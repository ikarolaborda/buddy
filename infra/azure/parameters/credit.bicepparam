// Isolated target for moving Buddy to the credited subscription. It uses the
// existing low-cost serving topology (in-environment Redis) while keeping
// globally named Key Vault and storage resources distinct from rg-buddy-dev.
using '../main.bicep'

param environment = 'credit'
param location = 'northeurope'
param deployWorkloads = false
param deployBackgroundWorkers = false
param buddyImageTag = readEnvironmentVariable('BUDDY_IMAGE_TAG', 'none')
param hubImageTag = readEnvironmentVariable('HUB_IMAGE_TAG', 'none')
param embeddingImageTag = readEnvironmentVariable('EMBEDDING_IMAGE_TAG', 'none')
param qdrantHost = '10.40.4.4'
param qdrantPort = '16334'
param qdrantRestPort = '16333'
param qdrantCaSecretUri = 'https://kv-buddy-credit.vault.azure.net/secrets/buddy-private-qdrant-ca'
param qdrantApiKeySecretUri = 'https://kv-buddy-credit.vault.azure.net/secrets/buddy-private-qdrant'
param hubSigningKeySecretUri = 'https://kv-buddy-credit.vault.azure.net/secrets/hub-signing-key'
param postgresAdminPassword = readEnvironmentVariable('POSTGRES_ADMIN_PASSWORD', '')
param alertEmailAddress = 'aerolambdahtech@outlook.com'
param monthlyBudgetAmount = 100
