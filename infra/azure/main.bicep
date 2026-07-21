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

@description('Immutable commit-SHA image tag for Buddy images')
param buddyImageTag string

@description('Immutable commit-SHA image tag for the memory hub image')
param hubImageTag string

@description('Qdrant Managed Cloud endpoint (external lifecycle)')
param qdrantEndpoint string

@secure()
@description('Key Vault secret URI for the Qdrant API key')
param qdrantApiKeySecretUri string

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
  }
}

module redis 'modules/redis.bicep' = {
  name: 'redis'
  params: {
    environment: environment
    location: location
    privateEndpointSubnetId: network.outputs.privateEndpointSubnetId
  }
}

module containerAppsEnvironment 'modules/container-apps-environment.bicep' = {
  name: 'container-apps-environment'
  params: {
    environment: environment
    location: location
    infrastructureSubnetId: network.outputs.containerAppsSubnetId
    logAnalyticsWorkspaceId: observability.outputs.logAnalyticsWorkspaceId
  }
}

module memoryHub 'modules/memory-hub.bicep' = {
  name: 'memory-hub'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: hubImageTag
    keyVaultName: keyVault.outputs.name
    qdrantEndpoint: qdrantEndpoint
    qdrantApiKeySecretUri: qdrantApiKeySecretUri
  }
}

module buddyApi 'modules/buddy-api.bicep' = {
  name: 'buddy-api'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultName: keyVault.outputs.name
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redis.outputs.hostName
    memoryHubInternalUrl: memoryHub.outputs.internalUrl
  }
}

module buddyWorker 'modules/buddy-worker.bicep' = {
  name: 'buddy-worker'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultName: keyVault.outputs.name
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redis.outputs.hostName
    memoryHubInternalUrl: memoryHub.outputs.internalUrl
  }
}

module jobs 'modules/jobs.bicep' = {
  name: 'jobs'
  params: {
    environment: environment
    location: location
    containerAppsEnvironmentId: containerAppsEnvironment.outputs.environmentId
    acrLoginServer: acr.outputs.loginServer
    imageTag: buddyImageTag
    keyVaultName: keyVault.outputs.name
    postgresFqdn: postgres.outputs.fqdn
    redisHostName: redis.outputs.hostName
  }
}

output apiUrl string = buddyApi.outputs.apiUrl
