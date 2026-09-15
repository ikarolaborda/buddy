import { runInDurableObject } from "cloudflare:test";
import { beforeEach } from "vitest";
import type { BuddyEdgeBudget } from "../src/do/edge-budget";
import { budgetStubFor, env, nextTestIdentity } from "./helpers";

// This plugin version does not isolate storage per test (`reset()` leaves SQLite-backed Durable Objects and KV
// untouched). Isolation is therefore explicit: every test gets its own client id (and so its own projection
// Durable Object), the single budget object is emptied, and KV/R2 keys are removed.
beforeEach(async () => {
  nextTestIdentity();

  await runInDurableObject(budgetStubFor(), (_instance: BuddyEdgeBudget, state) => {
    for (const table of ["counters", "reconciliation", "gauges"]) {
      state.storage.sql.exec(`DELETE FROM ${table}`);
    }
  });

  const kv = await env.BUDDY_READ_CACHE.list();
  for (const key of kv.keys) await env.BUDDY_READ_CACHE.delete(key.name);

  const objects = await env.BUDDY_ARTIFACTS.list();
  for (const object of objects.objects) await env.BUDDY_ARTIFACTS.delete(object.key);
});
