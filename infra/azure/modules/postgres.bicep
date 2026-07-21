// Dedicated production server (plan §17): the existing public burstable
// B1ms instances are not reused. Private access only, HA in prod.

param environment string
param location string
param delegatedSubnetId string
param privateDnsZoneId string

@secure()
param administratorPassword string = newGuid()

resource server 'Microsoft.DBforPostgreSQL/flexibleServers@2023-12-01-preview' = {
  name: 'psql-buddy-${environment}'
  location: location
  sku: {
    name: environment == 'prod' ? 'Standard_D2ds_v5' : 'Standard_B2s'
    tier: environment == 'prod' ? 'GeneralPurpose' : 'Burstable'
  }
  properties: {
    version: '16'
    administratorLogin: 'buddy_admin'
    administratorLoginPassword: administratorPassword
    storage: {
      storageSizeGB: environment == 'prod' ? 128 : 32
    }
    backup: {
      backupRetentionDays: environment == 'prod' ? 35 : 7
      geoRedundantBackup: environment == 'prod' ? 'Enabled' : 'Disabled'
    }
    highAvailability: {
      mode: environment == 'prod' ? 'ZoneRedundant' : 'Disabled'
    }
    network: {
      delegatedSubnetResourceId: delegatedSubnetId
      privateDnsZoneArmResourceId: privateDnsZoneId
    }
  }
}

resource database 'Microsoft.DBforPostgreSQL/flexibleServers/databases@2023-12-01-preview' = {
  parent: server
  name: 'buddy'
}

output fqdn string = server.properties.fullyQualifiedDomainName
