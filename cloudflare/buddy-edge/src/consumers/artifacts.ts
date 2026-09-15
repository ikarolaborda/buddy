import type { EdgeEnv } from "../env";
import { consumeBatch, type ConsumerSummary } from "./events";

/**
 * Artifact events (`buddy.task.artifact.*`, `buddy.task.export.completed.v1`) share the per-task sequence and land in
 * the same projection. They ride an isolated queue so a parser backlog cannot delay progress delivery.
 */
export function consumeArtifactsBatch(batch: MessageBatch<unknown>, env: EdgeEnv): Promise<ConsumerSummary> {
  return consumeBatch(batch, env, "artifacts");
}
