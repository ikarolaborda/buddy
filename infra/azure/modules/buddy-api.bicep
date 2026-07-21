// Stateless Buddy API app: enqueue-only for long evaluations, scales on
// HTTP concurrency, min 1 replica in production.

param environment string
param location string
param containerAppsEnvironmentId string
param acrLoginServer string
param imageTag string
param keyVaultName string
param postgresFqdn string
param redisHostName string
param memoryHubInternalUrl string

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-api-${environment}'
  location: location
}

resource api 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'ca-buddy-api-${environment}'
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
        external: true
        targetPort: 8080
        transport: 'http'
      }
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
    }
    template: {
      scale: {
        minReplicas: environment == 'prod' ? 1 : 0
        maxReplicas: 5
        rules: [
          {
            name: 'http-concurrency'
            http: {
              metadata: {
                concurrentRequests: '40'
              }
            }
          }
        ]
      }
      containers: [
        {
          name: 'buddy-api'
          image: '${acrLoginServer}/buddy:${imageTag}'
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: [
            { name: 'APP_ENV', value: 'production' }
            { name: 'DB_CONNECTION', value: 'pgsql' }
            { name: 'DB_HOST', value: postgresFqdn }
            { name: 'DB_DATABASE', value: 'buddy' }
            { name: 'QUEUE_CONNECTION', value: 'redis' }
            { name: 'CACHE_STORE', value: 'redis' }
            { name: 'REDIS_HOST', value: redisHostName }
            { name: 'REDIS_CLIENT', value: 'phpredis' }
            { name: 'BUDDY_MEMORY_BACKEND', value: 'hub' }
            { name: 'BUDDY_MEMORY_HUB_URL', value: memoryHubInternalUrl }
            { name: 'BUDDY_API_AUTH', value: 'true' }
            { name: 'KEY_VAULT_NAME', value: keyVaultName }
          ]
          probes: [
            {
              type: 'Readiness'
              httpGet: {
                path: '/api/ready'
                port: 8080
              }
              periodSeconds: 10
            }
            {
              type: 'Liveness'
              httpGet: {
                path: '/api/health'
                port: 8080
              }
              periodSeconds: 30
            }
          ]
        }
      ]
    }
  }
}

output apiUrl string = 'https://${api.properties.configuration.ingress.fqdn}'
output principalId string = identity.properties.principalId
