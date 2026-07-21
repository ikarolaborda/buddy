// Dev-only Redis: an internal TCP Container App standing in for Azure
// Managed Redis, which this subscription cannot provision (Redis
// Enterprise quota is zero in both candidate regions — raise with Azure
// support for prod). Volatility is acceptable here by design: the
// PostgreSQL outbox and task leases recover queued work (plan §9).

param environment string
param location string
param containerAppsEnvironmentId string
param keyVaultUri string

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-redis-${environment}'
  location: location
}

resource redisApp 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'ca-redis-${environment}'
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
        targetPort: 6379
        exposedPort: 6379
        transport: 'tcp'
      }
      secrets: [
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
        maxReplicas: 1
      }
      containers: [
        {
          name: 'redis'
          image: 'docker.io/library/redis:7-alpine'
          command: ['/bin/sh', '-c']
          args: ['exec redis-server --requirepass "$REDIS_PASSWORD" --maxmemory 256mb --maxmemory-policy noeviction']
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: [
            { name: 'REDIS_PASSWORD', secretRef: 'redis-password' }
          ]
        }
      ]
    }
  }
}

output hostName string = redisApp.properties.configuration.ingress.fqdn
output principalId string = identity.properties.principalId
