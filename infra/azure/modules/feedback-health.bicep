// Daily feedback-health check + degradation alert (roadmap follow-up: the
// LangSmith feedback alert is plan-gated, so the signal is computed from
// Buddy's own tables and alerted through the existing action group).
// Deploy standalone (never via full main.bicep):
//   az deployment group create -g rg-buddy-<env> -f modules/feedback-health.bicep \
//     -p environment=<env> containerAppsEnvironmentId=<id> acrLoginServer=<acr> \
//        imageTag=<tag> keyVaultUri=<uri> postgresFqdn=<fqdn> redisHost=<host> \
//        logAnalyticsWorkspaceId=<id> actionGroupId=<id>

param environment string
param location string = resourceGroup().location
param containerAppsEnvironmentId string
param acrLoginServer string
param imageTag string
param keyVaultUri string
param postgresFqdn string
param redisHost string
param redisPort string = '6379'
param cronExpression string = '30 6 * * *'
param logAnalyticsWorkspaceId string
param actionGroupId string

// Reuses the jobs identity: it already holds ACR pull and Key Vault access.
resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' existing = {
  name: 'id-buddy-jobs-${environment}'
}

resource healthJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-feedback-health-${environment}'
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
        cronExpression: cronExpression
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
          name: 'feedback-health'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'buddy:feedback-health']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: [
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
            { name: 'REDIS_HOST', value: redisHost }
            { name: 'REDIS_PORT', value: redisPort }
            { name: 'REDIS_PASSWORD', secretRef: 'redis-password' }
            { name: 'BUDDY_API_KEY_PEPPER', secretRef: 'api-pepper' }
          ]
        }
      ]
    }
  }
}

resource degradationAlert 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = {
  name: 'alert-buddy-feedback-degraded-${environment}'
  location: location
  properties: {
    displayName: 'Buddy feedback degradation (${environment})'
    description: 'buddy:feedback-health reported BUDDY_FEEDBACK_DEGRADED within the last day.'
    severity: 2
    enabled: true
    evaluationFrequency: 'PT1H'
    windowSize: 'P1D'
    scopes: [logAnalyticsWorkspaceId]
    autoMitigate: true
    criteria: {
      allOf: [
        {
          query: 'ContainerAppConsoleLogs_CL | where Log_s contains "BUDDY_FEEDBACK_DEGRADED"'
          timeAggregation: 'Count'
          operator: 'GreaterThan'
          threshold: 0
        }
      ]
    }
    actions: {
      actionGroups: [actionGroupId]
    }
  }
}

output jobName string = healthJob.name
output alertName string = degradationAlert.name
