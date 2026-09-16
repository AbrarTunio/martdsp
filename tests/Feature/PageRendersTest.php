<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A shell smoke test. Every page shares one layout, one nav source and one
 * icon component, so a broken component would otherwise only show up in the
 * browser on whichever page happened to be opened first.
 */
class PageRendersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function ownerPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'sell' => ['/sell'],
            'products' => ['/products'],
            'product create' => ['/products/create'],
            'categories' => ['/products/categories'],
            'brands' => ['/products/brands'],
            'units' => ['/products/units'],
            'labels' => ['/products/labels'],
            'sales' => ['/sales'],
            'sale returns' => ['/sales/returns'],
            'stock' => ['/stock'],
            'going off' => ['/stock/expiry'],
            'stock takes' => ['/stock/takes'],
            'count stock' => ['/stock/takes/create'],
            'stock adjustments' => ['/stock/adjustments'],
            'stock adjustment create' => ['/stock/adjustments/create'],
            'purchases' => ['/purchases'],
            'purchase create' => ['/purchases/create'],
            'reorder list' => ['/purchases/suggestions'],
            'purchase returns' => ['/purchases/returns'],
            'purchase return create' => ['/purchases/returns/create'],
            'suppliers' => ['/suppliers'],
            'supplier create' => ['/suppliers/create'],
            'khata' => ['/khata'],
            'khata create' => ['/khata/create'],
            'khata aging' => ['/khata/aging'],
            'drawer' => ['/drawer'],
            'reports' => ['/reports'],
            'daily sales report' => ['/reports/daily-sales'],
            'profit report' => ['/reports/profit'],
            'stock value report' => ['/reports/stock-value'],
            'dead stock report' => ['/reports/dead-stock'],
            'cashier report' => ['/reports/cashiers'],
            'supplier report' => ['/reports/suppliers'],
            'daily sales print' => ['/reports/daily-sales/print'],
            'settings' => ['/settings'],
            'staff' => ['/settings/staff'],
            'staff create' => ['/settings/staff/create'],
            'counters' => ['/settings/registers'],
            'printers' => ['/settings/printers'],
            'ai insights' => ['/settings/ai'],
            'profile' => ['/profile'],
        ];
    }

    #[DataProvider('ownerPages')]
    public function test_an_owner_can_open_every_page(string $path): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get($path)
            ->assertOk();
    }

    public function test_a_cashier_can_open_the_pages_their_role_allows(): void
    {
        $cashier = User::factory()->cashier()->create();

        foreach (['/dashboard', '/sell', '/products', '/stock', '/khata', '/drawer', '/profile'] as $path) {
            $this->actingAs($cashier)->get($path)->assertOk();
        }
    }

    /**
     * Buying and supplier accounts are the owner's and manager's business.
     */
    public function test_a_cashier_cannot_open_purchases_or_suppliers(): void
    {
        $cashier = User::factory()->cashier()->create();

        foreach (['/purchases', '/purchases/create', '/purchases/suggestions', '/purchases/returns', '/suppliers'] as $path) {
            $this->actingAs($cashier)->get($path)->assertForbidden();
        }
    }

    public function test_the_staff_edit_screen_renders(): void
    {
        $staff = User::factory()->cashier()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get("/settings/staff/{$staff->id}/edit")
            ->assertOk()
            ->assertSee($staff->name);
    }

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in to the till');
    }

    public function test_the_root_url_goes_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    /**
     * A cashier must not see the links they cannot use.
     */
    public function test_the_nav_hides_what_a_cashier_may_not_open(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="'.route('settings.index').'"', false)
            ->assertDontSee('href="'.route('reports.index').'"', false)
            ->assertDontSee('href="'.route('purchases.index').'"', false)
            ->assertDontSee('href="'.route('suppliers.index').'"', false);
    }
}
