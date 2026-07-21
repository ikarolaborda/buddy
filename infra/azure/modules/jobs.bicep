// One-shot and scheduled Container Apps Jobs: expand/contract database
// migrations (never suppress failures), outbox repair, memory curation,
// and CIL evaluation cycles.

param environment string
param location string
param containerAppsEnvironmentId string
param acrLoginServer string
param imageTag string
param keyVaultName string
param postgresFqdn string
param redisHostName string

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-jobs-${environment}'
  location: location
}

var commonEnv = [
  { name: 'APP_ENV', value: 'production' }
  { name: 'DB_CONNECTION', value: 'pgsql' }
  { name: 'DB_HOST', value: postgresFqdn }
  { name: 'DB_DATABASE', value: 'buddy' }
  { name: 'QUEUE_CONNECTION', value: 'redis' }
  { name: 'REDIS_HOST', value: redisHostName }
  { name: 'KEY_VAULT_NAME', value: keyVaultName }
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

resource outboxRepairJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-outbox-${environment}'
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${identity.id}': {}
    }
  }
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
