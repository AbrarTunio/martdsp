<?php

namespace Tests\Feature;

use App\Enums\CustomerEntryType;
use App\Enums\DrawerEntryType;
use App\Enums\MovementType;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\KhataService;
use App\Services\StockService;
use App\Support\KhataAging;
use App\Support\KhataReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The khata.
 *
 * The balance on the screen is only a cache of the lines beneath it, so every
 * test here checks both: the figure, and the line that explains it. The aging
 * is checked the way the shopkeeper reads it — a payment always clears the
 * oldest purchase first.
 */
class KhataTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnit $carton;

    private Register $register;

    private User $owner;

    private User $cashier;

    private KhataService $khata;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $product = Product::factory()->create(['name' => 'Surf Excel', 'base_unit_id' => $sachetUnit->id]);

        ProductUnit::factory()->base()->create([
            'product_id' => $product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 3_000,
        ]);

        /* Rs. 4,000 a carton. */
        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $product->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 400_000,
        ]);

        app(StockService::class)->record($product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create(['name' => 'Front']);
        $this->owner = User::factory()->owner()->create(['name' => 'Abrar']);
        $this->cashier = User::factory()->cashier()->create(['name' => 'Sana']);
        $this->khata = app(KhataService::class);
    }

    private function openDrawer(int $floatPaisa = 500_000): DrawerSession
    {
        return app(DrawerService::class)->open($this->register, $this->cashier, $floatPaisa, null);
    }

    /**
     * One carton put straight on a customer's khata.
     */
    private function sellOnKhata(Customer $customer, int $paisa = 400_000): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'customer_id' => $customer->id,
            'lines' => [['product_unit_id' => $this->carton->id, 'qty' => '1']],
            'payments' => [['method' => TenderType::Khata->value, 'amount_paisa' => $paisa]],
        ]);
    }

    /**
     * A khata built by hand, so the aging can be checked against dates that
     * are safely in the past.
     *
     * @param  list<array{paisa: int, days_ago: int}>  $purchases
     */
    private function khataWithPurchases(Customer $customer, array $purchases): void
    {
        foreach ($purchases as $purchase) {
            $on = today()->subDays($purchase['days_ago']);

            $this->khata->record(
                customer: $customer,
                type: CustomerEntryType::SaleCredit,
                debitPaisa: $purchase['paisa'],
                entryDate: $on,
                dueDate: $on,
            );
        }
    }

    public function test_a_khata_opens_with_what_was_already_written_in_the_old_register(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customers.store'), [
                'name' => 'Aslam',
                'phone' => '0300 1234567',
                'credit_limit' => '5000',
                'opening_balance' => '1200',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::sole();

        $this->assertSame(500_000, $customer->credit_limit_paisa);
        $this->assertSame(120_000, $customer->balance_paisa);
        $this->assertSame(120_000, $customer->opening_balance_paisa);

        $entry = $customer->ledgerEntries()->sole();
        $this->assertSame(CustomerEntryType::Opening, $entry->type);
        $this->assertSame(120_000, $entry->debit_paisa);
        $this->assertSame(120_000, $entry->balance_after_paisa);
    }

    public function test_money_already_paid_in_starts_the_khata_below_zero(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customers.store'), [
                'name' => 'Bilal',
                'opening_balance' => '800',
                'opening_is_advance' => '1',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::sole();

        $this->assertSame(-80_000, $customer->balance_paisa);
        $this->assertSame(80_000, $customer->ledgerEntries()->sole()->credit_paisa);
    }

    public function test_a_khata_that_starts_clear_gets_no_opening_line(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customers.store'), ['name' => 'Chand'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Customer::sole()->ledgerEntries()->count());
    }

    /**
     * A sale, a part payment and another sale: the running balance on every
     * line has to agree with the cached figure at the end.
     */
    public function test_the_running_balance_follows_every_line_in_order(): void
    {
        $customer = Customer::factory()->create(['name' => 'Aslam']);
        $this->openDrawer();

        $this->sellOnKhata($customer)->assertCreated();

        $this->actingAs($this->cashier)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '1500',
                'method' => TenderType::Cash->value,
                'register_id' => $this->register->id,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->sellOnKhata($customer)->assertCreated();

        $balances = $customer->ledgerEntries()->inLedgerOrder()->pluck('balance_after_paisa')->all();

        $this->assertSame([400_000, 250_000, 650_000], $balances);
        $this->assertSame(650_000, $customer->fresh()->balance_paisa);
        $this->assertSame(650_000, $this->khata->rebuild($customer)['balance_after']);
    }

    public function test_cash_taken_against_a_khata_lands_in_the_open_drawer(): void
    {
        $customer = Customer::factory()->owing(400_000)->create(['name' => 'Aslam']);
        $session = $this->openDrawer();

        $this->actingAs($this->cashier)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '2000',
                'method' => TenderType::Cash->value,
                'register_id' => $this->register->id,
                'paid_on' => today()->toDateString(),
            ])
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Rs. 2,000.00'));

        $this->assertSame(200_000, $customer->fresh()->balance_paisa);

        $movement = DrawerTransaction::query()
            ->where('drawer_session_id', $session->id)
            ->where('type', DrawerEntryType::KhataPayment)
            ->sole();

        $this->assertSame(200_000, $movement->amount_paisa);
        $this->assertSame('Khata payment from Aslam', $movement->note);
        $this->assertTrue($movement->reference->is($customer->ledgerEntries()->latestFirst()->first()));
    }

    public function test_cash_is_refused_when_no_drawer_is_open_at_that_counter(): void
    {
        $customer = Customer::factory()->owing(400_000)->create();

        $this->actingAs($this->cashier)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '2000',
                'method' => TenderType::Cash->value,
                'register_id' => $this->register->id,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('register_id');

        $this->assertSame(400_000, $customer->fresh()->balance_paisa);
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
    }

    /**
     * A transfer into the shop's bank never passes through the till, so the
     * drawer must not be asked to answer for it.
     */
    public function test_a_bank_payment_clears_the_khata_without_touching_the_drawer(): void
    {
        $customer = Customer::factory()->owing(400_000)->create();
        $session = $this->openDrawer();

        $this->actingAs($this->owner)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '4000',
                'method' => TenderType::BankTransfer->value,
                'paid_on' => today()->toDateString(),
                'note' => 'Meezan transfer',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'khata is clear'));

        $this->assertSame(0, $customer->fresh()->balance_paisa);
        $this->assertSame(0, DrawerTransaction::query()
            ->where('drawer_session_id', $session->id)
            ->where('type', DrawerEntryType::KhataPayment)
            ->count());
    }

    public function test_a_khata_cannot_be_cleared_with_khata(): void
    {
        $customer = Customer::factory()->owing(400_000)->create();

        $this->actingAs($this->owner)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '2000',
                'method' => TenderType::Khata->value,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('method');
    }

    public function test_a_slipped_zero_on_a_payment_is_caught(): void
    {
        $customer = Customer::factory()->owing(100_000)->create();
        $this->openDrawer();

        /* Rs. 1,000 owed, Rs. 20,000 typed in: a zero too many. */
        $this->actingAs($this->cashier)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '20000',
                'method' => TenderType::Cash->value,
                'register_id' => $this->register->id,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_sale_beyond_the_credit_limit_is_refused_at_the_till(): void
    {
        $customer = Customer::factory()->limit(500_000)->owing(200_000)->create(['name' => 'Aslam']);
        $this->openDrawer();

        $response = $this->sellOnKhata($customer);

        $response->assertUnprocessable();
        $this->assertStringContainsString('over their limit of Rs. 5,000.00', $response->json('message'));
        $this->assertSame(200_000, $customer->fresh()->balance_paisa);
    }

    public function test_a_sale_inside_the_credit_limit_goes_through(): void
    {
        $customer = Customer::factory()->limit(1_000_000)->create();
        $this->openDrawer();

        $this->sellOnKhata($customer)->assertCreated();

        $this->assertSame(400_000, $customer->fresh()->balance_paisa);
    }

    /**
     * Payments settle the oldest purchases first, so a customer who keeps
     * paying something never builds an old bucket.
     */
    public function test_the_aging_buckets_settle_the_oldest_purchase_first(): void
    {
        $customer = Customer::factory()->create();

        $this->khataWithPurchases($customer, [
            ['paisa' => 100_000, 'days_ago' => 120],
            ['paisa' => 200_000, 'days_ago' => 75],
            ['paisa' => 300_000, 'days_ago' => 40],
            ['paisa' => 400_000, 'days_ago' => 0],
        ]);

        /* Rs. 2,000 paid: it eats the 120-day bill and half the 75-day one. */
        $this->khata->payment($customer, 200_000, TenderType::BankTransfer);

        $aging = KhataAging::for($customer->fresh());

        $this->assertSame(0, $aging->paisa('over_90'));
        $this->assertSame(100_000, $aging->paisa('61_90'));
        $this->assertSame(300_000, $aging->paisa('31_60'));
        $this->assertSame(0, $aging->paisa('1_30'));
        $this->assertSame(400_000, $aging->paisa('current'));

        $this->assertSame(800_000, $aging->owedPaisa);
        $this->assertSame(400_000, $aging->overduePaisa());
        $this->assertSame(75, $aging->daysLate());
        $this->assertSame(800_000, $customer->fresh()->balance_paisa);
    }

    public function test_paying_more_than_is_owed_leaves_an_advance_and_no_aging(): void
    {
        $customer = Customer::factory()->create();

        $this->khataWithPurchases($customer, [['paisa' => 100_000, 'days_ago' => 60]]);

        $this->khata->payment($customer, 150_000, TenderType::BankTransfer);

        $aging = KhataAging::for($customer->fresh());

        $this->assertSame(0, $aging->owedPaisa);
        $this->assertSame(50_000, $aging->advancePaisa);
        $this->assertFalse($aging->isOverdue());
        $this->assertSame(-50_000, $customer->fresh()->balance_paisa);
    }

    public function test_the_aging_report_adds_every_khata_up(): void
    {
        $aslam = Customer::factory()->create(['name' => 'Aslam']);
        $bilal = Customer::factory()->create(['name' => 'Bilal']);
        Customer::factory()->create(['name' => 'Chand']);

        $this->khataWithPurchases($aslam, [['paisa' => 100_000, 'days_ago' => 100]]);
        $this->khataWithPurchases($bilal, [['paisa' => 250_000, 'days_ago' => 10]]);

        $response = $this->actingAs($this->owner)->get(route('customers.aging'));

        $response->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['over_90'] === 100_000
                && $totals['1_30'] === 250_000
                && $totals['current'] === 0)
            ->assertSeeText('Aslam')
            ->assertSeeText('Bilal')
            ->assertDontSeeText('Chand');

        /* The longest wait comes first, whatever the amount. */
        $this->assertSame(
            ['Aslam', 'Bilal'],
            $response->viewData('customers')->pluck('name')->all(),
        );
    }

    public function test_the_aging_report_can_be_narrowed_to_one_bucket(): void
    {
        $aslam = Customer::factory()->create(['name' => 'Aslam']);
        $bilal = Customer::factory()->create(['name' => 'Bilal']);

        $this->khataWithPurchases($aslam, [['paisa' => 100_000, 'days_ago' => 100]]);
        $this->khataWithPurchases($bilal, [['paisa' => 250_000, 'days_ago' => 10]]);

        $this->actingAs($this->owner)
            ->get(route('customers.aging', ['bucket' => 'over_90']))
            ->assertOk()
            ->assertSeeText('Aslam')
            ->assertDontSeeText('Bilal');
    }

    public function test_a_write_off_clears_the_debt_with_a_line_of_its_own(): void
    {
        $customer = Customer::factory()->owing(300_000)->create();

        $this->actingAs($this->owner)
            ->post(route('customers.write-off', $customer), [
                'amount' => '3000',
                'note' => 'Moved away, no contact for a year',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $customer->fresh()->balance_paisa);

        $entry = $customer->ledgerEntries()->latestFirst()->first();
        $this->assertSame(CustomerEntryType::WriteOff, $entry->type);
        $this->assertSame(300_000, $entry->credit_paisa);
        $this->assertSame('Moved away, no contact for a year', $entry->note);
    }

    public function test_more_cannot_be_written_off_than_is_owed(): void
    {
        $customer = Customer::factory()->owing(300_000)->create(['name' => 'Aslam']);

        $this->actingAs($this->owner)
            ->post(route('customers.write-off', $customer), [
                'amount' => '5000',
                'note' => 'Giving up on this one',
            ])
            ->assertSessionHasErrors(['amount' => 'Aslam only owes Rs. 3,000.00.']);

        $this->assertSame(300_000, $customer->fresh()->balance_paisa);
        $this->assertSame(0, $customer->ledgerEntries()->count());
    }

    public function test_a_correction_writes_the_reason_onto_the_khata(): void
    {
        $customer = Customer::factory()->owing(300_000)->create();

        $this->actingAs($this->owner)
            ->post(route('customers.adjustments.store', $customer), [
                'amount' => '500',
                'direction' => 'less',
                'note' => 'Double entry on 12 March, agreed with him',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(250_000, $customer->fresh()->balance_paisa);

        $entry = $customer->ledgerEntries()->latestFirst()->first();
        $this->assertSame(CustomerEntryType::Adjustment, $entry->type);
        $this->assertSame(50_000, $entry->credit_paisa);
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $customer = Customer::factory()->owing(300_000)->create();

        $this->actingAs($this->owner)
            ->post(route('customers.adjustments.store', $customer), [
                'amount' => '500',
                'direction' => 'less',
            ])
            ->assertSessionHasErrors('note');
    }

    /**
     * The statement is the one thing that settles an argument at the counter,
     * so it has to come out on either roll and on A4.
     */
    public function test_the_statement_prints_on_both_rolls_and_on_a4(): void
    {
        $customer = Customer::factory()->create(['name' => 'Aslam']);
        $this->khataWithPurchases($customer, [['paisa' => 300_000, 'days_ago' => 5]]);

        foreach (['80', '58', 'a4'] as $paper) {
            $this->actingAs($this->owner)
                ->get(route('customers.statement', ['customer' => $customer, 'paper' => $paper]))
                ->assertOk()
                ->assertViewHas('paper', $paper)
                ->assertSeeText('KHATA STATEMENT')
                ->assertSeeText('Aslam');
        }
    }

    public function test_a_dated_statement_brings_the_earlier_balance_forward(): void
    {
        $customer = Customer::factory()->create();

        $this->khataWithPurchases($customer, [
            ['paisa' => 100_000, 'days_ago' => 40],
            ['paisa' => 250_000, 'days_ago' => 5],
        ]);

        $this->actingAs($this->owner)
            ->get(route('customers.statement', [
                'customer' => $customer,
                'from' => today()->subDays(10)->toDateString(),
            ]))
            ->assertOk()
            ->assertViewHas('broughtForward', 100_000)
            ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1);
    }

    public function test_a_cashier_may_read_a_khata_and_take_money_but_not_open_or_correct_one(): void
    {
        $customer = Customer::factory()->owing(300_000)->create();
        $this->openDrawer();

        $this->actingAs($this->cashier)->get(route('customers.index'))->assertOk();
        $this->actingAs($this->cashier)->get(route('customers.show', $customer))->assertOk();
        $this->actingAs($this->cashier)->get(route('customers.statement', $customer))->assertOk();

        $this->actingAs($this->cashier)
            ->post(route('customers.payments.store', $customer), [
                'amount' => '1000',
                'method' => TenderType::Cash->value,
                'register_id' => $this->register->id,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->cashier)->get(route('customers.create'))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('customers.edit', $customer))->assertForbidden();
        $this->actingAs($this->cashier)
            ->post(route('customers.adjustments.store', $customer), [
                'amount' => '500',
                'direction' => 'less',
                'note' => 'Trying it on',
            ])
            ->assertForbidden();
        $this->actingAs($this->cashier)
            ->post(route('customers.write-off', $customer), ['amount' => '500', 'note' => 'Trying it on'])
            ->assertForbidden();
        $this->actingAs($this->cashier)->delete(route('customers.destroy', $customer))->assertForbidden();

        $this->assertSame(200_000, $customer->fresh()->balance_paisa);
    }

    public function test_hiding_a_customer_keeps_every_line_of_their_khata(): void
    {
        $customer = Customer::factory()->create();
        $this->khataWithPurchases($customer, [['paisa' => 300_000, 'days_ago' => 3]]);

        $this->actingAs($this->owner)
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertFalse($customer->fresh()->is_active);
        $this->assertSame(1, $customer->ledgerEntries()->count());
        $this->assertSame(300_000, $customer->fresh()->balance_paisa);
    }

    public function test_the_reminder_says_what_is_owed_and_how_late_it_is(): void
    {
        Setting::write('shop.name', 'Tunio Super Mart');
        Setting::write('shop.phone', '0300 1112222');

        $customer = Customer::factory()->create(['name' => 'Aslam', 'phone' => '0300 1234567']);
        $this->khataWithPurchases($customer, [['paisa' => 300_000, 'days_ago' => 45]]);

        $response = $this->actingAs($this->owner)->get(route('customers.reminder', $customer));

        $response->assertOk()->assertViewHas('whatsapp', '923001234567');

        $message = $response->viewData('message');

        $this->assertStringContainsString('Aslam', $message);
        $this->assertStringContainsString('Rs. 3,000.00', $message);
        $this->assertStringContainsString('Tunio Super Mart', $message);
        $this->assertStringContainsString('0300 1112222', $message);
    }

    public function test_a_pakistani_number_is_turned_into_one_whatsapp_can_dial(): void
    {
        $this->assertSame('923001234567', KhataReminder::whatsappNumber('0300 1234567'));
        $this->assertSame('923001234567', KhataReminder::whatsappNumber('+92 300 1234567'));
        $this->assertSame('923001234567', KhataReminder::whatsappNumber('0092-300-1234567'));
        $this->assertSame('923001234567', KhataReminder::whatsappNumber('3001234567'));
        $this->assertNull(KhataReminder::whatsappNumber('1234'));
        $this->assertNull(KhataReminder::whatsappNumber(null));
    }

    /**
     * The cache is only a convenience; the lines are the truth. If they ever
     * disagree, the lines win.
     */
    public function test_a_drifted_balance_is_rebuilt_from_the_lines(): void
    {
        $customer = Customer::factory()->create();
        $this->khataWithPurchases($customer, [['paisa' => 300_000, 'days_ago' => 2]]);

        $customer->forceFill(['balance_paisa' => 999_999])->save();

        $result = $this->khata->rebuild($customer);

        $this->assertTrue($result['drifted']);
        $this->assertSame(300_000, $result['balance_after']);
        $this->assertSame(300_000, $customer->fresh()->balance_paisa);
    }
}
