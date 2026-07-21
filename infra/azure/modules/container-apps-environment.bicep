param environment string
param location string
param infrastructureSubnetId string
param logAnalyticsWorkspaceId string

resource env 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: 'cae-buddy-${environment}'
  location: location
  properties: {
    vnetConfiguration: {
      infrastructureSubnetId: infrastructureSubnetId
      internal: false
    }
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: reference(logAnalyticsWorkspaceId, '2022-10-01').customerId
        sharedKey: listKeys(logAnalyticsWorkspaceId, '2022-10-01').primarySharedKey
      }
    }
    workloadProfiles: [
      {
        name: 'Consumption'
        workloadProfileType: 'Consumption'
      }
    ]
  }
}

// Azure Files share for the hub's small JSON registries only
// (jobs/schedules/revocations). Qdrant data never lives here.
resource hubStorage 'Microsoft.App/managedEnvironments/storages@2024-03-01' = {
  parent: env
  name: 'hub-data'
  properties: {
    azureFile: {
      accountName: hubStorageAccount.name
      shareName: 'hub-data'
      accessMode: 'ReadWrite'
      accountKey: hubStorageAccount.listKeys().keys[0].value
    }
  }
}

resource hubStorageAccount 'Microsoft.Storage/storageAccounts@2023-04-01' = {
  name: 'stbuddyhub${environment}'
  location: location
  sku: {
    name: 'Standard_ZRS'
  }
  kind: 'StorageV2'
  properties: {
    allowBlobPublicAccess: false
    minimumTlsVersion: 'TLS1_2'
  }
}

resource hubFileShare 'Microsoft.Storage/storageAccounts/fileServices/shares@2023-04-01' = {
  name: '${hubStorageAccount.name}/default/hub-data'
}

output environmentId string = env.id
