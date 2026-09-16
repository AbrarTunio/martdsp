# SuperMart — Database Design

**Stack:** Laravel 13 · MySQL 8.4 · Blade + Alpine · Tailwind v4 · Chart.js
**Profile:** Pakistan (PKR, GST) · Single store, multiple counters

---

## Design invariants

These five rules keep the whole system consistent. Every feature must respect them.

1. **All stock is stored in base units, as integers.** A product's base unit is its smallest sellable piece (one sachet, one piece, one gram). Cartons and boxes are *views* onto that number, never a separate stock pool. This eliminates the entire class of "half a carton" bugs.
2. **Stock only changes through `stock_movements`.** No controller ever writes `products.stock_qty_base` directly. A single `StockService` appends a signed movement and updates the cached balance in the same transaction.
3. **Money is stored as an integer number of paisa** (`bigint`), never a float and never a decimal column. Every column holding money is named with a `_paisa` suffix so the unit is impossible to misread. Quantities are `bigint` (base units), or `decimal(14,3)` where a weighted item needs grams.
4. **Ledgers are append-only.** Customer khata, supplier balances and drawer activity are never edited or deleted — a mistake is corrected with a reversing entry. This is what makes the audit trail trustworthy.
5. **Every sale line snapshots its cost** (`cost_at_sale_base`). Profit reports must not re-read today's cost to value last month's sale.

### Why paisa integers rather than `decimal(14,2)`

MySQL would compute `decimal` sums exactly, so the database side is not the problem. The POS is.
The cart runs in Alpine and totals lines **in the browser**, and JavaScript has no decimal type —
every subtotal, discount and GST line would pass through a float. Integer arithmetic is exact in
JavaScript far beyond any rupee total this shop will see, so the same numbers hold on the client,
on the server and in the database with no rounding discipline required anywhere.

The practical rules that follow from this:

- Percentage operations (GST, discounts) are the only place fractional paisa arise. They round
  half-up to the nearest paisa at the **line** level, and lines then sum exactly — so the printed
  receipt always adds up, which is what a customer actually checks.
- A “round off” line absorbs rounding to the nearest rupee where the shop wants it, recorded
  explicitly rather than hidden inside a total.
- `resources/js/money.js` and the `App\Support\Money` helper are the only places that convert
  between paisa and display rupees.

---

## 1. Packaging hierarchy — the core modelling problem

> *"most of the time there are packets within those packets — let the user insert the big packet and the number of sachets within it, so at POS we get proper calculations."*

### The model

| Table | Role |
|---|---|
| `products` | The item itself. Holds the **base unit** and the stock balance in base units. |
| `product_units` | One row per packaging level that can be bought or sold. |
| `barcodes` | Many barcodes per packaging level. |

Each `product_units` row stores **both** how the shopkeeper entered it *and* the flattened number the POS actually uses:

- `parent_unit_id` + `qty_per_parent` — how it was typed in ("1 carton = 12 boxes")
- `conversion_factor` — base units contained, computed and cached on save ("1 carton = 288 sachets")

### Worked example — Surf Excel

Shopkeeper types: *base = Sachet · 1 Box = 24 Sachets · 1 Carton = 12 Boxes*

| unit | parent | qty_per_parent | conversion_factor | sale_price | barcode |
|---|---|---|---|---|---|
| Sachet | — | 1 | **1** | 20.00 | 8964000101018 |
| Box | Sachet | 24 | **24** | 450.00 | 8964000101025 |
| Carton | Box | 12 | **288** | 5,200.00 | 8964000101032 |

- Buy 5 cartons → `+5 × 288 = +1,440` sachets
- Sell 1 box → `−24` sachets. Sell 3 sachets → `−3`
- Stock shows **1,413 sachets**, and the UI renders that as **4 cartons, 10 boxes, 21 sachets** by dividing down the factor chain. The shopkeeper never sees a raw number they can't act on.

Why flatten instead of walking a parent chain at POS time: a scan must resolve in one indexed lookup. `qty_per_parent` is kept so the edit screen can show the familiar "12 × 24", and so the factor can be recomputed if a packaging size ever changes.

### Repacking

When a carton is broken open to sell loose sachets, nothing needs to happen — stock was always counted in sachets. This is the main payoff of the base-unit invariant.

---

## 2. Table catalogue

### System & access

**`users`** — `name`, `email`, `password`, `role` (`owner|manager|cashier`), `phone`, `pin_code` (hashed 4-digit, for fast counter switching), `is_active`.

Roles drive Gates: cashiers never see cost or margin, cannot void a completed sale, cannot approve a drawer variance.

**`settings`** — `key`, `value` (json), `is_encrypted`. Holds shop name/address/NTN, GST rate and whether prices are tax-inclusive, receipt footer, printer configuration, AI provider credentials, low-stock thresholds, language.

**`activity_logs`** — `user_id`, `action`, `subject_type`, `subject_id`, `before` (json), `after` (json), `ip`, `created_at`. Covers price edits, voids, stock adjustments and drawer events.

### Catalogue

**`categories`** — `name`, `name_ur`, `parent_id` (nullable, one level of sub-category), `is_active`.

**`brands`** — `name`, `is_active`.

**`units`** — the global vocabulary of unit names: `name` ("Sachet", "Box", "Carton", "Kg", "Gram", "Litre", "Piece"), `short_name`, `type` (`count|weight|volume`).

**`products`**

| column | notes |
|---|---|
| `sku` | auto-generated, unique |
| `name`, `name_ur` | bilingual display |
| `category_id`, `brand_id` | nullable FKs |
| `base_unit_id` | FK → `units`. **Immutable once stock exists.** |
| `tax_rate` | GST %, defaults from settings |
| `is_weighted` | loose items priced by weight |
| `track_batches`, `track_expiry` | opt-in per product, default off |
| `reorder_level_base`, `reorder_qty_base` | reorder trigger, in base units |
| `avg_cost_base` | moving weighted-average cost of one base unit |
| `stock_qty_base` | cached balance, derived from `stock_movements` |
| `is_active` | retire without deleting history |

**`product_units`** — `product_id`, `unit_id`, `parent_unit_id`, `qty_per_parent`, `conversion_factor`, `sale_price`, `mrp`, `is_base`, `is_default_sale`, `is_default_purchase`. Unique on (`product_id`, `unit_id`).

**`barcodes`** — `product_unit_id`, `code` (**unique across the whole table**), `is_primary`. A separate table because the same box genuinely arrives with different supplier barcodes, and the shop prints its own labels for loose goods.

### Inventory

**`stock_movements`** — the ledger. `product_id`, `product_unit_id` (what was actually scanned), `qty_base` (**signed**), `type` (`opening|purchase|sale|sale_return|purchase_return|adjustment|stock_take`), `reference_type`, `reference_id` (morph to the purchase/sale/adjustment), `unit_cost_base`, `balance_after_base`, `batch_id`, `user_id`, `note`, `occurred_at`.

Indexed on (`product_id`, `occurred_at`) — this is what every stock report reads.

**`stock_batches`** — only for products with `track_batches`. `product_id`, `batch_no`, `expiry_date`, `qty_base_remaining`, `unit_cost_base`, `purchase_item_id`. Deducted FEFO (first-expiring-first-out) at sale time.

**`stock_adjustments`** / **`stock_adjustment_items`** — `reason` (`damage|expiry|theft|recount|opening|internal_use`), `note`, `user_id`, `approved_by`. Reason codes are what make the shrinkage insight possible, so they are a required enum, not free text.

**`stock_takes`** / **`stock_take_items`** — scan-based physical counts: `counted_qty_base`, `system_qty_base`, `variance_base`, `variance_value`. Posting a stock take writes one `stock_adjustment` with reason `recount`.

### Purchasing

**`suppliers`** — `name`, `company`, `phone`, `address`, `payment_terms_days`, `opening_balance`, `balance`, `is_active`.

**`purchases`** — `supplier_id`, `invoice_no`, `purchase_date`, `subtotal`, `discount`, `tax`, `total`, `paid`, `due`, `status` (`draft|received|cancelled`), `user_id`, `note`. Stock and the supplier ledger only move when status becomes `received`.

**`purchase_items`** — `purchase_id`, `product_id`, `product_unit_id`, `qty`, `qty_base`, `unit_cost`, `cost_base`, `expiry_date`, `batch_no`, `line_total`.

**`purchase_returns`** / **`purchase_return_items`** — goods back to supplier.

**`supplier_ledger_entries`** — `supplier_id`, `type` (`opening|purchase|payment|return|adjustment`), `debit`, `credit`, `balance_after`, `reference_type`, `reference_id`, `entry_date`, `user_id`, `note`.

### Selling

**`registers`** — the physical counters: `name` ("Counter 1"), `location`, `printer_profile`, `is_active`.

**`sales`** — `invoice_no`, `customer_id` (nullable = walk-in), `register_id`, `drawer_session_id`, `user_id`, `subtotal`, `discount`, `tax`, `total`, `paid`, `change_given`, `due`, `status` (`held|completed|void|returned|partially_returned`), `sold_at`, `note`. Held sales are how a cashier parks a basket to serve the next customer.

**`sale_items`** — `sale_id`, `product_id`, `product_unit_id`, `qty`, `qty_base`, `unit_price`, `discount`, `tax_amount`, `line_total`, **`cost_at_sale_base`**, `batch_id`.

**`sale_payments`** — `sale_id`, `method` (`cash|card|easypaisa|jazzcash|bank|khata`), `amount`, `reference`, `user_id`. A separate table so split tender ("Rs.500 cash, rest on khata") is native rather than bolted on.

**`sale_returns`** / **`sale_return_items`** — reference the original sale, restore stock, and either refund cash from the drawer or credit the customer's khata.

### Customers & Khata

**`customers`** — `name`, `name_ur`, `phone` (unique), `address`, `credit_limit`, `opening_balance`, `balance`, `is_active`, `notes`.

**`customer_ledger_entries`** — the khata itself. `customer_id`, `type` (`opening|sale_credit|payment|sale_return|adjustment|write_off`), `debit` (customer owes more), `credit` (customer paid or was credited), `balance_after`, `reference_type`, `reference_id`, `entry_date`, `due_date`, `user_id`, `note`.

`due_date` is what makes **aging** possible — grouping outstanding balance into 0–30 / 31–60 / 61–90 / 90+ day buckets. That single report is the difference between a khata book and a khata *system*, and it is the input to the credit-risk AI insight.

### Drawer & cash control

**`drawer_sessions`** — one shift on one register.

| column | meaning |
|---|---|
| `register_id`, `opened_by`, `opened_at` | who started the shift |
| `opening_float` | **the opening balance put into the drawer** |
| `closed_by`, `closed_at` | who ended it |
| `expected_cash` | computed: float + cash sales + cash khata receipts + pay-ins − pay-outs − refunds − safe drops |
| `counted_cash` | what was physically counted |
| `variance` | `counted − expected` (negative = short) |
| `variance_reason`, `approved_by` | manager sign-off on a discrepancy |
| `status` | `open` \| `closed` — only one `open` session per register at a time |

**`drawer_transactions`** — every cash event in the shift: `drawer_session_id`, `type` (`opening_float|cash_sale|refund|pay_in|pay_out|khata_payment|safe_drop`), `amount`, `reference_type`, `reference_id`, `user_id`, `note`, `created_at`.

`safe_drop` is literally *"someone emptied the drawer"* — amount, person, timestamp and reason, all logged and reportable. Because the next shift's `opening_float` is its own recorded row, the question *"what opening balance was added into the drawer next time?"* is answered by reading two rows rather than trusting anyone's memory.

**`cash_count_lines`** — `drawer_session_id`, `denomination` (5000/1000/500/100/50/20/10/5/2/1), `count`, `subtotal`. The close screen asks "how many 1000 notes?" instead of "type the total", which is both faster and far less error-prone for a non-technical user.

### AI

**`ai_insight_runs`** — `page`, `scope_hash`, `provider`, `model`, `input_tokens`, `output_tokens`, `estimated_cost`, `latency_ms`, `metric_pack` (json), `response` (json), `user_id`, `created_at`. Gives caching, a history of what was advised, and a running cost meter.

---

## 3. Relationship map

```
categories ─┐                              ┌─ barcodes
brands ─────┼─< products ─< product_units ─┘
units ──────┘      │
                   ├─< stock_movements >── (purchase | sale | adjustment | stock_take)
                   ├─< stock_batches
                   ├─< purchase_items >── purchases >── suppliers >── supplier_ledger_entries
                   └─< sale_items ───── sales ──┬── customers >── customer_ledger_entries
                                                ├──< sale_payments
                                                ├──< sale_returns
                                                ├── registers
                                                └── drawer_sessions ──< drawer_transactions
                                                                     └─< cash_count_lines

users ── activity_logs · ai_insight_runs · (every ledger and movement carries user_id)
```

---

## 4. Key derived values

| Value | Formula |
|---|---|
| Moving average cost | `new_avg = (old_qty × old_avg + recv_qty × recv_cost) ÷ (old_qty + recv_qty)` — recalculated on every received purchase line |
| COGS of a sale | `Σ (sale_items.qty_base × cost_at_sale_base)` |
| Gross profit | `sales.total − tax − COGS` |
| Stock value at cost | `Σ (products.stock_qty_base × avg_cost_base)` |
| Days of supply | `stock_qty_base ÷ average daily base units sold (last 30d)` |
| Reorder point | `avg daily sales × supplier lead-time days × 1.5 safety factor` |
| Customer balance | `Σ debit − Σ credit` over `customer_ledger_entries` |
| Expected cash | `opening_float + cash_sales + khata_receipts + pay_ins − pay_outs − refunds − safe_drops` |
