// Read-only PostgreSQL export job for a cross-subscription Buddy migration.
// It runs inside the source Container Apps environment because the source
// flexible server is private-access only. The custom-format dump is written
// to the existing Azure Files share, from which an operator can transfer it
// to the target before any client cutover.

param environment string
param location string = resourceGroup().location
param containerAppsEnvironmentId string
param postgresFqdn string
param keyVaultUri string
param exportFileName string = 'migration-export/buddy-credit.dump'

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' existing = {
  name: 'id-buddy-jobs-${environment}'
}

resource exportJob 'Microsoft.App/jobs@2024-03-01' = {
  name: 'caj-buddy-export-${environment}'
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
          name: 'postgres-export'
          image: 'docker.io/library/postgres:16'
          command: ['/bin/sh', '-c']
          args: [
            'set -eu; mkdir -p "$(dirname "$EXPORT_FILE")"; pg_dump --host "$PGHOST" --port 5432 --username buddy_admin --dbname buddy --format=custom --file "$EXPORT_FILE" --no-owner --no-acl; test -s "$EXPORT_FILE"; ls -lh "$EXPORT_FILE"'
          ]
          resources: {
            cpu: json('0.5')
            memory: '1Gi'
          }
          env: [
            { name: 'PGHOST', value: postgresFqdn }
            { name: 'PGSSLMODE', value: 'require' }
            { name: 'PGPASSWORD', secretRef: 'db-password' }
            { name: 'EXPORT_FILE', value: '/hub-data/${exportFileName}' }
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

output jobName string = exportJob.name
