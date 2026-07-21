param environment string
param location string

resource registry 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: 'acrbuddy${environment}${uniqueString(resourceGroup().id)}'
  location: location
  sku: {
    name: 'Basic'
  }
  properties: {
    adminUserEnabled: false
  }
}

output loginServer string = registry.properties.loginServer
output registryId string = registry.id
