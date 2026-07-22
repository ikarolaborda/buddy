// WARNING: do not run a full main.bicep deployment from this file against
// the live environment. Image tags below are CI placeholders; a full
// redeploy would replace running revisions with nonexistent images.
// Targeted module deployments (e.g. modules/alerts.bicep) are the safe
// path for incremental changes. See docs/adr/0007.
using '../main.bicep'

param environment = 'dev'
param location = 'northeurope'
param buddyImageTag = 'set-by-ci-commit-sha'
param hubImageTag = 'set-by-ci-commit-sha'
// This repo is public: the concrete Qdrant Cloud hostname stays out of
// git. Export QDRANT_HOST before a deployment; the live value is on the
// ca-memory-hub-dev container app (az containerapp show) and in Key Vault.
param qdrantHost = readEnvironmentVariable('QDRANT_HOST', '')
param qdrantPort = '6334'
param qdrantApiKeySecretUri = 'https://kv-buddy-dev.vault.azure.net/secrets/qdrant-api-key'
param postgresAdminPassword = readEnvironmentVariable('POSTGRES_ADMIN_PASSWORD', '')
param alertEmailAddress = 'iclaborda@msn.com'
param monthlyBudgetAmount = 100
