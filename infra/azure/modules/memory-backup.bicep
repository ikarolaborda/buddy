// Nightly memory-plane backup job (roadmap Wave 2). Postgres has managed
// geo-redundant backups; the Qdrant Cloud corpus and the hub's JSON
// registries had none. Deploy standalone (never via full main.bicep):
//   az deployment group create -g rg-buddy-<env> \
//     -f modules/memory-backup.bicep \
//     -p environment=<env> containerAppsEnvironmentId=<id> \
//        qdrantRestUrl=https://<cluster-host>:6333 \
//        qdrantApiKeySecretUri=<kv-secret-uri> storageAccountName=<sa>

param environment string
param location string = resourceGroup().location
param containerAppsEnvironmentId string
param qdrantRestUrl string
param storageAccountName string
param cronExpression string = '0 3 * * *'
param retentionDays string = '14'

@secure()
param qdrantApiKeySecretUri string

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'
var storageBlobDataContributor = 'ba92f5b4-2d11-453d-a403-e96b0029c9fe'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-memory-backup-${environment}'
  location: location
}

resource storage 'Microsoft.Storage/storageAccounts@2023-01-01' existing = {
  name: storageAccountName
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: 'kv-buddy-${environment}'
}

resource blobRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(storage.id, identity.id, storageBlobDataContributor)
  scope: storage
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', storageBlobDataContributor)
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

resource vaultRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(vault.id, identity.id, keyVaultSecretsUser)
  scope: vault
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', keyVaultSecretsUser)
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

resource backupJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-memory-backup-${environment}'
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
      replicaTimeout: 1800
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: cronExpression
        parallelism: 1
        replicaCompletionCount: 1
      }
      secrets: [
        {
          name: 'qdrant-api-key'
          keyVaultUrl: qdrantApiKeySecretUri
          identity: identity.id
        }
      ]
    }
    template: {
      containers: [
        {
          name: 'memory-backup'
          image: 'mcr.microsoft.com/azure-cli:latest'
          command: ['/bin/bash', '-c', loadTextContent('../scripts/memory-backup.sh')]
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: [
            { name: 'QDRANT_URL', value: qdrantRestUrl }
            { name: 'QDRANT_API_KEY', secretRef: 'qdrant-api-key' }
            { name: 'STORAGE_ACCOUNT', value: storageAccountName }
            { name: 'RETENTION_DAYS', value: retentionDays }
            { name: 'AZURE_CLIENT_ID', value: identity.properties.clientId }
          ]
          volumeMounts: [
            {
              volumeName: 'hub-data'
              mountPath: '/hub-data'
            }
          ]
        }
      ]
      volumes: [
        {
          name: 'hub-data'
          storageType: 'AzureFile'
          storageName: 'hub-data'
        }
      ]
    }
  }
  dependsOn: [blobRole, vaultRole]
}

output jobName string = backupJob.name
output principalId string = identity.properties.principalId
