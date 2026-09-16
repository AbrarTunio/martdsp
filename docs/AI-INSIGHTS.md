# SuperMart — AI Insights

Every major page gets an **"Explain this page"** button. It returns a short, plain-language read of
what the numbers on that page actually mean for the business, what looks wrong, and what to do
about it — in English or Urdu.

---

## 1. My recommendation on how to feed the AI

You asked for data to be *"fetched from the database directly as structured data."* That is the
right instinct, with one important refinement:

> **Send pre-computed metrics, not raw rows.**

Three reasons this matters, and they are not stylistic:

1. **Accuracy.** Language models are unreliable at arithmetic over hundreds of rows. If the model
   adds up your sales, some numbers will be wrong — and a wrong number in a business report is
   worse than no report. If the app computes every figure in SQL and the model only *explains*
   them, the numbers are always exact.
2. **Cost.** 5,000 sale rows is roughly 200,000 tokens per click. The same information as a metric
   summary is about 2,000 tokens — a 100× difference on every single button press.
3. **Privacy.** A metric pack contains totals and rankings. It does not need to ship customer
   phone numbers or full transaction histories to a third-party API.

So the pipeline is:

```
  SQL aggregates          Threshold rules            LLM
 ┌───────────────┐      ┌────────────────┐      ┌──────────────┐
 │ MetricPack    │─────▶│ Findings[]     │─────▶│ Narrative    │
 │ (exact facts) │      │ (what's wrong) │      │ (what to do) │
 └───────────────┘      └────────────────┘      └──────────────┘
        deterministic, always runs              optional, needs API key
```

The practical consequence: **the insights page still works with no API key and no internet.** The
rules layer produces the findings and severity on its own; the AI adds the explanation, the
prioritisation and the suggested action. If the key is missing, the provider is down, or the
monthly budget is spent, the panel degrades to the rule-based version instead of erroring. For a
shop that depends on this software daily, that fallback is not optional.

The model also never sees a number it could contradict — it is explicitly instructed that the
figures in the metric pack are authoritative and must be quoted, not recomputed.

---

## 2. Provider configuration

**Settings → AI** gives the flow you described:

1. **Choose provider** — Claude (Anthropic), OpenAI, Google Gemini, Groq, Z.ai, Ollama Cloud, or
   *Custom (OpenAI-compatible)* with a base URL.
2. **Paste API key** — stored `encrypted` at rest via Laravel's cast, write-only in the UI
   (shows `sk-…abc1`), never sent to the browser.
3. **Fetch models** — calls the provider's model-list endpoint and populates a dropdown, so the
   user picks from what their key can actually reach instead of typing a model ID.
4. **Test** — sends a tiny fixed prompt, shows the reply, the latency and the token count.
5. **Save** — plus a per-month spend cap and an output-language toggle (English / اردو).

### Driver design — 3 classes cover all 7 options

```php
interface AiDriver {
    public function listModels(): array;                    // for the dropdown
    public function complete(AiRequest $r): AiResponse;      // structured JSON out
    public function test(): TestResult;                      // latency + echo
}
```

| Driver | Covers | Endpoint | Structured output mechanism |
|---|---|---|---|
| `AnthropicDriver` | Claude | official `anthropic-ai/sdk` | `outputConfig: ['format' => …]` |
| `OpenAiCompatibleDriver` | OpenAI, Groq, Z.ai, Ollama Cloud, Custom | `/v1/chat/completions`, `/v1/models` | `response_format: json_schema` |
| `GeminiDriver` | Google Gemini | `/v1beta/models/{m}:generateContent` | `responseMimeType` + `responseSchema` |

Groq, Z.ai and Ollama Cloud all speak the OpenAI wire format, so they are configuration rows
against one driver, not three codebases. Adding a future provider is usually just a base URL.

Anthropic specifics (from the official PHP SDK): model `claude-opus-5`, adaptive thinking omitted
for these short calls, `output_config.effort: "low"` since narration is not a reasoning-heavy task,
and the long static system prompt carries `cacheControl: ephemeral` so repeat clicks re-use the
cached prefix.

### Safety rails

- All calls are **server-side only**; the key never reaches Alpine.
- Timeout 20s, 1 retry, then fall back to rules.
- Response is parsed against the JSON schema; a malformed reply falls back rather than rendering.
- Results cached in `ai_insight_runs` keyed by `page + scope_hash` (date range, filters) for 6
  hours, with a manual **Refresh** button.
- Every run records tokens, estimated cost and latency, so Settings can show *"Rs. 240 used this
  month of your Rs. 2,000 cap."*
- Prompt states that metric-pack figures are authoritative and must not be recalculated.

---

## 3. The insight catalogue

This is the business layer — the part you asked me to own. Each entry is a metric the app computes
in SQL, a threshold that decides whether it becomes a finding, and the retail reasoning behind it.

Benchmarks below are general grocery/FMCG retail norms and are stored as **editable settings**, not
hard-coded, because a neighbourhood kiryana and a large mart behave differently.

### 3.1 Inventory health — *Products, Stock pages*

| Metric | Formula | Flag when | Why it matters |
|---|---|---|---|
| **Dead stock** | no sale in 90d, `stock_qty_base > 0` | value > Rs. 5,000 | Cash sitting on a shelf. The single most common hidden loss in a small mart. |
| **Overstock** | days of supply > 90 | any A/B item | Over-ordering ties up working capital and raises expiry risk. |
| **Stockout on movers** | `stock = 0` but sold in last 30d | any | Estimated lost sale = avg daily units × days out × price. Turns an invisible loss into a number. |
| **Stock turn** | annualised COGS ÷ avg inventory at cost | < 4× /yr | Grocery should run 12–20×. Under 4 means the shelf is a warehouse. |
| **GMROI** | gross margin Rs ÷ avg inventory cost | < 2.0 | *Return on the money in stock.* The best single measure of whether a category earns its shelf space. Above 3.0 is healthy. |
| **ABC / Pareto** | rank by revenue: A = top 80%, B = next 15%, C = last 5% | — | A-items must never stock out; C-items are delisting candidates. Usually ~20% of SKUs are A. |
| **Below reorder point** | `stock ≤ avg daily × lead time × 1.5` | any | Drives the purchase suggestion list. |
| **Expiry exposure** | value of batches expiring ≤ 30/60d | any | Discount now or lose it entirely. Only for batch-tracked items. |
| **Negative margin** | `sale_price < avg_cost` | any | Usually a data-entry slip or a cost rise nobody passed on. Silent money leak. |
| **Supplier price creep** | unit cost trend over last 3 purchases | > 5% rise | Catch it before it eats the margin unnoticed. |

### 3.2 Sales & basket — *POS, Sales, Dashboard*

| Metric | Formula | Reading |
|---|---|---|
| **Average basket value (ATV)** | revenue ÷ transactions | Falling ATV with flat footfall = mix shifting cheaper |
| **Units per transaction (UPT)** | units ÷ transactions | The lever for upsell and placement |
| **Peak-hour heatmap** | sales by hour × weekday | Staffing and restocking schedule |
| **Basket affinity** | lift = P(A∩B) ÷ (P(A)·P(B)), over `sale_items` pairs | lift > 1.5 = genuine pairing. Drives adjacency and bundle offers. |
| **Margin mix shift** | revenue flat but margin % down | Sales moving to low-margin lines — the quiet profit killer |
| **Category contribution** | revenue share vs margin share | High-revenue/low-margin categories need a pricing review |
| **Discount leakage** | discount Rs by cashier and by product | Outlier cashiers, or one product routinely discounted |
| **Growth** | WoW / MoM, vs same weekday last month | Weekday-matched comparison avoids false alarms |

### 3.3 Khata / credit risk — *Customers*

This is where a small mart most often loses real money, so it gets the most attention.

| Metric | Formula | Flag when |
|---|---|---|
| **Aging buckets** | outstanding by 0–30 / 31–60 / 61–90 / 90+ days | anything in 90+ |
| **Over credit limit** | `balance > credit_limit` | any |
| **Debt growing faster than payment** | 90d debit total ÷ 90d credit total | ratio > 1.3 |
| **Days since last payment** | today − last `payment` entry | > 45 days |
| **Concentration risk** | top 5 debtors ÷ total receivables | > 50% — a few defaults would hurt badly |
| **Receivables vs cash** | total khata ÷ monthly revenue | > 25% — too much of the business is on credit |
| **Lapsed regular (RFM)** | was ≥ 4 visits/month, now 0 for 45d | any with decent lifetime value |

The lapsed-customer one is the rare *positive* insight: *"Ahmed Khan used to shop weekly and has
Rs. 43,000 of lifetime purchases, but hasn't come in 52 days."* That is an actionable phone call,
not just a warning.

### 3.4 Cash & drawer integrity — *Drawer, Reports*

| Metric | Flag when | Why |
|---|---|---|
| **Variance by cashier over time** | mean variance consistently negative | One-off shorts are human error; a persistent pattern is not |
| **Variance size** | \|variance\| > Rs. 500 or > 1% of cash sales | Needs a written reason and manager sign-off |
| **Void / refund rate by cashier** | > 2× the store average | Classic till-fraud signal, worth knowing about |
| **Safe drops** | frequency, amount, time of day | Verifies the drawer was actually emptied as claimed |
| **Sessions closed without counting** | any | The control only works if the count happens |
| **Long gap between float and first sale** | > 60 min | Register opened but idle |

### 3.5 Cross-cutting — *Dashboard*

A single **business health** narrative combining: today vs yesterday vs same weekday last week;
gross margin trend; cash position; top 3 risks ranked by rupee impact; and the one action with the
largest expected return. This is the page a busy owner actually reads.

---

## 4. Output contract

Every provider is asked for the same JSON shape, so the Blade panel is provider-agnostic:

```json
{
  "headline": "Your margin is healthy, but Rs. 84,000 is stuck in dead stock",
  "summary": "2–3 sentences in plain language.",
  "insights": [
    {
      "title": "23 products haven't sold in 3 months",
      "severity": "high",
      "explanation": "Why this is happening, referencing the exact figures given.",
      "action": "One concrete thing to do this week.",
      "impact": "Frees roughly Rs. 84,000 of working capital."
    }
  ],
  "watch_next": ["Short forward-looking prompts"]
}
```

Severity is decided by the **rules layer**, not the model — so the colour of the badge is
deterministic and cannot drift between runs or providers.

---

## 5. Prompt shape

- **System prompt (cached, static):** the role — a retail analyst advising a small supermarket
  owner in Pakistan; currency PKR; write for someone with no business-analytics training; short
  sentences; every claim must cite a figure from the data; never invent numbers; never recompute;
  say plainly when data is insufficient rather than guessing.
- **User message (per request):** the metric pack + findings as compact JSON, plus the page name
  and date range.
- **Output:** constrained to the schema above.

Roughly 1,500–2,500 input tokens and 400–700 output tokens per click — around Rs. 3–8 per insight
on Claude Opus 5, and effectively free on Groq or a local Ollama model. The spend cap in Settings
makes the ceiling explicit either way.
