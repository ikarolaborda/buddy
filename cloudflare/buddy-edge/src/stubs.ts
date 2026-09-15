import type { EdgeEnv } from "./env";
import type { BuddyEdgeBudget } from "./do/edge-budget";
import type { BuddyTaskProgress } from "./do/task-progress";

export const BUDGET_OBJECT_NAME = "budget";

export function budgetStub(env: EdgeEnv): DurableObjectStub<BuddyEdgeBudget> {
  const namespace = env.BUDDY_EDGE_BUDGET as DurableObjectNamespace<BuddyEdgeBudget>;
  return namespace.get(namespace.idFromName(BUDGET_OBJECT_NAME));
}

export function progressObjectName(clientId: string, taskId: string): string {
  return `${clientId}:${taskId}`;
}

export function progressStub(env: EdgeEnv, clientId: string, taskId: string): DurableObjectStub<BuddyTaskProgress> {
  const namespace = env.BUDDY_TASK_PROGRESS as DurableObjectNamespace<BuddyTaskProgress>;
  return namespace.get(namespace.idFromName(progressObjectName(clientId, taskId)));
}
