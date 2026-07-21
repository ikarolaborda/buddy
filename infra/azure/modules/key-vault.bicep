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
    // Container Apps resolves Key Vault secret references from its control
    // plane, which is neither VNet-scoped nor on the trusted-services list.
    // Dev therefore stays network-open with RBAC as the guard; prod locks
    // the network and must use private endpoints + a supported reference
    // path before workloads deploy there.
    networkAcls: {
      defaultAction: environment == 'prod' ? 'Deny' : 'Allow'
      bypass: 'AzureServices'
    }
  }
}

output name string = vault.name
output vaultUri string = vault.properties.vaultUri
