using '../main.bicep'

param environment = 'prod'
param location = 'northeurope'
param buddyImageTag = 'set-by-ci-commit-sha'
param hubImageTag = 'set-by-ci-commit-sha'
param qdrantEndpoint = 'https://REPLACE.cloud.qdrant.io:6334'
param qdrantApiKeySecretUri = 'https://kv-buddy-prod.vault.azure.net/secrets/qdrant-api-key'
