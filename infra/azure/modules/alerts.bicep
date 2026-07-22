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

var apiAppName = 'ca-buddy-api-${environment}'
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
