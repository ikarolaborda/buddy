// One-shot PostgreSQL import job for a cross-subscription Buddy migration.
// The target flexible server is private-access only, so the restore runs in
// the target Container Apps environment and reads a verified custom-format
// dump from its Azure Files share. The job is Manual: deploying it never
// changes the target database on its own.

param environment string
param location string = resourceGroup().location
param containerAppsEnvironmentId string
param postgresFqdn string
param keyVaultName string
param keyVaultUri string
param importFileName string = 'migration-export/buddy-credit.dump'

var keyVaultSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-buddy-import-${environment}'
  location: location
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' existing = {
  name: keyVaultName
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

resource importJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-import-${environment}'
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${identity.id}': {}
    }
  }
  dependsOn: [vaultRole]
  properties: {
    environmentId: containerAppsEnvironmentId
    configuration: {
      triggerType: 'Manual'
      replicaTimeout: 1800
      replicaRetryLimit: 0
      manualTriggerConfig: {
        parallelism: 1
        replicaCompletionCount: 1
      }
      secrets: [
        {
          name: 'db-password'
          keyVaultUrl: '${keyVaultUri}secrets/pg-admin-password'
          identity: identity.id
        }
      ]
    }
    template: {
      containers: [
        {
          name: 'postgres-import'
          image: 'docker.io/library/postgres:16'
          command: ['/bin/sh', '-c']
          args: [
            'set -eu; test -s "$IMPORT_FILE"; pg_restore --host "$PGHOST" --port 5432 --username buddy_admin --dbname buddy --clean --if-exists --no-owner --no-acl --exit-on-error "$IMPORT_FILE"; echo "Buddy restore completed"'
          ]
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: [
            { name: 'PGHOST', value: postgresFqdn }
            { name: 'PGSSLMODE', value: 'require' }
            { name: 'PGPASSWORD', secretRef: 'db-password' }
            { name: 'IMPORT_FILE', value: '/hub-data/${importFileName}' }
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
}

output jobName string = importJob.name
