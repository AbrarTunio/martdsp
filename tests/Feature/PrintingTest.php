<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\StockService;
use App\Support\Printing\PrinterDrivers;
use App\Support\Printing\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Printing.
 *
 * No hardware is in the room, so the driver is swapped for one that keeps the
 * bytes it was handed. That is enough to check the two things that actually
 * go wrong in a shop: a slip laid out wider than the roll it is printed on,
 * and a cash drawer that pops when it should not — or stays shut when it
 * should. The third thing this pins down is that a jammed printer never,
 * under any circumstance, loses a sale.
 */
class PrintingTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnit $carton;

    private Register $register;

    private User $owner;

    private User $manager;

    private User $cashier;

    private FakePrinterDrivers $drivers;

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

        $this->drivers = new FakePrinterDrivers;
        $this->app->instance(PrinterDrivers::class, $this->drivers);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function counterPrinter(bool $withDrawer = true): Printer
    {
        $printer = Printer::factory()->windowsShare()->when($withDrawer, fn ($factory) => $factory->withDrawer())
            ->create(['name' => 'Front printer']);

        $this->register->update(['printer_id' => $printer->id]);
        $this->register->refresh();

        return $printer;
    }

    private function openDrawer(int $floatPaisa = 500_000): DrawerSession
    {
        return app(DrawerService::class)->open($this->register, $this->cashier, $floatPaisa, null);
    }

    /**
     * @param  list<array{method: TenderType, amount_paisa: int}>|null  $payments
     */
    private function sell(?array $payments = null, ?Customer $customer = null): TestResponse
    {
        $payments ??= [['method' => TenderType::Cash, 'amount_paisa' => 400_000]];

        return $this->actingAs($this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'customer_id' => $customer?->id,
            'lines' => [['product_unit_id' => $this->carton->id, 'qty' => '1']],
            'payments' => array_map(fn (array $payment): array => [
                'method' => $payment['method']->value,
                'amount_paisa' => $payment['amount_paisa'],
            ], $payments),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The slip itself
    |--------------------------------------------------------------------------
    */

    public function test_a_reprint_carries_the_shop_the_bill_and_the_total(): void
    {
        Setting::write('shop.name', 'Al Madina Super Mart');
        $printer = $this->counterPrinter();
        $this->openDrawer();

        $sale = $this->sell()->assertCreated();
        $saleId = $sale->json('sale.id');

        $this->drivers->forget();

        $this->actingAs($this->owner)
            ->from(route('sales.show', $saleId))
            ->post(route('sales.print', $saleId))
            ->assertRedirect(route('sales.show', $saleId));

        $slip = $this->drivers->lastFor($printer);

        $this->assertStringContainsString('Al Madina Super Mart', $slip);
        $this->assertStringContainsString($sale->json('sale.invoice'), $slip);
        $this->assertStringContainsString('Surf Excel', $slip);
        $this->assertStringContainsString('TOTAL', $slip);
        $this->assertStringContainsString('4,000.00', $slip);

        /* It woke the printer at the start and cut the paper at the end. */
        $this->assertStringStartsWith("\x1B@", $slip);
        $this->assertStringEndsWith("\x1DV\x01", $slip);
    }

    public function test_nothing_on_a_slip_is_ever_wider_than_the_roll_it_prints_on(): void
    {
        Setting::write('shop.name', 'Al Madina Super Mart, Gulshan-e-Iqbal Block 13-D');
        $this->openDrawer();
        $sale = $this->sell()->assertCreated();

        foreach (['80' => 42, '58' => 32] as $paper => $columns) {
            $printer = Printer::factory()->windowsShare()->create(['name' => 'Roll '.$paper, 'paper' => $paper]);
            $this->register->update(['printer_id' => $printer->id]);
            $this->register->refresh();

            $this->drivers->forget();

            $this->actingAs($this->owner)->post(route('sales.print', $sale->json('sale.id')));

            foreach ($this->drivers->linesFor($printer) as $line) {
                $this->assertLessThanOrEqual($columns, mb_strlen($line), sprintf(
                    'A %s mm slip has a line %d characters wide: %s', $paper, mb_strlen($line), $line,
                ));
            }
        }
    }

    public function test_a_khata_bill_says_what_is_still_owed(): void
    {
        $printer = $this->counterPrinter();
        $customer = Customer::factory()->create(['name' => 'Aslam']);
        $this->openDrawer();

        $sale = $this->sell([['method' => TenderType::Khata, 'amount_paisa' => 400_000]], $customer)->assertCreated();

        $this->drivers->forget();
        $this->actingAs($this->owner)->post(route('sales.print', $sale->json('sale.id')));

        $slip = $this->drivers->lastFor($printer);

        $this->assertStringContainsString('Aslam', $slip);
        $this->assertStringContainsString('On khata', $slip);
        $this->assertStringContainsString('They now owe', $slip);
        $this->assertStringContainsString('please keep this slip', $slip);
    }

    /*
    |--------------------------------------------------------------------------
    | The drawer
    |--------------------------------------------------------------------------
    */

    public function test_a_cash_sale_pops_the_drawer(): void
    {
        $printer = $this->counterPrinter();
        $this->openDrawer();

        $this->sell()->assertCreated();

        $this->assertStringContainsString("\x1Bp\x00", $this->drivers->lastFor($printer));
    }

    public function test_the_drawer_pin_the_till_is_wired_to_is_the_one_that_is_fired(): void
    {
        $printer = Printer::factory()->windowsShare()->withDrawer(1)->create(['name' => 'Odd wiring']);
        $this->register->update(['printer_id' => $printer->id]);
        $this->openDrawer();

        $this->sell()->assertCreated();

        $this->assertStringContainsString("\x1Bp\x01", $this->drivers->lastFor($printer));
    }

    public function test_a_card_sale_leaves_the_drawer_shut(): void
    {
        $printer = $this->counterPrinter();
        $this->openDrawer();

        $this->sell([['method' => TenderType::Card, 'amount_paisa' => 400_000]])->assertCreated();

        $this->assertSame([], $this->drivers->sentTo($printer));
    }

    public function test_a_shop_that_keeps_its_drawer_unlocked_can_switch_the_pop_off(): void
    {
        Setting::write('drawer.pulse_on_cash', false);
        $printer = $this->counterPrinter();
        $this->openDrawer();

        $this->sell()->assertCreated();

        $this->assertSame([], $this->drivers->sentTo($printer));
    }

    public function test_a_printer_with_no_drawer_wired_to_it_is_not_asked_to_pop_one(): void
    {
        $printer = $this->counterPrinter(withDrawer: false);
        $this->openDrawer();

        $this->sell()->assertCreated();

        $this->assertSame([], $this->drivers->sentTo($printer));
    }

    /*
    |--------------------------------------------------------------------------
    | Auto-print
    |--------------------------------------------------------------------------
    */

    public function test_the_slip_prints_by_itself_when_the_shop_asks_for_it(): void
    {
        Setting::write('receipt.auto_print', true);
        $printer = $this->counterPrinter();
        $this->openDrawer();

        $response = $this->sell()->assertCreated();

        $this->assertTrue($response->json('printed'));
        $this->assertStringContainsString('TOTAL', $this->drivers->lastFor($printer));
    }

    public function test_the_till_is_told_nothing_printed_so_the_browser_can_step_in(): void
    {
        $this->counterPrinter();
        $this->openDrawer();

        /* Auto-print is off by default: the drawer pops, no slip comes out. */
        $this->assertFalse($this->sell()->assertCreated()->json('printed'));
    }

    public function test_selling_from_a_phone_never_claims_the_counter_printed(): void
    {
        Setting::write('receipt.auto_print', true);
        $this->openDrawer();

        /* No printer is wired up at all, which is every phone's situation. */
        $this->assertFalse($this->sell()->assertCreated()->json('printed'));
    }

    public function test_a_jammed_printer_never_loses_the_sale(): void
    {
        Setting::write('receipt.auto_print', true);
        $printer = $this->counterPrinter();
        $this->drivers->failWith('The paper is out.');
        $this->openDrawer();

        $response = $this->sell()->assertCreated();

        $this->assertFalse($response->json('printed'));
        $this->assertDatabaseHas('sales', ['id' => $response->json('sale.id'), 'total_paisa' => 400_000]);
    }

    /*
    |--------------------------------------------------------------------------
    | The test print and the drawer button
    |--------------------------------------------------------------------------
    */

    public function test_the_test_print_proves_the_roll_width_and_the_drawer_together(): void
    {
        $printer = Printer::factory()->windowsShare()->withDrawer()->narrow()->create(['name' => 'Narrow one']);

        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.test', $printer))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.printers.index'));

        $slip = $this->drivers->lastFor($printer);

        $this->assertStringContainsString('TEST PRINT', $slip);
        $this->assertStringContainsString("\x1Bp\x00", $slip);

        foreach ($this->drivers->linesFor($printer) as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line));
        }
    }

    public function test_a_test_print_is_written_into_the_log(): void
    {
        $printer = Printer::factory()->network()->create(['name' => 'Back office']);

        $this->actingAs($this->owner)->post(route('settings.printers.test', $printer));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'print.test',
            'subject_id' => $printer->id,
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_a_printer_the_server_cannot_reach_says_so_instead_of_failing_quietly(): void
    {
        $printer = Printer::factory()->windowsShare()->create(['name' => 'Front printer']);
        $this->drivers->failWith('The network path was not found.');

        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.test', $printer))
            ->assertSessionHasErrors(['printer' => 'The network path was not found.']);
    }

    public function test_the_drawer_button_pops_the_drawer_on_its_own(): void
    {
        $printer = Printer::factory()->windowsShare()->withDrawer()->create(['name' => 'Front printer']);

        $this->actingAs($this->manager)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.drawer', $printer))
            ->assertSessionHasNoErrors();

        $this->assertSame(["\x1Bp\x00\x1E\xFF"], $this->drivers->sentTo($printer));
        $this->assertDatabaseHas('activity_logs', ['action' => 'open.drawer', 'subject_id' => $printer->id]);
    }

    public function test_the_drawer_button_refuses_a_printer_with_no_drawer_on_it(): void
    {
        $printer = Printer::factory()->windowsShare()->create(['name' => 'Front printer']);

        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.drawer', $printer))
            ->assertSessionHasErrors(['printer' => 'Front printer has no cash drawer wired to it.']);

        $this->assertSame([], $this->drivers->sentTo($printer));
    }

    public function test_a_cashier_cannot_open_the_drawer_from_settings(): void
    {
        $printer = Printer::factory()->windowsShare()->withDrawer()->create();

        $this->actingAs($this->cashier)->post(route('settings.printers.drawer', $printer))->assertForbidden();
        $this->actingAs($this->cashier)->post(route('settings.printers.test', $printer))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('settings.printers.index'))->assertForbidden();

        $this->assertSame([], $this->drivers->sentTo($printer));
    }

    /*
    |--------------------------------------------------------------------------
    | The shift report
    |--------------------------------------------------------------------------
    */

    public function test_an_open_drawer_prints_a_peek_and_a_closed_one_prints_the_end_of_sale(): void
    {
        $printer = $this->counterPrinter();
        $drawer = $this->openDrawer();
        $this->sell()->assertCreated();

        $this->drivers->forget();
        $this->actingAs($this->owner)->post(route('drawer.print', $drawer));

        $peek = $this->drivers->lastFor($printer);
        $this->assertStringContainsString('X-REPORT', $peek);
        $this->assertStringContainsString('peek, not the end of the sale', $peek);

        /* Rs. 5,000 float and one Rs. 4,000 sale, counted out in notes. */
        $this->actingAs($this->cashier)->post(route('drawer.close.store', $drawer), [
            'counts' => [5000 => 1, 1000 => 4],
            'left_in_drawer' => '5000',
        ])->assertSessionHasNoErrors();

        $this->drivers->forget();
        $this->actingAs($this->owner)->post(route('drawer.print', $drawer));

        $report = $this->drivers->lastFor($printer);
        $this->assertStringContainsString('Z-REPORT', $report);
        $this->assertStringContainsString('Counted', $report);
        $this->assertStringContainsString('Left for the next shift', $report);
    }

    public function test_a_cashier_counting_blind_is_not_handed_the_answer_on_paper(): void
    {
        $printer = $this->counterPrinter();
        $drawer = $this->openDrawer();
        $this->sell()->assertCreated();

        $this->actingAs($this->cashier)->post(route('drawer.print', $drawer))->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Should be in the drawer', $this->drivers->lastFor($printer));

        $this->drivers->forget();

        $this->actingAs($this->owner)->post(route('drawer.print', $drawer));
        $this->assertStringContainsString('Should be in the drawer', $this->drivers->lastFor($printer));
    }

    /*
    |--------------------------------------------------------------------------
    | Which printer a counter uses
    |--------------------------------------------------------------------------
    */

    public function test_a_single_till_shop_does_not_have_to_assign_its_only_printer(): void
    {
        $printer = Printer::factory()->windowsShare()->create(['name' => 'The printer']);

        /* Nobody has pointed the counter at anything, and nobody should have to. */
        $this->assertNull($this->register->printer_id);
        $this->assertTrue($printer->is(Printer::forRegister($this->register)));
    }

    public function test_a_second_printer_makes_the_shop_say_which_counter_uses_which(): void
    {
        Printer::factory()->windowsShare()->create(['name' => 'Front printer']);
        Printer::factory()->network()->create(['name' => 'Back printer']);

        $this->assertNull(Printer::forRegister($this->register->fresh()));
    }

    public function test_a_counter_pointed_at_a_browser_printer_prints_through_the_browser(): void
    {
        $browser = Printer::factory()->create(['name' => 'Phone and browser']);
        $this->register->update(['printer_id' => $browser->id]);

        $this->assertNull(Printer::forRegister($this->register->fresh()));
    }

    public function test_a_switched_off_printer_is_not_used_behind_the_shops_back(): void
    {
        $printer = Printer::factory()->windowsShare()->inactive()->create(['name' => 'Retired']);
        $this->register->update(['printer_id' => $printer->id]);

        $this->assertNull(Printer::forRegister($this->register->fresh()));
    }

    public function test_a_counter_with_no_printer_says_so_rather_than_pretending_to_print(): void
    {
        $this->openDrawer();
        $saleId = $this->sell()->assertCreated()->json('sale.id');

        $this->actingAs($this->owner)
            ->from(route('sales.show', $saleId))
            ->post(route('sales.print', $saleId))
            ->assertSessionHasErrors(['printer' => 'No counter printer is set up, so this can only be printed from the browser.']);
    }

    public function test_the_counter_decides_the_roll_width_from_its_printer(): void
    {
        $printer = Printer::factory()->windowsShare()->narrow()->create();
        $this->register->update(['printer_id' => $printer->id, 'printer_profile' => null]);

        $this->assertSame('58', $this->register->fresh()->paper());

        /* A counter told to use a different roll still wins. */
        $this->register->update(['printer_profile' => '80']);
        $this->assertSame('80', $this->register->fresh()->paper());
    }

    /*
    |--------------------------------------------------------------------------
    | Setting a printer up
    |--------------------------------------------------------------------------
    */

    public function test_the_owner_can_add_a_printer(): void
    {
        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.store'), [
                'name' => 'Front printer',
                'channel' => 'windows_share',
                'target' => '\\\\localhost\\THERMAL',
                'paper' => '80',
                'cuts' => '1',
                'has_drawer' => '1',
                'drawer_pin' => '0',
                'feed_lines' => '4',
            ])
            ->assertSessionHasNoErrors();

        $printer = Printer::query()->firstOrFail();

        $this->assertSame('Front printer', $printer->name);
        $this->assertTrue($printer->canPulse());
        $this->assertDatabaseHas('activity_logs', ['action' => 'printer.created', 'subject_id' => $printer->id]);
    }

    public function test_a_printer_the_server_has_to_reach_needs_an_address(): void
    {
        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.store'), [
                'name' => 'Front printer',
                'channel' => 'network',
                'target' => '',
                'paper' => '80',
            ])
            ->assertSessionHasErrors(['target' => 'This kind of printer needs an address before the shop can reach it.']);

        $this->assertDatabaseCount('printers', 0);
    }

    public function test_a_browser_printer_needs_no_address_at_all(): void
    {
        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->post(route('settings.printers.store'), [
                'name' => 'The browser',
                'channel' => 'browser',
                'paper' => '80',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('printers', 1);
    }

    public function test_the_printer_list_shows_what_each_one_is_and_where_it_is(): void
    {
        $printer = Printer::factory()->windowsShare()->withDrawer()->create(['name' => 'Front printer']);

        $this->actingAs($this->owner)
            ->get(route('settings.printers.index'))
            ->assertOk()
            ->assertSee('Front printer')
            ->assertSee('localhost\THERMAL', escape: false)
            ->assertSee(__('Test print'))
            ->assertSee(__('Open drawer'));

        $this->assertTrue($printer->canPulse());
    }

    public function test_a_printer_a_counter_still_points_at_is_switched_off_rather_than_removed(): void
    {
        $printer = $this->counterPrinter();

        $this->actingAs($this->owner)
            ->from(route('settings.printers.index'))
            ->delete(route('settings.printers.destroy', $printer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($printer->fresh()->is_active);
        $this->assertSame($printer->id, $this->register->fresh()->printer_id);
    }

    public function test_a_printer_nobody_uses_is_removed_outright(): void
    {
        $printer = Printer::factory()->windowsShare()->create();

        $this->actingAs($this->owner)->delete(route('settings.printers.destroy', $printer));

        $this->assertDatabaseCount('printers', 0);
    }

    public function test_the_shop_can_turn_auto_printing_and_the_drawer_pop_on_from_settings(): void
    {
        $this->actingAs($this->owner)->put(route('settings.update'), [
            'settings' => [
                'shop.name' => 'Al Madina Super Mart',
                'tax.gst_rate' => '18',
                'receipt.paper_width' => '80',
                'receipt.auto_print' => '1',
                'sales.cashier_discount_limit' => '10',
                'khata.credit_days' => '30',
                'drawer.variance_tolerance' => '100',
                'drawer.pulse_on_cash' => '1',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) Setting::read('receipt.auto_print'));
        $this->assertTrue((bool) Setting::read('drawer.pulse_on_cash'));
    }

    /*
    |--------------------------------------------------------------------------
    | The drivers themselves
    |--------------------------------------------------------------------------
    */

    public function test_the_shop_is_told_plainly_when_a_printer_cannot_be_driven(): void
    {
        $drivers = new PrinterDrivers;

        $this->assertThrows(
            fn () => $drivers->for(Printer::factory()->create(['name' => 'The browser'])),
            RuntimeException::class,
            'The browser prints through the browser, so the server cannot send to it.',
        );

        $this->assertThrows(
            fn () => $drivers->for(Printer::factory()->windowsShare()->inactive()->create(['name' => 'Retired'])),
            RuntimeException::class,
            'Retired is switched off.',
        );

        $this->assertThrows(
            fn () => $drivers->for(Printer::factory()->network('')->create(['name' => 'Half set up'])),
            RuntimeException::class,
        );

        $this->assertThrows(
            fn () => $drivers->for(Printer::factory()->network('192.168.1.50:70000')->create()),
            RuntimeException::class,
        );
    }

    public function test_a_direct_printer_gets_a_driver_that_says_where_it_is_going(): void
    {
        $drivers = new PrinterDrivers;

        $this->assertStringContainsString(
            '192.168.1.50:9100',
            $drivers->for(Printer::factory()->network()->create())->describe(),
        );
    }
}

/**
 * A printer that exists only in memory.
 *
 * Every byte the shop would have sent is kept, so a test can read the slip
 * the same way a shopkeeper would read the paper coming out of the machine.
 */
class FakePrinterDrivers extends PrinterDrivers
{
    /** @var array<int, list<string>> */
    public array $jobs = [];

    private ?string $failure = null;

    public function for(Printer $printer): ReceiptPrinter
    {
        return new FakeReceiptPrinter($printer, $this);
    }

    public function failWith(?string $reason): void
    {
        $this->failure = $reason;
    }

    public function forget(): void
    {
        $this->jobs = [];
    }

    /**
     * @throws RuntimeException when the fake has been told the printer is down
     */
    public function accept(Printer $printer, string $bytes): void
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        $this->jobs[$printer->id][] = $bytes;
    }

    /**
     * @return list<string>
     */
    public function sentTo(Printer $printer): array
    {
        return $this->jobs[$printer->id] ?? [];
    }

    public function lastFor(Printer $printer): string
    {
        $jobs = $this->sentTo($printer);

        if ($jobs === []) {
            throw new RuntimeException('Nothing was sent to '.$printer->name.'.');
        }

        return (string) end($jobs);
    }

    /**
     * The slip as rows of text, with the escape codes taken back out.
     *
     * @return list<string>
     */
    public function linesFor(Printer $printer): array
    {
        $text = preg_replace(
            /* `ESC @` wakes the printer and takes no argument; the rest take one. */
            ['/\x1B@/s', '/\x1B[EadHt\-]./s', '/\x1D[!V]./s', '/\x1Bp.../s'],
            '',
            $this->lastFor($printer),
        ) ?? '';

        return array_values(array_filter(explode("\n", $text), fn (string $line): bool => $line !== ''));
    }
}

class FakeReceiptPrinter implements ReceiptPrinter
{
    public function __construct(
        private readonly Printer $printer,
        private readonly FakePrinterDrivers $drivers,
    ) {}

    public function send(string $bytes): void
    {
        $this->drivers->accept($this->printer, $bytes);
    }

    public function describe(): string
    {
        return $this->printer->describe();
    }
}
