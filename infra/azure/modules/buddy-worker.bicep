// No-ingress worker app scaling on Redis queue depth, fixed bounded
// processes per replica. Graceful shutdown must exceed the longest
// admitted job (plan §8.3/§8.4).

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

// The KEDA redis scaler measures a raw Redis list, so it must name the key
// Laravel actually writes: config('database.redis.options.prefix') followed by
// 'queues:'.<queue>. Neither APP_NAME nor REDIS_PREFIX nor REDIS_QUEUE is set on
// the worker, so the prefix is Str::slug('Laravel').'-database-' and the queue is
// 'default'. Measured inside the production worker on 2026-09-15 (P0 of the
// Cloudflare plan): prefix laravel-database-, queue default, db 0. The previous
// value 'buddy:queue:default' never existed, so the scaler always read 0 and
// maxReplicas was decorative. tests/Unit/RedisQueueScaleRuleTest.php pins the
// derivation so a future APP_NAME/REDIS_PREFIX change fails CI unless this
// default changes with it.
param redisQueueListName string = 'laravel-database-queues:default'

// Address the SCALER dials, which is not necessarily the address the worker
// dials. KEDA runs in the environment's system namespace; the short app name
// resolves there to the app's cluster service IP, which refused every dial for
// 14 days (1,469 KEDAScalerFailed events, 2026-09-01..15) while app pods using
// the identical name connected fine. Leave empty to use host:port; set it after
// a probe app proves a reachable address (docs/recipes/redis-autoscaling-repair.md).
param redisScaleAddress string = ''

// 'redis' keeps the direct list measurement. 'metrics-api' polls the Buddy API's
// authenticated queue-depth endpoint over the public ingress instead, which is
// the path the scaler can actually reach (ADR 0012). The endpoint reports the
// same list, so the threshold semantics do not change.
@allowed(['redis', 'metrics-api'])
param workerScaleRuleType string = 'redis'
param scalingMetricsUrl string = ''

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

var scaleAddress = empty(redisScaleAddress) ? '${redisHostName}:${redisPort}' : redisScaleAddress

var redisScaleRule = {
  name: 'redis-queue-depth'
  custom: {
    type: 'redis'
    metadata: {
      address: scaleAddress
      listName: redisQueueListName
      listLength: '10'
      enableTLS: redisUseTls ? 'true' : 'false'
    }
    auth: [
      {
        secretRef: 'redis-password'
        triggerParameter: 'password'
      }
    ]
  }
}

var metricsApiScaleRule = {
  name: 'queue-depth-api'
  custom: {
    type: 'metrics-api'
    metadata: {
      url: scalingMetricsUrl
      valueLocation: 'pending'
      targetValue: '10'
      authMode: 'apiKey'
      method: 'header'
      keyParamName: 'X-Buddy-Scaling-Key'
    }
    auth: [
      {
        secretRef: 'scaling-metrics-key'
        triggerParameter: 'apiKey'
      }
    ]
  }
}

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'
var acrPull = '7f951dda-4ed3-4680-a7ca-43fe172d538d'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-worker-${environment}'
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

resource worker 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'ca-buddy-worker-${environment}'
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
          name: 'openrouter-api-key'
          keyVaultUrl: '${keyVaultUri}secrets/openrouter-api-key'
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
          name: 'edge-delegation-secret'
          keyVaultUrl: '${keyVaultUri}secrets/buddy-edge-delegation-secret'
          identity: identity.id
        }
      ], edgeProvisionedSecrets)
    }
    template: {
      scale: {
        minReplicas: 1
        maxReplicas: 4
        rules: [
          workerScaleRuleType == 'metrics-api' ? metricsApiScaleRule : redisScaleRule
        ]
      }
      containers: [
        {
          name: 'buddy-worker'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'queue:work', 'redis', '--timeout=1860', '--tries=3', '--max-jobs=500']
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: concat([
            { name: 'APP_ENV', value: 'production' }
            { name: 'CONTAINER_ROLE', value: 'worker' }
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
            // Must exceed the council job timeout (900s) or Redis redelivers
            // a live council mid-deliberation (ADR 0009 timing chain).
            { name: 'REDIS_QUEUE_RETRY_AFTER', value: '2400' }
            { name: 'OPENROUTER_API_KEY', secretRef: 'openrouter-api-key' }
            { name: 'BUDDY_EVALUATOR_PROVIDER', value: 'azure' }
            { name: 'BUDDY_REFINER_PROVIDER', value: 'azure' }
            { name: 'BUDDY_MODEL', value: 'gpt-6-astra' }
            { name: 'BUDDY_MODEL_ROUTING', value: 'false' }
            { name: 'AZURE_OPENAI_API_KEY', secretRef: 'azure-openai-key' }
            { name: 'AZURE_OPENAI_URL', value: azureOpenAiUrl }
            { name: 'AZURE_OPENAI_API_VERSION', value: '2024-10-21' }
            { name: 'AZURE_OPENAI_DEPLOYMENT', value: azureOpenAiDeployment }
            { name: 'BUDDY_MEMORY_BACKEND', value: 'hub' }
            { name: 'BUDDY_MEMORY_HUB_URL', value: memoryHubInternalUrl }
            { name: 'BUDDY_MEMORY_HUB_TOKEN', secretRef: 'hub-token' }
            { name: 'LANGSMITH_SEND_PROMPTS', value: 'true' }
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
            { name: 'BUDDY_EDGE_DELEGATION_SECRET', secretRef: 'edge-delegation-secret' }
            { name: 'BUDDY_EDGE_WORKER_URL', value: edgeWorkerUrl }
            { name: 'BUDDY_EDGE_ALLOWED_ORIGINS', value: edgeAllowedOrigins }
            { name: 'BUDDY_EDGE_EVENTS_QUEUE_ID', value: edgeEventsQueueId }
            { name: 'BUDDY_EDGE_ARTIFACTS_QUEUE_ID', value: edgeArtifactsQueueId }
            { name: 'BUDDY_R2_BUCKET', value: edgeArtifactsBucket }
            { name: 'BUDDY_R2_ENDPOINT', value: edgeR2Endpoint }
          ], edgeProvisionedEnv)
        }
      ]
    }
  }
}

output principalId string = identity.properties.principalId
