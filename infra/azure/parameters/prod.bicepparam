// WARNING: do not run a full main.bicep deployment from this file against
// a live environment. Image tags are CI placeholders and qdrantHost is a
// placeholder until a prod Qdrant cluster exists. Prod promotion criteria
// live in docs/adr/0007.
using '../main.bicep'

param environment = 'prod'
param location = 'northeurope'
param buddyImageTag = 'set-by-ci-commit-sha'
param hubImageTag = 'set-by-ci-commit-sha'
param qdrantHost = 'REPLACE.cloud.qdrant.io'
param qdrantPort = '6334'
param qdrantApiKeySecretUri = 'https://kv-buddy-prod.vault.azure.net/secrets/qdrant-api-key'
param postgresAdminPassword = readEnvironmentVariable('POSTGRES_ADMIN_PASSWORD', '')
param alertEmailAddress = 'iclaborda@msn.com'
param monthlyBudgetAmount = 200
