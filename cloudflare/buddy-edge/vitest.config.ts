import { defineConfig } from "vitest/config";
import { cloudflareTest } from "@cloudflare/vitest-plugin";

// Tests run inside the Workers runtime (workerd) through @cloudflare/vitest-plugin.
// Bindings, Durable Objects, Queues, KV, R2, and the Workflow come from wrangler.jsonc (`preview`).
// Every test is deterministic: Azure is stubbed with fetchMock, time is controlled where it matters,
// and nothing here reaches a real Cloudflare resource.
export default defineConfig({
  plugins: [
    cloudflareTest({
      wrangler: { configPath: "./wrangler.jsonc", environment: "preview" },
      miniflare: {
        // Test-only overrides. Keep the feature flags off by default to match production defaults;
        // individual tests enable a flag by constructing a derived env object.
        bindings: {
          EDGE_SERVICE_KEY: "test-edge-service-key",
        },
      },
    }),
  ],
  test: {
    include: ["test/**/*.test.ts"],
    setupFiles: ["./test/setup.ts"],
    testTimeout: 20_000,
    hookTimeout: 20_000,
  },
});
