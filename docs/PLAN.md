# SuperMart — Build Plan

**Goal:** a supermarket POS + inventory + khata system that a non-technical shopkeeper can run
daily, mobile-first for everyday use, desktop for printing.

**Decisions locked in:** Pakistan (PKR, GST) · single store, multiple counters · USB thermal
printer shared from the counter PC · multi-provider AI with the user's own API key.

Related documents: [DATABASE.md](DATABASE.md) · [AI-INSIGHTS.md](AI-INSIGHTS.md)

---

## Stack

| Concern | Choice | Note |
|---|---|---|
| Framework | Laravel 13.31, PHP 8.3 | already scaffolded |
| Database | MySQL 8.4 | switching off the default SQLite in Phase 0 |
| Auth | Laravel Breeze (Blade) | confirmed compatible with Laravel 13 |
| Frontend | Blade + Alpine.js + Tailwind v4 | server-rendered, no SPA — fewer moving parts to maintain |
| Charts | Chart.js 4 | |
| Thermal printing | `mike42/escpos-php` v5 | ESC/POS, includes the cash-drawer kick |
| A4 / PDF | `barryvdh/laravel-dompdf` | invoices, khata statements, reports |
| Barcode images | `picqer/php-barcode-generator` | shelf and product labels |
| Camera scanning | `html5-qrcode` | mobile scanning without hardware |
| Animation | Alpine transitions + Motion One (~5 KB) | Lottie only for the payment-success tick |
| AI | own drivers over Anthropic SDK + OpenAI-compatible HTTP | see AI-INSIGHTS.md |

### Why Blade + Alpine rather than a SPA

The brief is explicit that the code must stay maintainable and not collapse into one giant file.
For this domain, server-rendered Blade with Alpine islands gives smaller surface area, no build-step
surprises on a shop PC, and far easier debugging than a Vue/React POS. Alpine handles the genuinely
interactive parts (cart, scan field, payment sheet, drawer count) and nothing else.

### Code organisation rules

- **Services own the writes.** `StockService`, `SaleService`, `DrawerService`, `KhataService`,
  `PurchaseService`. Controllers validate and delegate; they never touch balances directly.
- **Form Requests** for every write endpoint.
- **Blade components** for anything used twice (`x-money`, `x-stat-card`, `x-scan-input`,
  `x-ai-insight-panel`, `x-qty-stepper`).
- **Enums** for every status and type column.
- **Actions/DTOs** for the multi-step operations (posting a purchase, closing a drawer).
- Every money mutation runs inside a DB transaction with row locking on the balance record.

---

## Printing architecture

The printer is USB, attached to the counter PC, and shared as a Windows printer. Laravel runs on
that same PC under Laragon, so **the server prints, not the browser**. That is what makes a sale
rung up on a phone able to print at the counter.

```
Phone (POS) ──HTTP──▶ Laravel on counter PC ──escpos──▶ \\localhost\THERMAL ──USB──▶ printer
                                                                                      └─▶ cash drawer (RJ11)
```

Three receipt outputs share one `ReceiptPrinter` contract:

| Driver | Use |
|---|---|
| `WindowsShareDriver` | ESC/POS via `WindowsPrintConnector` — 80 mm receipt, and `pulse()` to pop the drawer |
| `BrowserHtmlDriver` | 80 mm / 58 mm HTML with a `@page` stylesheet — fallback, and for phones |
| `PdfDriver` | A4 invoice, khata statement, Z-report via DomPDF |

**Known risk, addressed in Phase 7:** if Apache runs as a Windows *service* under `LocalSystem`,
it cannot see a user-mapped printer share. Mitigations, in order of preference: run the print job
through a `queue:work` worker started by the logged-in user; or share the printer and address it
as `\\<PC-NAME>\THERMAL`; or attach the printer by IP if the model supports it. Phase 7 starts with
a **Test Print** button in Settings precisely so this is proven early rather than discovered late.

---

## Mobile-first UI

- Bottom tab bar on phones (Sell · Stock · Khata · Drawer · More); sidebar from `lg:` up.
- Minimum 44 px touch targets, numeric keypads on amount fields, no hover-only affordances.
- The POS scan field is always focused — a USB scanner is just a keyboard, so scanning works with
  zero configuration. On a phone, a camera button opens `html5-qrcode`.
- Big, unambiguous confirmations for destructive or financial actions.
- Bilingual labels (English / اردو) driven by Laravel localisation, toggled in Settings.

---

## Phases

Each phase is independently testable and leaves the app in a working state. We do them in order,
one at a time, and you check the result before we move on.

### Phase 0 — Foundation
Finish Laravel Boost setup · switch to MySQL and create the `supermart` schema · install Breeze
(Blade) · Tailwind v4 + Alpine + Motion One · the app shell (bottom nav / sidebar, theme, PKR money
helper) · `role` on users with Gates · `settings` table + Settings screen · base seeders (units,
categories, a demo admin).
**Done when:** you can log in on a phone and see the empty shell with working navigation.
**Status: complete.** Units and categories moved to Phase 1, where their tables are created; the seeder here creates the owner account only. Public registration and self-delete were removed from Breeze — staff accounts are created and deactivated by the owner.

### Phase 1 — Catalogue and the packaging model
Categories · brands · units · products · **`product_units` with the parent/child packaging entry
UI** · multi-barcode support · product list with search and filters · barcode label printing.
**Done when:** you can enter "1 carton = 12 boxes = 24 sachets", scan any of the three barcodes,
and the system resolves the right unit and price.
**Status: complete.** Packaging is entered as it arrives — each size described in terms of a
smaller one — and flattened to a `conversion_factor` in base units on save. Every way the chain
can be wrong (a size listed twice, a pack made of itself, a parent not on the form, a barcode
another product already owns, the base unit changed after stock is counted in it) is refused in
the form request, in plain language. Barcode labels print from a dependency-free Code 128
renderer, on A4 stationery or a 50 mm thermal roll.

### Phase 2 — Inventory core
`StockService` and the `stock_movements` ledger · moving average cost · opening stock entry ·
stock adjustments with reason codes · stock ledger view per product · a `stock:recalculate`
command that rebuilds cached balances from movements.
**Done when:** every stock change is traceable to a movement row, and the balance can be rebuilt
from scratch and match.
**Status: complete.** `stock_movements` is the only thing that writes stock; `products.stock_qty_base`
and `avg_cost_base_paisa` are a cache of it, and `stock:recalculate` rebuilds both from nothing —
`--check` asks the question of a live shop without writing, and exits non-zero so a scheduled run is
noticed. Cost is a moving weighted average in paisa per base unit; outgoing stock never revalues it.
Corrections are drafts until posted, posting is a one-way door, and a mistake is fixed with another
correction rather than an edit. Quantities are settled at post time, not entry time — a recount
measures against the shelf as it stands when it is posted, so a sale rung up in between is not
counted twice. `/stock` and a product's ledger are open to any signed-in user; cost and value are
behind `see-financials` and the whole corrections area behind `supervise`.

### Phase 3 — Purchases and scanner stock entry
Suppliers · purchase entry driven by the scanner (scan → known item increments, unknown item opens
quick-create) · receive a purchase to post stock and update cost · purchase returns · supplier
ledger · purchase suggestion list from reorder points.
**Done when:** a delivery can be entered end to end by scanning, and stock plus cost update
correctly.
**Status: complete.** A delivery is scanned in carton by carton: a known barcode adds a line or
bumps its quantity, and an unknown one opens a quick-add sheet without leaving the bill. Each line
offers the price last paid for that size. A bill is saved as a draft or received straight away, and
only receiving touches stock and the supplier's account. Discounts and GST on the bill are shared
over the lines by value, in whole paisa. Free goods count as stock and lower the cost of every
piece, so the moving average reflects what the shop actually paid. Money handed over on delivery
comes off the account on the same statement. The same paper bill cannot be entered twice.
Received bills are locked. Returns go back at the average cost, so the goods left on the shelf keep
their value. What the supplier gives back is a separate figure, and the gap is recorded as a loss.
A return settled in cash appears on the statement without changing the balance. The reorder list
groups low items under the supplier they last came from and turns ticked lines into a draft bill.
Purchasing and supplier accounts sit behind `supervise`.

### Phase 4 — POS
Register selection · scan-driven cart · unit picker · manual search for loose items · line and
bill discounts · GST calculation · split payment (cash / card / Easypaisa / JazzCash / khata) ·
hold and resume · on-screen receipt · void with reason.
**Done when:** a full sale can be completed on a phone in under 20 seconds.
**Status: complete.** The fast path is scan → Pay (F9) → Exact → Complete (Enter) → Next
customer. The camera uses the phone's built-in barcode reader; a USB scanner types into the same
box. The till works the bill out in the browser for instant feedback, but the server prices it
again from the database. If a price changed since the scan, the till is shown the new figures
instead of the sale going through. The browser and server maths are held to the same figures by
tests. Typed discounts are rupees unless they end in %. Bill discounts are shared over the lines
to the paisa. GST is worked out after discounts, and the total rounds to the rupee with the
rounding shown as its own line. Stock always comes off in base units, and half a carton is fine
but half a sachet is refused. Card and wallets are taken exactly, cash gives change, and what is
unpaid goes on the chosen customer's khata with a due date. Cashiers are held to the discount and
credit limits, and managers are not. Held baskets take no invoice number and move nothing, and
can be picked up at any counter only once. A supervisor can void a same-day bill, which puts the
stock back at its sale-day cost and takes the khata back off. Receipts print on 80 mm, 58 mm or
A4 from each counter's own paper setting. Cashiers see only their own bills.

### Phase 5 — Drawer management
Open shift with opening float · live drawer view · pay-in / pay-out · **safe drop ("emptied the
drawer")** · denomination-based close · expected vs counted vs variance · manager approval on
variance · X-report (peek) and Z-report (end of sale) · full drawer history.
**Done when:** the story *"who opened it, with how much, who emptied it and when, what the count
said, and what the next shift started with"* is answerable from one screen.
**Status: complete.** Each counter's shift is a ledger. Opening the drawer writes the float, every
completed sale writes the cash it kept (net of change), a cancelled bill hands its cash back out of
the open drawer, and pay-ins, pay-outs (with a reason) and safe drops are typed in with a name on
them. What the drawer should hold is always the sum of that ledger, never a typed figure. The till
will not sell until the counter's drawer is open, and it offers what the last shift left behind as
the opening cash. The close is counted note by note in Pakistani denominations. The variance is
checked against the tolerance in Settings; beyond it a reason is required and a manager signs it
off, unless the closer is one. With blind counting on, a cashier never sees the expected cash, the
sales that add up to it, or the variance. The drawer page tells the shift top to bottom: who opened
it and with how much, any gap from the previous shift, every safe drop, the count, and who opened
the next shift with what. X- and Z-reports print on 80 mm, 58 mm or A4.

### Phase 6 — Customers and Khata
Customers with credit limits · sell on khata from POS · ledger with running balance · record
payments · statement view · aging buckets · credit-limit warnings at POS · WhatsApp/SMS reminder
text generation.
**Done when:** a customer's khata is accurate, printable, and the aging report is correct.
**Status: complete.** The khata is a ledger, not a number. Every sale on credit, every rupee handed
back, every correction and every write-off appends one line with the balance as it stood after it,
written inside the same locked transaction as the balance itself — so the figure on the screen can
always be replayed from the lines beneath it, and a drifted balance is rebuilt from them rather
than trusted. A khata opens with whatever the old register said was owed, or below zero when the
customer was already in advance. Cash taken against a khata goes into the counter's open drawer as
a typed entry, so the end-of-shift count answers for it exactly as it does for a sale; a bank
transfer or wallet payment never touches the drawer, and the till refuses cash for a counter with
no drawer open. Reading a khata is open to everyone on the counter, and so is taking money, because
"kitna baaqi hai?" is asked at the till and a payment nobody is allowed to write is a payment that
goes unwritten; opening a khata, setting a credit limit, correcting a balance and giving up on a
debt are the owner's. Aging is read the way the shopkeeper reads it — a payment always clears the
oldest purchase first — and the report sorts by the longest wait rather than the largest amount,
with the whole khata across the top and the filtered rows totalled at the bottom. The statement
prints on 80 mm, 58 mm or A4, dated or from the beginning, with a brought-forward line when it
starts partway. The reminder writes the words and gets the amount right; the shop sends it itself,
from its own number, and nothing about that is recorded here.

### Phase 7 — Printing
`ReceiptPrinter` drivers · printer settings with **Test Print** and **Open Drawer** buttons · 80 mm
and 58 mm receipt layouts · cash-drawer pulse on cash sales · A4 invoice · khata statement PDF ·
Z-report print · auto-print toggle.
**Done when:** a sale prints on the thermal printer and the drawer pops, from both the PC and a
phone.
**Status: complete.** A printer is described once, in plain terms a shop owner has been told by
whoever wired the counter up: how the server reaches it (a Windows share, an address on the
network, or the browser), the width of the roll, whether there is a cutter, whether a drawer hangs
off the back and on which pin. A counter points at one; a shop with a single till does not have to
point at anything. **Test Print** puts a ruler line on paper and fires the drawer in the same
breath, so the width and the wiring are both proved before a customer is waiting, and the failure
messages say what to do rather than what went wrong. The slips are laid out against a column count
— 42 across an 80 mm roll, 32 across 58 mm — and a test holds every line of every slip to that
width, so a long shop name wraps instead of falling off the edge. Urdu is dropped from the ESC/POS
stream rather than printed as rubbish, because the English name is always beside it.

The drawer pops only when a note actually changes hands: card and khata bills leave it shut, and a
shop that keeps its drawer unlocked can switch the pop off. Auto-print is off by default, and the
till is told whether the counter printed, so on a phone the browser steps in instead. A jammed
printer is logged and never loses the sale — the bill stands, printed or not. An end-of-sale report
prints from the drawer screen, and a cashier counting blind is not handed the expected figure on
paper any more than on screen. Browser printing stays a first-class path throughout, because it is
the only one a phone has.

Built with no new dependencies: the ESC/POS byte stream is hand-rolled in `app/Support/EscPos.php`,
which also makes it assertable in tests, and the Windows share driver does the `copy /b` a printer
library would do. The A4 invoice, khata statement and Z-report already print from the browser
through `@page` stylesheets, so no PDF engine was added.

### Phase 8 — Returns, batches, expiry, stock takes
Sale returns (cash refund or khata credit) · batch and expiry tracking for flagged products · FEFO
deduction · expiry dashboard · scan-based stock take with variance posting.
**Done when:** an expiring-stock list is accurate and a physical count can be posted.
**Status: complete.** A return starts from the bill, never from the shelf: the refund is worked
out from what the customer actually paid, discount and all, and a line can only come back as many
times as it was sold. Cash comes out of a named counter's drawer and is counted for at the end of
the shift; khata credit comes off what the customer owes, and a bill with no customer is told to
hand cash back instead. Goods that are fine go back on the shelf at what they cost on the day they
were sold, so a return never moves the shop's cost base; expired or broken goods are refunded but
never restocked.

Batches are only kept for products flagged for them. A delivery of one of those asks for the
batch and expiry date, and stock leaves first-expiry-first-out through the same ledger that moves
everything else — at the till, on a supplier return, on a correction or a count — which is what
keeps the expiring-stock list true to the shelf. When the layers do not cover what is leaving,
stock from before tracking was switched on, say, what can be taken is taken and the sale still
goes through: the till is never held up by paperwork. The expiry screen is ordered by how little
time is left rather than by money, looks 7, 30 or 90 days ahead with "already gone" on its own,
and is open to the cashiers who do the facing up; what the stock is worth stays behind
`see-financials`.

A stock take is its own record rather than a pile of corrections. A supervisor picks a section,
walks it with the scanner — a carton scan counts a whole carton — and can save and pick the count
up again as often as it takes. Nothing reaches the books until it is posted. Selling carries on
throughout: each item is held against the books as they stood at the moment it was first scanned,
by the server's clock, so a packet sold after the scan is neither missing nor counted twice. The
one risky switch — "anything in this section I did not scan is gone" — needs a section, covers its
subsections, and is what finds stock that is on the books but not on the shelf. The posted report
leads with the biggest losses at cost, marks what was never found, and every difference is a line
in the stock ledger with the count's reference on it. A posted count cannot be edited or abandoned;
a wrong one is put right by counting again.

### Phase 9 — Reports and dashboard
Chart.js dashboard (sales trend, top products, category mix, cash position) · daily sales · profit
and margin · stock valuation · dead stock · cashier performance · supplier report · everything
exportable to PDF and Excel.
**Done when:** the owner can answer "how did we do this month, and where is the money" without
asking anyone.
**Status: complete.** Every report is one small class that answers one question and hands back
the same shape — columns, rows, totals, a few headline figures and a chart — so the screen, the
A4 print and the download are one template each rather than one per report. Six are in: daily
sales (with the tender split, and returns on the day they came back), profit and margin by
product or category, stock value at cost and at selling price, dead stock, cashier performance
and suppliers. The dates are picked from plain presets, anything typed by hand is kept sensible,
and every headline figure says how it compares with the stretch before. The shop's clock is now
set to Pakistan time, so a bill rung up at 11:55 at night lands on the right day.

Profit is sales before GST less what the goods cost on the day they were sold. A return takes its
money back off, and takes its cost back only when the goods went back on the shelf — a broken or
expired return is a loss, and the report shows it as one. Stock below zero is listed in red rather
than counted as negative money, and a product that has never sold is judged from the day it was
added. A drawer that closed short is put against the person who opened the shift.

"Excel" is a CSV file that Excel opens directly, with Urdu intact and anything that looks like a
formula made harmless; "PDF" is the A4 print page saved from the browser. Neither needed a new
package. Every download is written to the activity log.

The dashboard is the same figures, not a second set of sums: sales and profit come from the daily
sales and profit reports, so the two can never disagree. The owner and manager see today against
the same day last week, the month so far against the same dates last month, where the money is
sitting (the drawers, the shelves, the khata, less what is owed to suppliers), the last 30 days,
the best sellers and the category mix. A cashier sees their own day, the open drawers — without
the cash figure when blind counting is on — and what needs looking at on the shelves; never
profit, cost or the shop's takings.

### Phase 10 — AI Insights
Provider settings (choose · key · fetch models · test · save · spend cap) · the three drivers ·
`MetricPack` builders per page · the rules engine and findings · the insight panel component ·
per-page prompts · caching and cost tracking · rule-based fallback.
**Done when:** every major page has a working **Explain this page** button that still produces
useful output with the API key removed.

**Status: complete.** Six companies can be used — Claude, Gemini, OpenAI, Groq, Z.ai and Ollama
Cloud — plus any other service that speaks OpenAI's language. The owner picks one, pastes their own
key, fetches the list of models that key can actually reach, tests one and saves. The key is
encrypted in the settings table, is never sent back to a browser (the screen shows only the first
and last few characters), never appears in the activity log, and leaves the server only on its way
to the AI company. Three drivers cover the lot: Anthropic's own dialect, Gemini's, and OpenAI's —
which the rest borrow.

Every page hands the AI a metric pack: totals, rankings and names, never a phone number, an
address or anything about a staff account. The pack is built from the same reports the screens
use, so the note and the figures on the page cannot disagree. Before the AI sees it, the shop's
own checks run over the pack and raise findings — stock below zero, expired goods still counted,
a customer past their khata limit, khata older than ninety days, a drawer count well outside the
allowance, a supplier overdue. The AI is asked to explain those findings, not to invent its own;
a finding key it makes up is shown as an ordinary note rather than a red one.

Cost is kept in hand. Each answer is saved against a fingerprint of the figures behind it, so
asking again about an unchanged page costs nothing and says "Saved answer"; **Ask again** forces a
fresh one. Every run records its tokens and what it cost in rupees at the dollar rate the owner
set, and a monthly cap switches the AI off once it is reached — 0 switches it off altogether. If
the company is down, the key is refused or the answer comes back in the wrong shape, the page
still explains itself from the shop's own checks, which is also what happens when there is no key
at all. That last case is the one the tests hold to: every page in the registry produces a useful
note with nothing set up.

### Phase 11 — Polish
Motion One transitions · Lottie success tick · keyboard shortcuts for the counter · offline-tolerant
POS (queue a sale locally, sync on reconnect) · Urdu labels · activity-log viewer · automated
database backup to a local folder.

**Status: complete.** Urdu labels were dropped at the shopkeeper's request; everything else was
built. The database copies itself to a folder the owner chooses, on a schedule, keeping as many
days as asked and saying plainly when it could not — and only the owner may take a copy, download
one or throw one away. The activity log is now readable: filtered by day, by part of the shop and
by person, every action written out in plain words rather than a code, with what changed before and
after. The counter has a keyboard: F1 shows the list, F2 searches, F3 picks the customer, F4 brings
back a held basket, F6 holds this one, F7 takes off the last line, F8 discounts it, F9 pays, and
plus and minus change the count when the scan box is empty, so a barcode is never interrupted. The
till now survives the line going down: the bill is named before it is sent, kept on the till when
nothing answers, and sent by itself when the connection returns — the same name twice gets the same
bill back, so nobody is charged twice, and a bill caught up later neither prints nor pops a drawer
at an empty counter. What is waiting is shown at the top of the till, and only a manager may throw a
waiting bill away. Pages settle in rather than appearing all at once, and the tick after a sale is
drawn stroke by stroke — with an inline SVG driven by Motion One, which was already installed, so
no new package was added. Everything honours the device's reduced-motion setting.

### Phase 12 — Hardening and handover
Feature tests on the money paths (stock maths, drawer close, khata balance, unit conversion) ·
realistic demo seed data · performance pass on POS queries · deployment notes · a short illustrated
user manual in simple language.

---

## Sequencing rationale

Phases 1–2 come before everything because the unit-conversion and stock-ledger invariants are the
ones that are painful to retrofit. POS (4) precedes drawer (5) because a drawer session needs sales
to reconcile against. Printing (7) is deliberately *after* POS and drawer so there is real content
to print, but before returns and reports so the hardware risk surfaces early. AI (10) is last among
the functional phases because every insight needs real data flowing through the tables first.
