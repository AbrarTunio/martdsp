<?php

namespace Tests\Feature;

use App\Enums\CustomerEntryType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightRegistry;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop's own checks.
 *
 * These are what the insight panel falls back to with no AI key, and what the
 * AI is handed when there is one — so each one is worth proving on its own:
 * it fires when the shop has the problem, and stays quiet when it does not.
 */
class InsightPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_that_shows_less_than_nothing_is_raised(): void
    {
        Product::factory()->withStock(-6)->create(['name' => 'Tapal Danedar']);

        $finding = $this->finding('stock', 'below_zero');

        $this->assertNotNull($finding);
        $this->assertSame('high', $finding->severity->value);
        $this->assertStringContainsString('Tapal Danedar', $finding->detail);
    }

    public function test_expired_goods_still_counted_as_stock_are_raised(): void
    {
        $product = Product::factory()->perishable()->withStock(40)->create(['name' => 'Olpers Milk']);

        StockBatch::factory()->expired()->create([
            'product_id' => $product->id,
            'qty_base' => 40,
            'cost_base_paisa' => 15_000,
        ]);

        $finding = $this->finding('stock', 'expired');

        $this->assertNotNull($finding);
        $this->assertSame(600_000, $finding->impactPaisa);
        $this->assertStringContainsString('Olpers Milk', $finding->detail);
    }

    public function test_a_product_at_its_reorder_level_is_raised(): void
    {
        Product::factory()->withStock(2)->create(['name' => 'Surf Excel', 'reorder_level_base' => 5]);

        $this->assertNotNull($this->finding('stock', 'low_stock'));
    }

    public function test_a_shop_with_nothing_wrong_raises_nothing_about_its_stock(): void
    {
        Product::factory()->withStock(200)->create(['reorder_level_base' => 10]);

        $this->assertNull($this->finding('stock', 'below_zero'));
        $this->assertNull($this->finding('stock', 'low_stock'));
        $this->assertNull($this->finding('stock', 'expired'));
    }

    public function test_a_customer_past_their_khata_limit_is_raised(): void
    {
        Customer::factory()->limit(500_000)->owing(800_000)->create(['name' => 'Bilal General Store']);

        $finding = $this->finding('khata', 'over_limit');

        $this->assertNotNull($finding);
        $this->assertSame(300_000, $finding->impactPaisa);
        $this->assertStringContainsString('Bilal General Store', $finding->detail);
    }

    public function test_khata_more_than_ninety_days_old_is_raised(): void
    {
        $customer = Customer::factory()->owing(250_000)->create(['name' => 'Rashid Kirana']);

        CustomerLedgerEntry::factory()->create([
            'customer_id' => $customer->id,
            'type' => CustomerEntryType::SaleCredit,
            'debit_paisa' => 250_000,
            'credit_paisa' => 0,
            'balance_after_paisa' => 250_000,
            'entry_date' => today()->subDays(140),
            'due_date' => today()->subDays(110),
        ]);

        $finding = $this->finding('khata', 'over_90');

        $this->assertNotNull($finding);
        $this->assertSame(250_000, $finding->impactPaisa);
    }

    /**
     * A customer's own note names them but never rings their phone number
     * through to the AI.
     */
    public function test_one_customers_pack_carries_a_name_and_no_phone_number(): void
    {
        $customer = Customer::factory()->owing(120_000)->create([
            'name' => 'Bilal General Store',
            'phone' => '03009998877',
        ]);

        $pack = $this->pack('khata', new InsightScope(User::factory()->owner()->create(), customer: $customer));
        $json = json_encode($pack->forAi());

        $this->assertStringContainsString('Bilal General Store', $json);
        $this->assertStringNotContainsString('03009998877', $json);
    }

    public function test_a_drawer_count_well_out_is_raised(): void
    {
        Setting::writeMany(['insights.variance_alert' => 500]);

        DrawerSession::factory()->closed(500_000, 440_000)->create();

        $finding = $this->finding('drawer', 'large_variance');

        $this->assertNotNull($finding);
        $this->assertSame(60_000, $finding->impactPaisa);
        $this->assertStringContainsString('short', $finding->detail);
    }

    public function test_a_drawer_count_within_the_allowance_is_left_alone(): void
    {
        Setting::writeMany(['insights.variance_alert' => 500]);

        DrawerSession::factory()->closed(500_000, 499_000)->create();

        $this->assertNull($this->finding('drawer', 'large_variance'));
    }

    public function test_a_supplier_paid_late_is_raised(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Haji Traders', 'company' => 'Nestlé']);
        $supplier->forceFill(['balance_paisa' => 450_000])->save();

        $finding = $this->finding('buying', 'overdue_payables');

        $this->assertNotNull($finding);
        $this->assertSame(450_000, $finding->impactPaisa);
        $this->assertStringContainsString('Haji Traders', $finding->detail);
    }

    /**
     * The dashboard's note is the other packs' worst findings gathered up, so
     * a problem anywhere in the shop reaches the front page.
     */
    public function test_the_business_pack_gathers_what_the_other_pages_found(): void
    {
        Product::factory()->withStock(-3)->create(['name' => 'Tapal Danedar']);
        Customer::factory()->limit(100_000)->owing(400_000)->create(['name' => 'Bilal General Store']);

        $pack = $this->pack('business');
        $keys = array_map(fn ($finding): string => $finding->key, $pack->findings);

        $this->assertContains('below_zero', $keys);
        $this->assertContains('over_limit', $keys);
        $this->assertLessThanOrEqual(8, count($pack->findings));
    }

    /**
     * Every pack must build on an empty shop without falling over — day one,
     * before a single sale.
     */
    public function test_every_pack_builds_for_a_brand_new_shop(): void
    {
        foreach (InsightRegistry::pages() as $page) {
            $pack = $this->pack($page);

            $this->assertSame($page, $pack->page);
            $this->assertNotSame('', $pack->summary);
            $this->assertNotSame('', $pack->fingerprint('anthropic', 'claude-opus-5', 'en'));
        }
    }

    /**
     * The saved answer is only reused while the figures behind it are the
     * same, so a sale made since always gets a fresh note.
     */
    public function test_the_fingerprint_changes_when_the_figures_do(): void
    {
        $before = $this->pack('stock')->fingerprint('anthropic', 'claude-opus-5', 'en');

        Product::factory()->withStock(-3)->create();

        $this->assertNotSame($before, $this->pack('stock')->fingerprint('anthropic', 'claude-opus-5', 'en'));
    }

    private function pack(string $page, ?InsightScope $scope = null): MetricPack
    {
        return app(InsightRegistry::class)->build($page, $scope ?? new InsightScope(User::factory()->owner()->create()));
    }

    private function finding(string $page, string $key): ?Finding
    {
        return $this->pack($page)->finding($key);
    }
}
