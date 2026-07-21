// Go qdrant-memory hub (plan §11.3). Hard constraints:
// - exactly one replica (min = max = 1): per-content-ID locks and the
//   JSON registries are process-local; two replicas can race.
// - internal ingress only; the hub is never exposed publicly.
// - MiniLM embedding sidecar in the same replica with a readiness gate.
// - registries persisted on Azure Files; Qdrant data never on Azure Files.
// Deployments must drain/pause memory writes before switching revisions.
// Env contract verified against the hub source at 80b824c: QDRANT_HOST +
// QDRANT_PORT (gRPC), HUB_SIGNING_KEY required in http mode,
// EMBED_BACKEND defaults to nop so sidecar must be explicit.

param environment string
param location string
param containerAppsEnvironmentId string
param acrLoginServer string
param imageTag string
param qdrantHost string
param qdrantPort string = '6334'

@secure()
param qdrantApiKeySecretUri string

@secure()
param hubSigningKeySecretUri string

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-memory-hub-${environment}'
  location: location
}

resource hub 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'ca-memory-hub-${environment}'
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${identity.id}': {}
    }
  }
  properties: {
    managedEnvironmentId: containerAppsEnvironmentId
    configuration: {
      ingress: {
        external: false
        targetPort: 8200
        transport: 'http'
      }
      secrets: [
        {
          name: 'qdrant-api-key'
          keyVaultUrl: qdrantApiKeySecretUri
          identity: identity.id
        }
        {
          name: 'hub-signing-key'
          keyVaultUrl: hubSigningKeySecretUri
          identity: identity.id
        }
      ]
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
    }
    template: {
      scale: {
        minReplicas: 1
        maxReplicas: 1
      }
      containers: [
        {
          name: 'memory-hub'
          image: '${acrLoginServer}/qdrant-memory-hub:${imageTag}'
          command: ['/usr/local/bin/broker']
          args: ['--mode', 'http', '--http', '0.0.0.0:8200']
          resources: {
            cpu: json('1.0')
            memory: '2Gi'
          }
          env: [
            { name: 'HUB_TENANCY', value: 'multi' }
            { name: 'HUB_JOBS_FILE', value: '/app/data/jobs.json' }
            { name: 'HUB_SCHEDULES_FILE', value: '/app/data/schedules.json' }
            { name: 'HUB_REVOCATION_FILE', value: '/app/data/revocations.json' }
            { name: 'QDRANT_HOST', value: qdrantHost }
            { name: 'QDRANT_PORT', value: qdrantPort }
            { name: 'QDRANT_USE_TLS', value: 'true' }
            { name: 'QDRANT_API_KEY', secretRef: 'qdrant-api-key' }
            { name: 'HUB_SIGNING_KEY', secretRef: 'hub-signing-key' }
            { name: 'EMBED_BACKEND', value: 'sidecar' }
            { name: 'EMBED_URL', value: 'http://localhost:8000' }
          ]
          probes: [
            {
              type: 'Readiness'
              httpGet: {
                path: '/api/healthz'
                port: 8200
              }
              initialDelaySeconds: 15
              periodSeconds: 10
            }
            {
              type: 'Liveness'
              httpGet: {
                path: '/api/healthz'
                port: 8200
              }
              periodSeconds: 30
            }
          ]
          volumeMounts: [
            {
              volumeName: 'hub-data'
              mountPath: '/app/data'
            }
          ]
        }
        {
          name: 'minilm-sidecar'
          image: '${acrLoginServer}/minilm-embedding:${imageTag}'
          resources: {
            cpu: json('1.0')
            memory: '2Gi'
          }
        }
      ]
      volumes: [
        {
          name: 'hub-data'
          storageType: 'AzureFile'
          storageName: 'hub-data'
        }
      ]
    }
  }
}

output internalUrl string = 'https://${hub.properties.configuration.ingress.fqdn}'
output principalId string = identity.properties.principalId
