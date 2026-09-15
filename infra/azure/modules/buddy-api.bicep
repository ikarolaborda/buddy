// Stateless Buddy API app: enqueue-only for long evaluations, scales on
// HTTP concurrency. Min 1 replica in every environment: dev is the
// serving tier (ADR 0007) and scale-to-zero cold starts caused MCP
// timeouts for the agents it serves.

param environment string
param location string
param containerAppsEnvironmentId string
param acrLoginServer string
param acrName string
param imageTag string
param keyVaultUri string
param keyVaultName string
param postgresFqdn string
param redisHostName string
param redisPort string = '10000'
param redisUseTls bool = true
param memoryHubInternalUrl string
param azureOpenAiUrl string
param azureOpenAiDeployment string

// Cloudflare edge wiring (2026-09-15 plan). Identifiers only; the Queues token
// and R2 keys are Key Vault secrets referenced only when deployEdgeSecrets is
// true, so a revision never fails on a secret that has not been provisioned.
param edgeWorkerUrl string = ''
param edgeAllowedOrigins string = ''
param edgeEventsQueueId string = ''
param edgeArtifactsQueueId string = ''
param edgeArtifactsBucket string = 'buddy-artifacts-preview'
param edgeR2Endpoint string = ''
param deployEdgeSecrets bool = false

var edgeProvisionedSecrets = deployEdgeSecrets ? [
  {
    name: 'edge-queues-token'
    keyVaultUrl: '${keyVaultUri}secrets/buddy-cloudflare-queues'
    identity: identity.id
  }
  {
    name: 'r2-access-key-id'
    keyVaultUrl: '${keyVaultUri}secrets/buddy-r2-access-key-id'
    identity: identity.id
  }
  {
    name: 'r2-secret-access-key'
    keyVaultUrl: '${keyVaultUri}secrets/buddy-r2-secret-access-key'
    identity: identity.id
  }
] : []

var edgeProvisionedEnv = deployEdgeSecrets ? [
  { name: 'BUDDY_EDGE_QUEUES_TOKEN', secretRef: 'edge-queues-token' }
  { name: 'BUDDY_R2_ACCESS_KEY_ID', secretRef: 'r2-access-key-id' }
  { name: 'BUDDY_R2_SECRET_ACCESS_KEY', secretRef: 'r2-secret-access-key' }
] : []

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'
var acrPull = '7f951dda-4ed3-4680-a7ca-43fe172d538d'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-api-${environment}'
  location: location
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: keyVaultName
}

resource registry 'Microsoft.ContainerRegistry/registries@2023-07-01' existing = {
  name: acrName
}

resource vaultRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(vault.id, identity.id, keyVaultSecretsUser)
  scope: vault
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', keyVaultSecretsUser)
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

resource acrPullRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(registry.id, identity.id, acrPull)
  scope: registry
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', acrPull)
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
  }
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
  dependsOn: [
    vaultRole
    acrPullRole
  ]
  properties: {
    managedEnvironmentId: containerAppsEnvironmentId
    configuration: {
      activeRevisionsMode: 'Single'
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
      secrets: concat([
        {
          name: 'app-key'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-app-key'
          identity: identity.id
        }
        {
          name: 'db-password'
          keyVaultUrl: '${keyVaultUri}secrets/pg-admin-password'
          identity: identity.id
        }
        {
          name: 'api-pepper'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-api-pepper'
          identity: identity.id
        }
        {
          name: 'redis-password'
          keyVaultUrl: '${keyVaultUri}secrets/redis-access-key'
          identity: identity.id
        }
        {
          name: 'langsmith-api-key'
          keyVaultUrl: '${keyVaultUri}secrets/langsmith-api-key'
          identity: identity.id
        }
        {
          name: 'hub-token'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-hub-token'
          identity: identity.id
        }
        {
          name: 'azure-openai-key'
          keyVaultUrl: '${keyVaultUri}secrets/azure-openai-api-key'
          identity: identity.id
        }
        {
          name: 'scaling-metrics-key'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-scaling-metrics-key'
          identity: identity.id
        }
        {
          name: 'edge-service-key'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-edge-service-key'
          identity: identity.id
        }
        {
          name: 'edge-delegation'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-edge-delegation-secret'
          identity: identity.id
        }
      ], edgeProvisionedSecrets)
    }
    template: {
      scale: {
        minReplicas: 1
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
          env: concat([
            { name: 'APP_ENV', value: 'production' }
            { name: 'DB_CONNECTION', value: 'pgsql' }
            { name: 'DB_HOST', value: postgresFqdn }
            { name: 'DB_DATABASE', value: 'buddy' }
            { name: 'QUEUE_CONNECTION', value: 'redis' }
            { name: 'CACHE_STORE', value: 'redis' }
            { name: 'REDIS_HOST', value: redisUseTls ? 'tls://${redisHostName}' : redisHostName }
            { name: 'REDIS_CLIENT', value: 'phpredis' }
            { name: 'APP_KEY', secretRef: 'app-key' }
            { name: 'APP_DEBUG', value: 'false' }
            { name: 'LOG_CHANNEL', value: 'stderr' }
            { name: 'DB_PORT', value: '5432' }
            { name: 'DB_USERNAME', value: 'buddy_admin' }
            { name: 'DB_PASSWORD', secretRef: 'db-password' }
            { name: 'REDIS_PORT', value: redisPort }
            { name: 'REDIS_PASSWORD', secretRef: 'redis-password' }
            { name: 'BUDDY_API_KEY_PEPPER', secretRef: 'api-pepper' }
            { name: 'LANGSMITH_API_KEY', secretRef: 'langsmith-api-key' }
            { name: 'LANGSMITH_ENDPOINT', value: 'https://api.smith.langchain.com' }
            { name: 'LANGSMITH_PROJECT', value: 'buddy-${environment}' }
            { name: 'LANGSMITH_TRACING', value: 'true' }
            { name: 'BUDDY_MEMORY_BACKEND', value: 'hub' }
            { name: 'BUDDY_MEMORY_HUB_URL', value: memoryHubInternalUrl }
            { name: 'BUDDY_MEMORY_HUB_TOKEN', secretRef: 'hub-token' }
            { name: 'BUDDY_API_AUTH', value: 'true' }
            { name: 'BUDDY_EVALUATOR_PROVIDER', value: 'azure' }
            { name: 'BUDDY_REFINER_PROVIDER', value: 'azure' }
            { name: 'BUDDY_MODEL', value: 'gpt-6-astra' }
            { name: 'BUDDY_MODEL_ROUTING', value: 'false' }
            { name: 'AZURE_OPENAI_API_KEY', secretRef: 'azure-openai-key' }
            { name: 'AZURE_OPENAI_URL', value: azureOpenAiUrl }
            { name: 'AZURE_OPENAI_API_VERSION', value: '2024-10-21' }
            { name: 'AZURE_OPENAI_DEPLOYMENT', value: azureOpenAiDeployment }
            { name: 'LANGSMITH_SEND_PROMPTS', value: 'true' }
            { name: 'BUDDY_SCALING_METRICS_KEY', secretRef: 'scaling-metrics-key' }
            // Cloudflare edge (2026-09-15 plan). Every flag is an explicit false so
            // a revision diff shows exactly which feature a release enables.
            { name: 'BUDDY_EDGE_EVENTS', value: 'false' }
            { name: 'BUDDY_EDGE_PROGRESS', value: 'false' }
            { name: 'BUDDY_EDGE_SUPERVISION', value: 'false' }
            { name: 'BUDDY_EDGE_AUTO_RECOVERY', value: 'false' }
            { name: 'BUDDY_EDGE_ARTIFACTS', value: 'false' }
            { name: 'BUDDY_EDGE_READ_CACHE', value: 'false' }
            { name: 'BUDDY_EDGE_BROWSER_DIAGNOSTICS', value: 'false' }
            { name: 'BUDDY_EDGE_SERVICE_KEY', secretRef: 'edge-service-key' }
            { name: 'BUDDY_EDGE_DELEGATION_SECRET', secretRef: 'edge-delegation' }
            { name: 'BUDDY_EDGE_WORKER_URL', value: edgeWorkerUrl }
            { name: 'BUDDY_EDGE_ALLOWED_ORIGINS', value: edgeAllowedOrigins }
            { name: 'BUDDY_EDGE_EVENTS_QUEUE_ID', value: edgeEventsQueueId }
            { name: 'BUDDY_EDGE_ARTIFACTS_QUEUE_ID', value: edgeArtifactsQueueId }
            { name: 'BUDDY_R2_BUCKET', value: edgeArtifactsBucket }
            { name: 'BUDDY_R2_ENDPOINT', value: edgeR2Endpoint }
          ], edgeProvisionedEnv)
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
