// Azure Managed Redis (not Azure Cache for Redis, which is on a
// retirement path). Non-clustered initially until Laravel queue/lock
// compatibility against clustered mode is proven (plan §9).
// Requires the Microsoft.Cache provider to be registered — an explicit,
// approval-gated provisioning step.

param environment string
param location string
param privateEndpointSubnetId string

resource redis 'Microsoft.Cache/redisEnterprise@2025-07-01' = {
  name: 'redis-buddy-${environment}'
  location: location
  sku: {
    name: environment == 'prod' ? 'Balanced_B1' : 'Balanced_B0'
  }
  properties: {
    // B0 rejects Disabled at create time; private endpoint + disabled PNA
    // is applied as a prod hardening step after creation.
    publicNetworkAccess: environment == 'prod' ? 'Disabled' : 'Enabled'
  }
}

// Persistence stays off in dev: Redis is never the source of truth here —
// the PostgreSQL outbox and task leases recover queued work (plan §9).
resource redisDatabase 'Microsoft.Cache/redisEnterprise/databases@2025-07-01' = {
  parent: redis
  name: 'default'
  properties: {
    clientProtocol: 'Encrypted'
    evictionPolicy: 'NoEviction'
    clusteringPolicy: 'EnterpriseCluster'
    persistence: environment == 'prod' ? {
      aofEnabled: false
      rdbEnabled: true
      rdbFrequency: '1h'
    } : null
  }
}

resource privateEndpoint 'Microsoft.Network/privateEndpoints@2023-11-01' = if (environment == 'prod') {
  name: 'pe-redis-buddy-${environment}'
  location: location
  properties: {
    subnet: {
      id: privateEndpointSubnetId
    }
    privateLinkServiceConnections: [
      {
        name: 'redis'
        properties: {
          privateLinkServiceId: redis.id
          groupIds: ['redisEnterprise']
        }
      }
    ]
  }
}

output hostName string = redis.properties.hostName
