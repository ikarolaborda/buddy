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
          name: 'outbox-relay'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'buddy:outbox-relay', '--once']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: commonEnv
        }
      ]
    }
  }
}

output principalId string = identity.properties.principalId
