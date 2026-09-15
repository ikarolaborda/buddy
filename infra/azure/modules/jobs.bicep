// One-shot and scheduled Container Apps Jobs: expand/contract database
// migrations (never suppress failures), outbox repair, memory curation,
// and CIL evaluation cycles.

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
param deployOutboxRepair bool = true

// Cloudflare edge wiring (2026-09-15 plan). The outbox relay publishes remote
// deliveries and the cleanup job touches R2, so both jobs carry the same edge
// configuration as the worker. Provisioned secrets are optional until created.
param edgeEventsQueueId string = ''
param edgeArtifactsQueueId string = ''
param edgeArtifactsBucket string = 'buddy-artifacts-preview'
param edgeR2Endpoint string = ''
param deployEdgeSecrets bool = false

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'
var acrPull = '7f951dda-4ed3-4680-a7ca-43fe172d538d'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-jobs-${environment}'
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

var commonEnv = [
  { name: 'APP_ENV', value: 'production' }
  { name: 'APP_KEY', secretRef: 'app-key' }
  { name: 'LOG_CHANNEL', value: 'stderr' }
  { name: 'DB_CONNECTION', value: 'pgsql' }
  { name: 'DB_HOST', value: postgresFqdn }
  { name: 'DB_PORT', value: '5432' }
  { name: 'DB_DATABASE', value: 'buddy' }
  { name: 'DB_USERNAME', value: 'buddy_admin' }
  { name: 'DB_PASSWORD', secretRef: 'db-password' }
  { name: 'QUEUE_CONNECTION', value: 'redis' }
  { name: 'REDIS_CLIENT', value: 'phpredis' }
  { name: 'REDIS_HOST', value: redisUseTls ? 'tls://${redisHostName}' : redisHostName }
  { name: 'REDIS_PORT', value: redisPort }
  { name: 'REDIS_PASSWORD', secretRef: 'redis-password' }
  { name: 'BUDDY_API_KEY_PEPPER', secretRef: 'api-pepper' }
  { name: 'BUDDY_EDGE_EVENTS', value: 'false' }
  { name: 'BUDDY_EDGE_PROGRESS', value: 'false' }
  { name: 'BUDDY_EDGE_ARTIFACTS', value: 'false' }
  { name: 'BUDDY_EDGE_EVENTS_QUEUE_ID', value: edgeEventsQueueId }
  { name: 'BUDDY_EDGE_ARTIFACTS_QUEUE_ID', value: edgeArtifactsQueueId }
  { name: 'BUDDY_R2_BUCKET', value: edgeArtifactsBucket }
  { name: 'BUDDY_R2_ENDPOINT', value: edgeR2Endpoint }
]

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

var edgeJobSecrets = [
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
]

resource migrationJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-migrate-${environment}'
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
    environmentId: containerAppsEnvironmentId
    configuration: {
      triggerType: 'Manual'
      replicaTimeout: 600
      replicaRetryLimit: 0
      manualTriggerConfig: {
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
      secrets: [
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
      ]
    }
    template: {
      containers: [
        {
          name: 'migrate'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'migrate', '--force']
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: commonEnv
        }
      ]
    }
  }
}

resource outboxRepairJob 'Microsoft.App/jobs@2024-03-01' = if (deployOutboxRepair) {
  name: 'caj-buddy-outbox-${environment}'
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
    environmentId: containerAppsEnvironmentId
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 300
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: '*/5 * * * *'
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
      secrets: concat(edgeJobSecrets, edgeProvisionedSecrets)
    }
    template: {
      containers: [
        {
          name: 'outbox-relay'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'buddy:outbox-relay', '--once']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: concat(commonEnv, edgeProvisionedEnv)
        }
      ]
    }
  }
}

// Daily artifact lifecycle (plan §9): expire abandoned reservations, release
// quota, and tombstone plus purge objects past retention. Safe to repeat and
// inert while BUDDY_EDGE_ARTIFACTS is false.
resource artifactsCleanupJob 'Microsoft.App/jobs@2024-03-01' = if (deployOutboxRepair) {
  name: 'caj-buddy-artifacts-${environment}'
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
    environmentId: containerAppsEnvironmentId
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 600
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: '15 3 * * *'
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
      secrets: concat(edgeJobSecrets, edgeProvisionedSecrets)
    }
    template: {
      containers: [
        {
          name: 'artifacts-cleanup'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'buddy:artifacts:cleanup']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: concat(commonEnv, edgeProvisionedEnv)
        }
      ]
    }
  }
}


resource queueHealthJob 'Microsoft.App/jobs@2024-03-01' = if (deployOutboxRepair) {
  name: 'caj-buddy-queue-health-${environment}'
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
    environmentId: containerAppsEnvironmentId
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 120
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: '*/15 * * * *'
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: acrLoginServer
          identity: identity.id
        }
      ]
      secrets: concat(edgeJobSecrets, edgeProvisionedSecrets)
    }
    template: {
      containers: [
        {
          name: 'queue-health'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'buddy:queue:health']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: concat(commonEnv, edgeProvisionedEnv)
        }
      ]
    }
  }
}

output principalId string = identity.properties.principalId
