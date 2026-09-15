/**
 * POST /internal/events: Azure publishes one event envelope through the Worker instead of the Queues REST API,
 * so it needs only the shared edge key. The envelope is validated and budgeted here, then queued unchanged;
 * the consumer applies the same validation again before it touches a projection.
 */
import type { EdgeEnv } from "./env";
import { isArtifactEnvelope, isTerminalEnvelope, validateEnvelope } from "./envelope";
import { errorJson, json, readJsonBody } from "./http";
import { budgetStub } from "./stubs";

/** Queue messages are capped at 128 KiB; a larger body can never be delivered, so it is rejected before parsing. */
const MAX_EVENT_BYTES = 128 * 1024;

export async function handleEventIngest(request: Request, env: EdgeEnv): Promise<Response> {
  const body = await readJsonBody<unknown>(request, MAX_EVENT_BYTES);
  if (body === null) return errorJson(422, "invalid_envelope", { reason: "body_not_json_object" });
  const validation = validateEnvelope(body);
  if (!validation.ok) return errorJson(422, "invalid_envelope", { reason: validation.reason });
  const envelope = validation.envelope;

  let allowed = true;
  try {
    allowed = (await budgetStub(env).increment("events")).allowed;
  } catch (error) {
    console.warn(`[buddy-edge] budget unavailable, queueing event anyway: ${String(error)}`);
  }
  // Terminal events keep an operating reserve, matching the consumer.
  if (!allowed && !isTerminalEnvelope(envelope)) return errorJson(429, "budget_exhausted");

  const queue = isArtifactEnvelope(envelope) ? env.BUDDY_ARTIFACT_EVENTS : env.BUDDY_EVENTS;
  try {
    await queue.send(envelope, { contentType: "json" });
  } catch (error) {
    console.error(`[buddy-edge] queue send failed for ${envelope.event_id}: ${String(error)}`);
    return errorJson(503, "queue_unavailable");
  }
  return json({ queued: true, event_id: envelope.event_id }, 202);
}
