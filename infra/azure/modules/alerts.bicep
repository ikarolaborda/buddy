// Operational alerting and cost guardrail (plan §13, Phase 4 gate; ADR 0007).
// Deliberately independent of the workload modules: container app resource
// IDs are reconstructed from their deterministic names so this module can be
// deployed standalone without a full main.bicep redeploy (which would
// require live image tags). Insights alert resources are global-scoped.
// Known gap, accepted for a single-operator dev tier: nothing fires on
// "app fully down, zero traffic" — 5xx needs requests, RestartCount needs
// restarts. RestartCount is cumulative per replica; Maximum aggregation
// with a small threshold avoids a permanently latched alert.

param environment string
param alertEmailAddress string
param monthlyBudgetAmount int = 100
param budgetStartDate string = utcNow('yyyy-MM-01')
// Log Analytics workspace for the scheduled-query alerts; empty skips them.
param logAnalyticsWorkspaceId string = ''
param workerMemoryPercentThreshold int = 85

var apiAppName = 'ca-buddy-api-${environment}'
var workerAppName = 'ca-buddy-worker-${environment}'
var monitoredApps = [
  apiAppName
  'ca-buddy-worker-${environment}'
  'ca-memory-hub-${environment}'
]

resource actionGroup 'Microsoft.Insights/actionGroups@2021-09-01' = {
  name: 'ag-buddy-${environment}'
  location: 'Global'
  properties: {
    groupShortName: 'buddy-${environment}'
    enabled: true
    emailReceivers: [
      {
        name: 'operator'
        emailAddress: alertEmailAddress
        useCommonAlertSchema: true
      }
    ]
  }
}

resource apiServerErrors 'Microsoft.Insights/metricAlerts@2018-03-01' = {
  name: 'alert-${apiAppName}-5xx'
  location: 'global'
  properties: {
    description: 'Buddy API is returning server errors'
    severity: 2
    enabled: true
    scopes: [
      resourceId('Microsoft.App/containerApps', apiAppName)
    ]
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    criteria: {
      'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
      allOf: [
        {
          criterionType: 'StaticThresholdCriterion'
          name: 'server-errors'
          metricName: 'Requests'
          dimensions: [
            {
              name: 'statusCodeCategory'
              operator: 'Include'
              values: ['5xx']
            }
          ]
          operator: 'GreaterThan'
          threshold: 10
          timeAggregation: 'Total'
        }
      ]
    }
    actions: [
      {
        actionGroupId: actionGroup.id
      }
    ]
  }
}

resource replicaRestarts 'Microsoft.Insights/metricAlerts@2018-03-01' = [
  for appName in monitoredApps: {
    name: 'alert-${appName}-restarts'
    location: 'global'
    properties: {
      description: 'Container app replicas are restarting'
      severity: 2
      enabled: true
      scopes: [
        resourceId('Microsoft.App/containerApps', appName)
      ]
      evaluationFrequency: 'PT5M'
      windowSize: 'PT30M'
      criteria: {
        'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
        allOf: [
          {
            criterionType: 'StaticThresholdCriterion'
            name: 'restarts'
            metricName: 'RestartCount'
            dimensions: []
            operator: 'GreaterThan'
            threshold: 3
            timeAggregation: 'Maximum'
          }
        ]
      }
      actions: [
        {
          actionGroupId: actionGroup.id
        }
      ]
    }
  }
]

resource budget 'Microsoft.Consumption/budgets@2025-04-01' = {
  name: 'budget-buddy-${environment}'
  properties: {
    timeGrain: 'Monthly'
    timePeriod: {
      startDate: budgetStartDate
    }
    category: 'Cost'
    amount: monthlyBudgetAmount
    notifications: {
      actual80: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 80
        thresholdType: 'Actual'
        contactEmails: [alertEmailAddress]
      }
      actual100: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 100
        thresholdType: 'Actual'
        contactEmails: [alertEmailAddress]
      }
      forecast100: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 100
        thresholdType: 'Forecasted'
        contactEmails: [alertEmailAddress]
      }
    }
  }
}

// Queue harness (ADR 0014). Memory uses the Maximum aggregation so one
// replica near its 2 GiB limit fires even when the others are idle.
resource workerMemory 'Microsoft.Insights/metricAlerts@2018-03-01' = {
  name: 'alert-${workerAppName}-memory'
  location: 'global'
  properties: {
    description: 'A Buddy worker replica is close to its memory limit'
    severity: 2
    enabled: true
    scopes: [
      resourceId('Microsoft.App/containerApps', workerAppName)
    ]
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    criteria: {
      'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
      allOf: [
        {
          criterionType: 'StaticThresholdCriterion'
          name: 'memory-percent'
          metricName: 'MemoryPercentage'
          dimensions: []
          operator: 'GreaterThan'
          threshold: workerMemoryPercentThreshold
          timeAggregation: 'Maximum'
        }
      ]
    }
    actions: [
      {
        actionGroupId: actionGroup.id
      }
    ]
  }
}

// The worker's KEDA rules poll the API; repeated failures mean the worker
// can no longer scale out (ADR 0012, ADR 0014). One failure per poll during a
// revision switch is expected, so the threshold tolerates a short window.
resource scalerFailures 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = if (logAnalyticsWorkspaceId != '') {
  name: 'alert-${workerAppName}-scaler-failed'
  location: resourceGroup().location
  properties: {
    displayName: 'Buddy worker scaler failing (${environment})'
    description: 'KEDA could not read the queue-depth endpoint for the worker'
    severity: 2
    enabled: true
    evaluationFrequency: 'PT15M'
    windowSize: 'PT15M'
    scopes: [logAnalyticsWorkspaceId]
    autoMitigate: true
    criteria: {
      allOf: [
        {
          query: 'ContainerAppSystemLogs_CL | where ContainerAppName_s == "${workerAppName}" and Reason_s == "KEDAScalerFailed"'
          timeAggregation: 'Count'
          operator: 'GreaterThan'
          threshold: 6
        }
      ]
    }
    actions: {
      actionGroups: [actionGroup.id]
    }
  }
}

// Lane-level signal: buddy:queue:health (caj-buddy-queue-health) runs every
// fifteen minutes and logs BUDDY_QUEUE_DEGRADED when a task has waited too
// long for a worker or evaluations keep failing.
resource queueDegraded 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = if (logAnalyticsWorkspaceId != '') {
  name: 'alert-buddy-queue-degraded-${environment}'
  location: resourceGroup().location
  properties: {
    displayName: 'Buddy queue degraded (${environment})'
    description: 'buddy:queue:health reported BUDDY_QUEUE_DEGRADED'
    severity: 2
    enabled: true
    evaluationFrequency: 'PT15M'
    windowSize: 'PT30M'
    scopes: [logAnalyticsWorkspaceId]
    autoMitigate: true
    criteria: {
      allOf: [
        {
          query: 'ContainerAppConsoleLogs_CL | where Log_s contains "BUDDY_QUEUE_DEGRADED"'
          timeAggregation: 'Count'
          operator: 'GreaterThan'
          threshold: 0
        }
      ]
    }
    actions: {
      actionGroups: [actionGroup.id]
    }
  }
}

output actionGroupId string = actionGroup.id
