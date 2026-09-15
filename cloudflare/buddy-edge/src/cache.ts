import { type ArtifactSummaryResponse, type AzureClient, type AzureResult } from "./azure";
import { flagEnabled, type EdgeEnv } from "./env";
import { budgetStub } from "./stubs";

export const SUMMARY_CACHE_TTL_SECONDS = 3600;

export function summaryCacheKey(clientId: string, artifactId: string, contentHash: string, processorVersion: string): string {
  return `summary:v1:${clientId}:${artifactId}:${contentHash}:${processorVersion}`;
}

export interface SummaryLookup {
  task_id: string;
  artifact_id: string;
  client_id: string;
  session_token: string;
}

export type SummaryOutcome =
  | { ok: true; source: "origin" | "cache"; summary: ArtifactSummaryResponse }
  | { ok: false; status: number; error: string };

/**
 * Authorization always comes first: with the cache on, the metadata-only origin call both authorizes the read and
 * yields the immutable key parts. Only then is KV consulted. With the flag off every read goes to origin.
 */
export async function getArtifactSummary(env: EdgeEnv, azure: AzureClient, lookup: SummaryLookup): Promise<SummaryOutcome> {
  if (!flagEnabled(env, "READ_CACHE_ENABLED")) {
    return fromOrigin(azure.artifactSummary(lookup.task_id, lookup.artifact_id, lookup.session_token, false));
  }

  const metadata = await azure.artifactSummary(lookup.task_id, lookup.artifact_id, lookup.session_token, true);
  if (!metadata.ok) return { ok: false, status: metadata.status, error: metadata.error };
  const { content_hash, processor_version } = metadata.data;
  if (!content_hash || !processor_version) {
    return fromOrigin(azure.artifactSummary(lookup.task_id, lookup.artifact_id, lookup.session_token, false));
  }

  const key = summaryCacheKey(lookup.client_id, lookup.artifact_id, content_hash, processor_version);
  const budget = budgetStub(env);
  const cached = await env.BUDDY_READ_CACHE.get(key, "json").catch(() => null);
  if (cached && typeof cached === "object") {
    await budget.increment("cache_hits").catch(() => undefined);
    return { ok: true, source: "cache", summary: cached as ArtifactSummaryResponse };
  }

  await budget.increment("cache_misses").catch(() => undefined);
  const full = await azure.artifactSummary(lookup.task_id, lookup.artifact_id, lookup.session_token, false);
  if (!full.ok) return { ok: false, status: full.status, error: full.error };
  try {
    await env.BUDDY_READ_CACHE.put(key, JSON.stringify(full.data), { expirationTtl: SUMMARY_CACHE_TTL_SECONDS });
  } catch (error) {
    console.warn(`[buddy-edge] summary cache write failed: ${String(error)}`);
  }
  return { ok: true, source: "origin", summary: full.data };
}

async function fromOrigin(pending: Promise<AzureResult<ArtifactSummaryResponse>>): Promise<SummaryOutcome> {
  const result = await pending;
  if (!result.ok) return { ok: false, status: result.status, error: result.error };
  return { ok: true, source: "origin", summary: result.data };
}
