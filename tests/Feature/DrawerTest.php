<?php

namespace Tests\Feature;

use App\Enums\DrawerEntryType;
use App\Enums\DrawerStatus;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A counter's cash drawer, shift by shift.
 *
 * It opens with a float, every cash sale and cancelled sale moves it, pay-ins,
 * pay-outs and safe drops are typed in with a name on them, and the shift ends
 * with a note-by-note count against what the ledger says should be there.
 */
class DrawerTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnit $carton;

    private Register $register;

    private User $owner;

    private User $manager;

    private User $cashier;

    private DrawerService $drawers;

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
        $this->manager = User::factory()->manager()->create(['name' => 'Mehmood']);
        $this->cashier = User::factory()->cashier()->create(['name' => 'Sana']);
        $this->drawers = app(DrawerService::class);
    }

    private function openDrawer(?User $as = null, int $floatPaisa = 500_000, ?Register $register = null): DrawerSession
    {
        return $this->drawers->open($register ?? $this->register, $as ?? $this->cashier, $floatPaisa, null);
    }

    /**
     * One carton rung up at the front counter.
     *
     * @param  list<array{method: string, amount_paisa: int, reference?: string}>  $payments
     */
    private function sellACarton(array $payments, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'lines' => [['product_unit_id' => $this->carton->id, 'qty' => '1']],
            'payments' => $payments,
        ]);
    }

    /**
     * @return list<array{method: string, amount_paisa: int}>
     */
    private function cash(int $paisa): array
    {
        return [['method' => TenderType::Cash->value, 'amount_paisa' => $paisa]];
    }

    /**
     * @param  array<int, int>  $counts
     */
    private function closeDrawer(DrawerSession $session, array $counts, string $left, ?string $reason = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->post(route('drawer.close.store', $session), [
            'counts' => $counts,
            'left_in_drawer' => $left,
            'reason' => $reason,
        ]);
    }

    public function test_a_shift_opens_with_its_float_and_a_counter_cannot_be_opened_twice(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('drawer.open'), ['register_id' => $this->register->id, 'float' => '5000', 'note' => 'Counted twice'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('drawer.show', DrawerSession::query()->sole()));

        $session = DrawerSession::query()->sole();

        $this->assertSame(DrawerStatus::Open, $session->status);
        $this->assertSame($this->cashier->id, $session->opened_by);
        $this->assertSame(500_000, $session->opening_float_paisa);
        $this->assertSame('Counted twice', $session->opening_note);
        $this->assertSame(500_000, $session->expectedCashPaisa());

        $float = DrawerTransaction::query()->sole();
        $this->assertSame(DrawerEntryType::OpeningFloat, $float->type);
        $this->assertSame(500_000, $float->amount_paisa);

        $this->actingAs($this->manager)
            ->post(route('drawer.open'), ['register_id' => $this->register->id, 'float' => '1000'])
            ->assertSessionHasErrors('float');

        $this->assertSame(1, DrawerSession::query()->count());
    }

    public function test_a_switched_off_counter_cannot_be_opened(): void
    {
        $off = Register::factory()->inactive()->create();

        $this->actingAs($this->cashier)
            ->post(route('drawer.open'), ['register_id' => $off->id, 'float' => '5000'])
            ->assertSessionHasErrors('register_id');

        $this->assertSame(0, DrawerSession::query()->count());
    }

    public function test_the_till_asks_for_the_drawer_first_and_goes_straight_back_to_selling(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertViewIs('pos.open-drawer')
            ->assertViewHas('suggestedFloatPaisa', 0);

        $this->actingAs($this->cashier)
            ->post(route('drawer.open'), ['register_id' => $this->register->id, 'float' => '5000', 'back_to' => 'pos'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('pos.register_id', $this->register->id);

        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertViewIs('pos.index');
    }

    public function test_nothing_can_be_sold_until_the_drawer_is_open(): void
    {
        $response = $this->sellACarton($this->cash(400_000));

        $response->assertUnprocessable();
        $this->assertStringContainsString('Open the drawer at Front before selling', $response->json('message'));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_cash_goes_in_net_of_change_and_card_money_does_not(): void
    {
        $session = $this->openDrawer();

        /* Rs. 5,000 handed over for a Rs. 4,000 carton: Rs. 1,000 went straight back. */
        $this->sellACarton($this->cash(500_000))->assertCreated();

        $this->sellACarton([
            ['method' => TenderType::Card->value, 'amount_paisa' => 100_000, 'reference' => 'TX-1'],
            ['method' => TenderType::Cash->value, 'amount_paisa' => 300_000],
        ])->assertCreated();

        $this->sellACarton([
            ['method' => TenderType::Card->value, 'amount_paisa' => 400_000, 'reference' => 'TX-2'],
        ])->assertCreated();

        $cashSales = DrawerTransaction::query()->where('type', DrawerEntryType::CashSale)->orderBy('id')->get();

        $this->assertSame([400_000, 300_000], $cashSales->pluck('amount_paisa')->all());
        $this->assertSame(1_200_000, $session->expectedCashPaisa());

        $sale = Sale::query()->orderBy('id')->first();
        $this->assertSame($session->id, $sale->drawer_session_id);
        $this->assertTrue($cashSales->first()->reference->is($sale));
        $this->assertSame(3, $session->sales()->count());
    }

    public function test_a_cancelled_bill_hands_its_cash_back_out_of_the_drawer(): void
    {
        $session = $this->openDrawer();
        $this->sellACarton($this->cash(400_000))->assertCreated();
        $sale = Sale::query()->sole();

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Scanned twice'])
            ->assertSessionHasNoErrors();

        $void = DrawerTransaction::query()->where('type', DrawerEntryType::SaleVoid)->sole();

        $this->assertSame(-400_000, $void->amount_paisa);
        $this->assertSame($this->manager->id, $void->user_id);
        $this->assertSame(500_000, $session->expectedCashPaisa());
    }

    public function test_a_cash_bill_cannot_be_cancelled_while_its_drawer_is_closed(): void
    {
        $session = $this->openDrawer();
        $this->sellACarton($this->cash(400_000))->assertCreated();
        $sale = Sale::query()->sole();

        $this->drawers->close($session, $this->cashier, [5000 => 1, 1000 => 4], 500_000);

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Scanned twice'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(0, DrawerTransaction::query()->where('type', DrawerEntryType::SaleVoid)->count());
    }

    public function test_cash_added_paid_out_and_emptied_is_kept_with_who_did_it(): void
    {
        $session = $this->openDrawer();

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'pay_in', 'amount' => '1000', 'note' => 'Change from the safe'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('drawer.show', $session));

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'pay_out', 'amount' => '250', 'note' => 'Tea'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('drawer.movements.store', $session), ['type' => 'safe_drop', 'amount' => '3000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(500_000 + 100_000 - 25_000 - 300_000, $session->expectedCashPaisa());

        $payOut = DrawerTransaction::query()->where('type', DrawerEntryType::PayOut)->sole();
        $this->assertSame(-25_000, $payOut->amount_paisa);
        $this->assertSame('Tea', $payOut->note);
        $this->assertSame($this->cashier->id, $payOut->user_id);

        $drop = DrawerTransaction::query()->where('type', DrawerEntryType::SafeDrop)->sole();
        $this->assertSame(-300_000, $drop->amount_paisa);
        $this->assertSame($this->manager->id, $drop->user_id);

        $this->actingAs($this->manager)
            ->get(route('drawer.show', $session))
            ->assertOk()
            ->assertSee('Mehmood')
            ->assertSee('emptied', false)
            ->assertSee('Tea');
    }

    public function test_a_pay_out_has_to_say_what_it_was_for(): void
    {
        $session = $this->openDrawer();

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'pay_out', 'amount' => '250'])
            ->assertSessionHasErrors('note');

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'cash_sale', 'amount' => '250'])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, DrawerTransaction::query()->count());
    }

    public function test_nobody_can_take_out_more_than_the_drawer_should_hold(): void
    {
        $session = $this->openDrawer();

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'safe_drop', 'amount' => '6000'])
            ->assertSessionHasErrors('amount');

        $this->assertStringNotContainsString('5,000', session('errors')->first('amount'), 'A blind cashier is not told the answer');

        $this->actingAs($this->manager)
            ->post(route('drawer.movements.store', $session), ['type' => 'safe_drop', 'amount' => '6000'])
            ->assertSessionHasErrors('amount');

        $this->assertStringContainsString('should only hold', session('errors')->first('amount'));
        $this->assertSame(500_000, $session->expectedCashPaisa());
    }

    public function test_the_close_counts_note_by_note_and_the_next_shift_is_offered_what_was_left(): void
    {
        $session = $this->openDrawer();
        $this->sellACarton($this->cash(400_000))->assertCreated();

        $this->actingAs($this->cashier)
            ->get(route('drawer.close.create', $session))
            ->assertOk()
            ->assertSee('Rs. 5,000');

        /* 5,000 + 3 × 1,000 + 500 + 5 × 100 = Rs. 9,000, exactly what it should hold. */
        $this->closeDrawer($session, [5000 => 1, 1000 => 3, 500 => 1, 100 => 5, 50 => 0], '5000')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('drawer.show', $session));

        $session->refresh();

        $this->assertSame(DrawerStatus::Closed, $session->status);
        $this->assertNull($session->open_register_id);
        $this->assertSame($this->cashier->id, $session->closed_by);
        $this->assertSame(900_000, $session->expected_cash_paisa);
        $this->assertSame(900_000, $session->counted_cash_paisa);
        $this->assertSame(0, $session->variance_paisa);
        $this->assertFalse($session->needs_approval);
        $this->assertSame(500_000, $session->left_in_drawer_paisa);
        $this->assertSame(400_000, $session->takenAtClosePaisa());

        $this->assertSame([5000, 1000, 500, 100], $session->countLines->pluck('denomination')->all());
        $this->assertSame(300_000, $session->countLines->firstWhere('denomination', 1000)->subtotal_paisa);

        $this->assertSame(500_000, $this->drawers->suggestedFloatPaisa($this->register));

        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertViewIs('pos.open-drawer')
            ->assertViewHas('suggestedFloatPaisa', 500_000);

        $this->actingAs($this->cashier)
            ->get(route('drawer.close.create', $session))
            ->assertRedirect(route('drawer.show', $session));
    }

    public function test_a_closed_drawer_takes_no_more_cash_and_cannot_be_closed_again(): void
    {
        $session = $this->openDrawer();
        $this->drawers->close($session, $this->cashier, [5000 => 1], 500_000);

        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $session), ['type' => 'pay_in', 'amount' => '100'])
            ->assertSessionHasErrors('amount');

        $this->closeDrawer($session, [5000 => 1], '5000')->assertSessionHasErrors('reason');

        $this->assertSame(1, $session->countLines()->count());
    }

    public function test_the_count_cannot_have_a_note_that_does_not_exist_or_leave_more_than_was_counted(): void
    {
        $session = $this->openDrawer();

        $this->closeDrawer($session, [3 => 1, 5000 => 1], '5000')->assertSessionHasErrors('counts');
        $this->closeDrawer($session, [1000 => 2], '5000', 'Short')->assertSessionHasErrors('reason');

        $this->assertTrue($session->fresh()->isOpen());
    }

    public function test_a_small_gap_is_only_change_making(): void
    {
        $session = $this->openDrawer();

        /* Rs. 50 short, inside the Rs. 100 allowed. */
        $this->closeDrawer($session, [100 => 49, 50 => 1], '4950')->assertSessionHasNoErrors();

        $session->refresh();

        $this->assertSame(-5_000, $session->variance_paisa);
        $this->assertFalse($session->needs_approval);
        $this->assertFalse($session->isAwaitingApproval());
    }

    public function test_a_big_gap_needs_a_reason_and_a_managers_sign_off(): void
    {
        $session = $this->openDrawer();

        $this->closeDrawer($session, [1000 => 4], '4000')->assertSessionHasErrors('reason');
        $this->assertTrue($session->fresh()->isOpen());

        $this->closeDrawer($session, [1000 => 4], '4000', 'Gave Rs. 1,000 change twice')->assertSessionHasNoErrors();

        $session->refresh();

        $this->assertSame(-100_000, $session->variance_paisa);
        $this->assertTrue($session->needs_approval);
        $this->assertTrue($session->isAwaitingApproval());
        $this->assertSame('Gave Rs. 1,000 change twice', $session->variance_reason);

        $this->actingAs($this->cashier)
            ->post(route('drawer.approve', $session), ['note' => 'Fine'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('drawer.index'))
            ->assertOk()
            ->assertSee('Counts waiting for you')
            ->assertViewHas('waiting', fn ($waiting): bool => $waiting->contains($session));

        $this->actingAs($this->manager)
            ->post(route('drawer.approve', $session), ['note' => 'Sana will make it up'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('drawer.show', $session));

        $session->refresh();

        $this->assertFalse($session->isAwaitingApproval());
        $this->assertSame($this->manager->id, $session->approved_by);
        $this->assertSame('Sana will make it up', $session->approval_note);

        $this->actingAs($this->manager)
            ->post(route('drawer.approve', $session))
            ->assertSessionHasErrors('note');
    }

    public function test_a_manager_who_closes_with_a_big_gap_signs_it_off_themselves(): void
    {
        $session = $this->openDrawer($this->manager);

        $this->closeDrawer($session, [1000 => 4], '4000', 'Paid the milkman', $this->manager)->assertSessionHasNoErrors();

        $session->refresh();

        $this->assertTrue($session->needs_approval);
        $this->assertSame($this->manager->id, $session->approved_by);
        $this->assertFalse($session->isAwaitingApproval());
    }

    public function test_a_cashier_counts_blind(): void
    {
        $session = $this->openDrawer();
        $this->sellACarton($this->cash(400_000))->assertCreated();

        $this->actingAs($this->cashier)
            ->get(route('drawer.show', $session))
            ->assertOk()
            ->assertViewHas('seesExpected', false)
            ->assertDontSee('Should be in the drawer')
            ->assertDontSee('INV-000001');

        $this->actingAs($this->cashier)
            ->get(route('drawer.close.create', $session))
            ->assertOk()
            ->assertViewHas('config', fn (array $config): bool => $config['expectedPaisa'] === null)
            ->assertDontSee('Should be in the drawer');

        $this->actingAs($this->cashier)
            ->get(route('drawer.report', $session))
            ->assertOk()
            ->assertDontSee('Total sold');

        $this->actingAs($this->manager)
            ->get(route('drawer.show', $session))
            ->assertOk()
            ->assertSee('Should be in the drawer')
            ->assertSee('INV-000001');

        Setting::write('drawer.blind_count', false, 'drawer');

        $this->actingAs($this->cashier)
            ->get(route('drawer.close.create', $session))
            ->assertViewHas('config', fn (array $config): bool => $config['expectedPaisa'] === 900_000)
            ->assertSee('Should be in the drawer');
    }

    public function test_one_screen_tells_the_whole_story_of_a_shift(): void
    {
        $first = $this->openDrawer();
        $this->drawers->move($first, $this->manager, DrawerEntryType::SafeDrop, 200_000, null);
        $this->drawers->close($first, $this->cashier, [1000 => 3], 300_000 - 50_000);

        $evening = User::factory()->cashier()->create(['name' => 'Kashif']);
        $second = $this->openDrawer($evening, 200_000);

        $this->actingAs($this->manager)
            ->get(route('drawer.show', $first))
            ->assertOk()
            ->assertSee('Sana')
            ->assertSee('emptied', false)
            ->assertSee('Mehmood')
            ->assertSee('The next shift')
            ->assertSee('Kashif')
            ->assertSee('different from what this shift left behind');

        $this->actingAs($this->manager)
            ->get(route('drawer.show', $second))
            ->assertOk()
            ->assertSee('The shift before')
            ->assertSee('less than the last shift left');
    }

    public function test_the_x_and_z_reports_print_on_every_paper(): void
    {
        $session = $this->openDrawer();
        $this->sellACarton($this->cash(400_000))->assertCreated();

        foreach (['80', '58', 'a4'] as $paper) {
            $this->actingAs($this->manager)
                ->get(route('drawer.report', ['drawer' => $session, 'paper' => $paper]))
                ->assertOk()
                ->assertViewHas('paper', $paper)
                ->assertSee('X-REPORT')
                ->assertSee('Total sold');
        }

        $this->drawers->close($session, $this->cashier, [5000 => 1, 1000 => 4], 500_000);

        $this->actingAs($this->manager)
            ->get(route('drawer.report', ['drawer' => $session, 'paper' => 'a4', 'print' => 1]))
            ->assertOk()
            ->assertSee('Z-REPORT')
            ->assertSee('Left for next shift')
            ->assertSee('window.print()', false);

        $this->actingAs($this->manager)
            ->get(route('drawer.report', ['drawer' => $session, 'paper' => 'letter']))
            ->assertViewHas('paper', '80');
    }

    public function test_the_drawers_page_shows_each_counter_and_every_shift(): void
    {
        $back = Register::factory()->create(['name' => 'Back']);
        $this->openDrawer();

        $this->actingAs($this->manager)
            ->get(route('drawer.index'))
            ->assertOk()
            ->assertSee('Front')
            ->assertSee('Back')
            ->assertSee('Count & close')
            ->assertViewHas('expected', fn ($expected): bool => $expected->get($this->register->id) === 500_000)
            ->assertViewHas('suggestedFloats', fn ($floats): bool => $floats->has($back->id));

        $this->actingAs($this->manager)
            ->get(route('drawer.index', ['status' => 'closed']))
            ->assertViewHas('history', fn (LengthAwarePaginator $history): bool => $history->total() === 0);

        $this->actingAs($this->manager)
            ->get(route('drawer.index', ['status' => 'open', 'register' => $this->register->id, 'date' => now()->format('Y-m-d')]))
            ->assertViewHas('history', fn (LengthAwarePaginator $history): bool => $history->total() === 1);

        $this->actingAs($this->cashier)
            ->get(route('drawer.index'))
            ->assertOk()
            ->assertViewHas('expected', fn ($expected): bool => $expected->isEmpty());
    }

    public function test_a_cashier_sees_only_their_own_shifts_once_they_are_closed(): void
    {
        $other = User::factory()->cashier()->create();
        $theirs = DrawerSession::factory()->closed()->create(['register_id' => $this->register->id, 'opened_by' => $other->id]);
        $mine = DrawerSession::factory()->closed()->create(['register_id' => $this->register->id, 'opened_by' => $this->cashier->id]);

        $this->actingAs($this->cashier)
            ->get(route('drawer.index'))
            ->assertViewHas('history', fn (LengthAwarePaginator $history): bool => $history->pluck('id')->all() === [$mine->id]);

        $this->actingAs($this->cashier)->get(route('drawer.show', $theirs))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('drawer.report', $theirs))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('drawer.show', $mine))->assertOk();
        $this->actingAs($this->manager)->get(route('drawer.show', $theirs))->assertOk();

        /* A drawer still running is the counter's, whoever opened it. */
        $running = $this->openDrawer($other);
        $this->actingAs($this->cashier)->get(route('drawer.show', $running))->assertOk();
    }
}
