import { consumeArtifactsBatch } from "./consumers/artifacts";
import { consumeBatch } from "./consumers/events";
import type { EdgeEnv } from "./env";
import { handleRequest } from "./router";

export { BuddyEdgeBudget } from "./do/edge-budget";
export { BuddyTaskProgress } from "./do/task-progress";
export { BuddyTaskSupervisor } from "./workflows/supervisor";

export default {
  fetch(request, env, ctx) {
    return handleRequest(request, env, ctx);
  },
  async queue(batch, env, _ctx) {
    if (batch.queue.includes("artifacts")) {
      await consumeArtifactsBatch(batch, env);
      return;
    }
    await consumeBatch(batch, env, "events");
  },
} satisfies ExportedHandler<EdgeEnv, unknown>;
