// Read-only row-count verification job for a private-access PostgreSQL
// server. Use the same query against source and target immediately after a
// migration; it emits only table names and counts to Container Apps logs.

param jobName string
param identityName string
param location string = resourceGroup().location
param containerAppsEnvironmentId string
param postgresFqdn string
param keyVaultUri string

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' existing = {
  name: identityName
}

resource verifyJob 'Microsoft.App/jobs@2024-03-01' = {
  name: jobName
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
      replicaTimeout: 300
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
          name: 'postgres-verify'
          image: 'docker.io/library/postgres:16'
          command: ['/bin/sh', '-c']
          args: [
            'set -eu; psql --host "$PGHOST" --port 5432 --username buddy_admin --dbname buddy --tuples-only --no-align --command "SELECT count(*) FROM api_clients; SELECT count(*) FROM api_keys; SELECT count(*) FROM buddy_tasks; SELECT count(*) FROM buddy_runs; SELECT count(*) FROM buddy_recommendations; SELECT count(*) FROM buddy_memory_references; SELECT count(*) FROM buddy_decision_logs; SELECT count(*) FROM outbox_messages;"'
          ]
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: [
            { name: 'PGHOST', value: postgresFqdn }
            { name: 'PGSSLMODE', value: 'require' }
            { name: 'PGPASSWORD', secretRef: 'db-password' }
          ]
        }
      ]
    }
  }
}

output jobName string = verifyJob.name
