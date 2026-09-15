# AI models usable under the startup credits (Cloudflare and Azure)

Checked on 2026-09-15 against vendor documentation and the live accounts.
Dates on the documents are the vendors' own. Re-read billing before relying on
a balance; neither CLI exposes the credit balance for these programs.

## Azure (Microsoft for Startups credits, subscription `e7e7a0f4-2689-47ed-b7e0-ce68d8394cc4`)

**Rule.** Credits pay for services billed by Microsoft. Marketplace purchases
are excluded: the Marketplace FAQ (updated 2026-07-23) states "prepaid or
sponsored Azure spend can't be used to buy partner solutions from Microsoft
Marketplace." The Foundry "models from partners and community" page (dated
2026-09-01) lists the subscription types that cannot use those models:
"Azure subscriptions that don't have an active pay-as-you-go billing method
(for example, student, free trial, or startup credit-based accounts)" and
"Sponsored subscriptions that only use Azure credits. Note: If you have an
account with a credit card on file, the credit card will be charged instead of
Azure Credits." The EUR 843.25 Marketplace charge on invoice G183325414 is that
rule in action.

**Credit-eligible: Foundry Models sold by Azure** (page dated 2026-09-04),
billed first-party and therefore consumable with credits:

| Provider | Models sold by Azure (identifiers as documented) |
| --- | --- |
| Azure OpenAI | every Azure OpenAI model: GPT-6 (`gpt-6-astra`), GPT-5.6 (`gpt-5.6-sol`, `gpt-5.6-luna`, `gpt-5.6-terra`), GPT-5.5, 5.4, 5.3, 5.2, 5.1, 5 (chat, codex, mini, nano, pro variants), `gpt-chat-latest`, GPT-4.1 series, GPT-4o, o-series, `gpt-oss-20b`/`gpt-oss-120b`, embeddings (`text-embedding-3-small`/large), image, audio, realtime and video models |
| DeepSeek | `DeepSeek-V3.2`, `DeepSeek-V3.2-Speciale`, `DeepSeek-V4-Flash`, `DeepSeek-V4-Flash-0731`, `DeepSeek-V4-Pro` |
| Moonshot AI | `Kimi-K2.5`, `Kimi-K2.6`, `Kimi-K2.7-Code` |
| xAI | `grok-4`, `grok-4-20-reasoning`/`non-reasoning`, `grok-4.1-fast-reasoning`/`non-reasoning`, `grok-4.3`, `grok-4.6`, `grok-code-fast-1` |
| Mistral AI | `Mistral-Large-3`, `mistral-medium-3-5`, `mistral-ocr-4-0`, `mistral-document-ai-2512` |
| Meta | `Llama-3.3-70B-Instruct`, `Llama-4-Maverick-17B-128E-Instruct-FP8` |
| Microsoft | `MAI-Thinking-1`, `MAI-Image-2.5`/`2.6` families, Phi models sold by Azure |
| Cohere | `Cohere-command-a`, `Cohere-command-a-plus-05-2026`, `Cohere-parse-v5`, `Cohere-rerank-v4.0-fast`/`pro` |
| Black Forest Labs | `FLUX-1.1-pro`, `FLUX.1-Kontext-pro`, `FLUX.2-flex`, `FLUX.2-pro` |
| Alibaba | `Qwen-32B` |

**Not credit-eligible (Marketplace, "partners and community"):** every
Anthropic Claude model in Foundry (`claude-fable-5-1`, `claude-fable-5`,
`claude-mythos-*`, `claude-opus-5`, `claude-opus-4-8`/`4-7`/`4-6`/`4-5`,
`claude-sonnet-5`/`4-6`/`4-5`, `claude-haiku-4-5`), whether "Hosted on Azure"
or on Anthropic infrastructure; Cohere embed v3; Meta `Llama-4-Scout` (partner
listing); Mistral `Codestral-2501`, `Ministral-3B`, `Mistral-small-2503`,
`Mistral-medium-2505`, `Mistral-large` (partner listings) and the hub-only
Mistral/Mixtral models; Phi partner listings; NTT `tsuzumi-7b`. Any SaaS
resource (`Microsoft.SaaS/resources`) falls in this group.

**What the account allows today.** The subscription policy
`startup-credit-ai-only` denies every `Microsoft.SaaS` resource and every
Cognitive Services deployment outside its allow-list, which is currently
`gpt-6-astra,2026-09-03`, `gpt-5.5,2026-04-24`, `text-embedding-3-small,1`.
Deployed: `aerolambda-ai-eastus2` runs `gpt-6-astra` (GlobalStandard, 100),
`gpt-5.5` (GlobalStandard, 200), `text-embedding-3-small` (Standard, 120);
`aerolambda-ai-eu` (swedencentral) runs `text-embedding-3-small` (DataZone).
The allow-list is narrower than credit eligibility on purpose; widening it is
a policy parameter change (`az policy assignment update ... --params`), not a
billing question. Candidates that would add independent model families to the
Buddy council while staying on credits: `gpt-5.6-terra` or `gpt-5.6-sol`,
`gpt-oss-120b`, `DeepSeek-V4-Pro`, `Kimi-K2.7-Code`, `grok-4.6`,
`Mistral-Large-3`, `MAI-Thinking-1`, subject to quota in the deployment region.
Quota already granted in eastus2 (TPM, `az cognitiveservices usage list`):
`gpt-5.6-sol`/`luna`/`terra` 1,000 GlobalStandard each (333 DataZone),
`gpt-oss-120b` 5,000, `grok-4-fast-*` 1,000, `DeepSeek-V3.1`/`R1` 1,000,
`Kimi-K2-Thinking` 100; catalog entries exist for `DeepSeek-V4-Pro`,
`Kimi-K2.7-Code`, `grok-4.6`, `Mistral-Large-3`, `MAI-Thinking-1` in both
eastus2 and swedencentral as GlobalStandard. Data residency caveat: partner
models sold by Azure are Global-only here, so they are not a destination for
EU-resident clinical data.

## Cloudflare (Cloudflare for Startups, account `63cc5315181fb5f7fbf59dac3efcf76e`)

**Rule** (cloudflare.com/startups, read 2026-09-15). Tier 3 credits ($10,000,
"valid for one year or until fully consumed", no extension; this grant expires
2027-08-11) cover Workers, Workers for Platforms, Durable Objects, Workflows,
Workers AI (capped at $2,500 on Tier 3), Workers KV, D1, Queues, Vectorize, R2
(capped at $10,000), Pages, Images and Stream, Cache Reserve, Argo. Excluded:
AI Gateway ("temporarily not covered by credits"), Registrar, Enterprise
add-ons, premium network services.

**Workers AI models usable on credits** (Tier 3 cap $2,500; $0.011 per 1,000
neurons beyond the 10,000 free neurons per day). Text-generation models on the
account on 2026-09-15 (31): `@cf/openai/gpt-oss-120b`, `@cf/openai/gpt-oss-20b`,
`@cf/nvidia/nemotron-3-120b-a12b`, `@cf/deepseek-ai/deepseek-v4-pro-0813`,
`@cf/deepseek-ai/deepseek-v4-flash-0731`, `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b`,
`@cf/moonshotai/kimi-k2.7-code`, `@cf/moonshotai/kimi-k2.6`, `@cf/zai-org/glm-5.3`,
`@cf/zai-org/glm-5.3-flash`, `@cf/zai-org/glm-5.2`, `@cf/zai-org/glm-4.7-flash`,
`@cf/meta/llama-4-scout-17b-16e-instruct`, `@cf/meta/llama-3.3-70b-instruct-fp8-fast`,
`@cf/meta/llama-3.2-11b-vision-instruct`, `@cf/meta/llama-3.2-3b-instruct`,
`@cf/meta/llama-3.2-1b-instruct`, `@cf/meta/llama-3.1-8b-instruct-fp8`,
`@cf/meta/llama-guard-3-8b`, `@cf/mistralai/mistral-small-3.1-24b-instruct`,
`@cf/mistral/mistral-7b-instruct-v0.2-lora`, `@cf/qwen/qwen3.8-27b`,
`@cf/qwen/qwen3-30b-a3b-fp8`, `@cf/qwen/qwq-32b`, `@cf/qwen/qwen2.5-coder-32b-instruct`,
`@cf/google/gemma-4-26b-a4b-it`, `@cf/google/gemma-7b-it-lora`, `@cf/google/gemma-2b-it-lora`,
`@cf/aisingapore/gemma-sea-lion-v4-27b-it`, `@cf/ibm-granite/granite-4.0-h-micro`,
`@cf/meta-llama/llama-2-7b-chat-hf-lora`. Published per-token prices (examples):
Llama 3.3 70B $0.293/$2.253 per M in/out, DeepSeek V4 Pro $1.32/$3.96,
Kimi K2.7 Code $0.95/$4.00, Mistral Small 24B $0.351/$0.555, GLM 4.7 Flash
$0.06/$0.40. Buddy's `workers_ai` council profile (Sol flag) therefore runs on
credits; it stays off for quality and latency reasons recorded in ADR 0009
notes, not for billing reasons.

**Not on credits:** AI Gateway routes (Fable 5.1 and other paid gateway
models), and Browser Run, which is absent from the published coverage list and
whose pricing page (read 2026-09-15) contains no credit mention. Browser Run is
"available on Free and Paid plans": the Workers Paid plan includes 10 browser
hours per month and 10 concurrent browsers, then $0.09 per additional hour.
Buddy's diagnostic quota (10 captures per client per day, 30 seconds each,
2 concurrent) is about 2.5 hours per month, inside the included allowance, so
the live pilot costs nothing extra as long as the Worker budget counters hold.

## Credentials observed

- `buddy/.env` `CLOUDFLARE_API_TOKEN` and the Key Vault `buddy-cloudflare-workers-ai`
  token both answer `Invalid API Token` from this workstation; the Key Vault one
  is expected to work only from Azure's egress IP. Wrangler holds an OAuth login
  for the account, which provisioned the edge resources.
- No Cloudflare API token is stored on Azure for the edge: Azure reaches
  Cloudflare only through the Worker with the shared service key.
