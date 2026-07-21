// No-ingress worker app scaling on Redis queue depth, fixed bounded
// processes per replica. Graceful shutdown must exceed the longest
// admitted job (plan §8.3/§8.4).

param environment string
param location string
param containerAppsEnvironmentId string
param acrLoginServer string
param imageTag string
param keyVaultUri string
param postgresFqdn string
param redisHostName string
param redisPort string = '10000'
param redisUseTls bool = true
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
      scale: {
        minReplicas: 1
        maxReplicas: 4
        rules: [
          {
            name: 'redis-queue-depth'
            custom: {
              type: 'redis'
              metadata: {
                address: '${redisHostName}:${redisPort}'
                listName: 'buddy:queue:default'
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
            { name: 'REDIS_QUEUE_RETRY_AFTER', value: '240' }
            { name: 'BUDDY_MEMORY_BACKEND', value: 'hub' }
            { name: 'BUDDY_MEMORY_HUB_URL', value: memoryHubInternalUrl }
                      ]
        }
      ]
    }
  }
}

output principalId string = identity.properties.principalId
