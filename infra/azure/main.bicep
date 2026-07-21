// Buddy production topology (plan §11). Deployment requires explicit
// approval: registering Microsoft.Cache, creating network/DNS resources,
// and any change to existing PostgreSQL servers are gated operations.
// Qdrant Managed Cloud is provisioned via Azure Marketplace outside this
// template and supplied as an external endpoint/key reference.

targetScope = 'resourceGroup'

@description('Deployment environment')
@allowed(['dev', 'prod'])
param environment string

@description('Azure region for the whole data plane. North Europe only if every dependency SKU is available there; otherwise West Europe for all new resources.')
param location string

@description('Deploy the workload apps (hub, api, worker, jobs). False provisions core infrastructure only, so images and the Qdrant secret can be created first.')
param deployWorkloads bool = false

@description('Immutable commit-SHA image tag for Buddy images')
param buddyImageTag string = 'none'

@description('Immutable commit-SHA image tag for the memory hub image')
param hubImageTag string = 'none'

@description('Qdrant Managed Cloud gRPC hostname (external lifecycle)')
param qdrantHost string = ''

@description('Qdrant Managed Cloud gRPC port')
param qdrantPort string = '6334'

@secure()
@description('Key Vault secret URI for the Qdrant API key')
param qdrantApiKeySecretUri string = ''

@secure()
@description('Key Vault secret URI for the hub HTTP-mode signing key')
param hubSigningKeySecretUri string = ''

@secure()
@description('PostgreSQL administrator password; store it in Key Vault after deployment')
param postgresAdminPassword string

module network 'modules/network.bicep' = {
  name: 'network'
  params: {
    environment: environment
    location: location
  }
}

module observability 'modules/observability.bicep' = {
  name: 'observability'
  params: {
    environment: environment
    location: location
  }
}

module acr 'modules/acr.bicep' = {
  name: 'acr'
  params: {
    environment: environment
    location: location
  }
}

module keyVault 'modules/key-vault.bicep' = {
  name: 'key-vault'
  params: {
    environment: environment
    location: location
  }
}

module postgres 'modules/postgres.bicep' = {
  name: 'postgres'
  params: {
    environment: environment
    location: location
    delegatedSubnetId: network.outputs.postgresSubnetId
    privateDnsZoneId: network.outputs.postgresPrivateDnsZoneId
    administratorPassword: postgresAdminPassword
  }
}

// AMR is quota-blocked on this subscription (CreateFailed in both
// candidate regions); dev uses an in-environment Redis container until
// the quota is raised. Prod keeps the Managed Redis module.
module redis 'modules/redis.bicep' = if (environment == 'prod') {
  name: 'redis'
  params: {
    environment: environment
    location: location
    privateEndpointSubnetId: network.outputs.privateEndpointSubnetId
  }
}

module redisContainer 'modules/redis-container.bicep' = if (environment == 'dev') {
  name: 'redis-container'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    keyVaultUri: keyVault.outputs.vaultUri
  }
}

// In-environment TCP apps are reached via the short app name on the
// exposed port; the internal FQDN's HTTP-oriented ingress path does not
// carry raw TCP reliably (observed: connect timeouts from sibling apps).
var redisHost = environment == 'prod' ? redis.outputs.hostName : 'ca-redis-dev'
var redisPort = environment == 'prod' ? '10000' : '6379'
var redisTls = environment == 'prod'

module containerAppsEnvironment 'modules/container-apps-environment.bicep' = {
  name: 'container-apps-environment'
  params: {
    environment: environment
    location: location
    infrastructureSubnetId: network.outputs.containerAppsSubnetId
    logAnalyticsWorkspaceId: observability.outputs.logAnalyticsWorkspaceId
  }
}

module memoryHub 'modules/memory-hub.bicep' = if (deployWorkloads) {
  name: 'memory-hub'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: hubImageTag
    qdrantHost: qdrantHost
    qdrantPort: qdrantPort
    qdrantApiKeySecretUri: qdrantApiKeySecretUri
    hubSigningKeySecretUri: hubSigningKeySecretUri
  }
}

module buddyApi 'modules/buddy-api.bicep' = if (deployWorkloads) {
  name: 'buddy-api'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultUri: keyVault.outputs.vaultUri
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redisHost
    redisPort: redisPort
    redisUseTls: redisTls
    memoryHubInternalUrl: memoryHub.outputs.internalUrl
  }
}

module buddyWorker 'modules/buddy-worker.bicep' = if (deployWorkloads) {
  name: 'buddy-worker'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultUri: keyVault.outputs.vaultUri
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redisHost
    redisPort: redisPort
    redisUseTls: redisTls
    memoryHubInternalUrl: memoryHub.outputs.internalUrl
  }
}

module jobs 'modules/jobs.bicep' = if (deployWorkloads) {
  name: 'jobs'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultUri: keyVault.outputs.vaultUri
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redisHost
    redisPort: redisPort
    redisUseTls: redisTls
  }
}

output apiUrl string = deployWorkloads ? buddyApi.outputs.apiUrl : ''
