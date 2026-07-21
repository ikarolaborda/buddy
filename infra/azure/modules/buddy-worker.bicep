// No-ingress worker app scaling on Redis queue depth, fixed bounded
// processes per replica. Graceful shutdown must exceed the longest
// admitted job (plan §8.3/§8.4).

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
  name: 'id-buddy-worker-${environment}'
  location: location
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
  properties: {
    managedEnvironmentId: containerAppsEnvironmentId
    configuration: {
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
        maxReplicas: 4
        rules: [
          {
            name: 'redis-queue-depth'
            custom: {
              type: 'redis'
              metadata: {
                address: '${redisHostName}:10000'
                listName: 'buddy:queue:default'
                listLength: '10'
                enableTLS: 'true'
              }
            }
          }
        ]
      }
      containers: [
        {
          name: 'buddy-worker'
          image: '${acrLoginServer}/buddy:${imageTag}'
          command: ['php', 'artisan', 'queue:work', 'redis', '--timeout=210', '--tries=3', '--max-jobs=500']
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: [
            { name: 'APP_ENV', value: 'production' }
            { name: 'CONTAINER_ROLE', value: 'worker' }
            { name: 'DB_CONNECTION', value: 'pgsql' }
            { name: 'DB_HOST', value: postgresFqdn }
            { name: 'DB_DATABASE', value: 'buddy' }
            { name: 'QUEUE_CONNECTION', value: 'redis' }
            { name: 'CACHE_STORE', value: 'redis' }
            { name: 'REDIS_HOST', value: redisHostName }
            { name: 'REDIS_CLIENT', value: 'phpredis' }
            { name: 'REDIS_QUEUE_RETRY_AFTER', value: '240' }
            { name: 'BUDDY_MEMORY_BACKEND', value: 'hub' }
            { name: 'BUDDY_MEMORY_HUB_URL', value: memoryHubInternalUrl }
            { name: 'KEY_VAULT_NAME', value: keyVaultName }
          ]
        }
      ]
    }
  }
}

output principalId string = identity.properties.principalId
