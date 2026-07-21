param environment string
param location string

resource registry 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: 'acrbuddy${environment}'
  location: location
  sku: {
    name: 'Standard'
  }
  properties: {
    adminUserEnabled: false
    policies: {
      // Images are deployed by immutable commit-SHA tags; retention keeps
      // the registry bounded without breaking rollback windows.
      retentionPolicy: {
        status: 'enabled'
        days: 90
      }
    }
  }
}

output loginServer string = registry.properties.loginServer
output registryId string = registry.id
