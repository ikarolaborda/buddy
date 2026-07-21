param environment string
param location string

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: 'kv-buddy-${environment}'
  location: location
  properties: {
    sku: {
      family: 'A'
      name: 'standard'
    }
    tenantId: subscription().tenantId
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: 30
    enablePurgeProtection: true
    networkAcls: {
      defaultAction: 'Deny'
      bypass: 'AzureServices'
    }
  }
}

output name string = vault.name
output vaultUri string = vault.properties.vaultUri
