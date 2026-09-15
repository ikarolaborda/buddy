import { describe, expect, it } from "vitest";
import { utcDay } from "../src/do/edge-budget";
import { budgetStubFor } from "./helpers";

describe("BuddyEdgeBudget", () => {
  it("counts per UTC day, warns once at 80% and denies at 100%", async () => {
    const budget = budgetStubFor();
    const decisions = [];
    for (let i = 0; i < 12; i++) decisions.push(await budget.increment("captures"));

    expect(decisions.slice(0, 10).every((d) => d.allowed)).toBe(true);
    expect(decisions[7]).toMatchObject({ allowed: true, count: 8, limit: 10, ratio: 0.8, warning: true });
    expect(decisions.filter((d) => d.warning)).toHaveLength(1);
    expect(decisions[9]).toMatchObject({ allowed: true, count: 10, ratio: 1 });
    expect(decisions[10]).toMatchObject({ allowed: false, count: 10, limit: 10, ratio: 1, warning: false });
    expect(decisions[11]).toMatchObject({ allowed: false, count: 10 });

    const report = await budget.read();
    expect(report.counters.captures).toEqual({ count: 10, limit: 10, ratio: 1 });
    expect(report.counters.events).toEqual({ count: 0, limit: 50000, ratio: 0 });
  });

  it("keeps separate counters for different days", async () => {
    const budget = budgetStubFor();
    const today = Date.parse("2026-09-15T23:59:59Z");
    const tomorrow = Date.parse("2026-09-16T00:00:01Z");
    for (let i = 0; i < 10; i++) await budget.increment("captures", today);
    expect((await budget.increment("captures", today)).allowed).toBe(false);
    const next = await budget.increment("captures", tomorrow);
    expect(next).toMatchObject({ allowed: true, count: 1, day: "2026-09-16" });
    expect(utcDay(today)).toBe("2026-09-15");
  });

  it("counts but never limits informational kinds", async () => {
    const budget = budgetStubFor();
    const hit = await budget.increment("cache_hits");
    expect(hit).toMatchObject({ allowed: true, count: 1, limit: null, ratio: 0, warning: false });
    const sockets = await budget.increment("sockets");
    expect(sockets.limit).toBeNull();
  });

  it("stores reconciliation items and capture slots durably", async () => {
    const budget = budgetStubFor();
    const id = await budget.recordReconciliation({ kind: "workflow_create_failed", task_id: "t", generation: 1, detail: "x".repeat(600) });
    const items = await budget.listReconciliation();
    expect(items).toHaveLength(1);
    expect(items[0]!.id).toBe(id);
    expect(items[0]!.detail.length).toBe(500);
    expect((await budget.read()).reconciliation_pending).toBe(1);
    expect((await budget.read()).counters.reconcile_pending.count).toBe(1);
    expect(await budget.resolveReconciliation(id)).toBe(true);
    expect(await budget.resolveReconciliation(id)).toBe(false);
    expect(await budget.listReconciliation()).toEqual([]);

    expect(await budget.acquireCaptureSlot()).toEqual({ acquired: true, active: 1, limit: 2 });
    expect(await budget.acquireCaptureSlot()).toEqual({ acquired: true, active: 2, limit: 2 });
    expect(await budget.acquireCaptureSlot()).toEqual({ acquired: false, active: 2, limit: 2 });
    expect(await budget.releaseCaptureSlot()).toBe(1);
    expect(await budget.releaseCaptureSlot()).toBe(0);
    expect(await budget.releaseCaptureSlot()).toBe(0);
  });
});
