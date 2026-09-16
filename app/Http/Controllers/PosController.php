<?php

namespace App\Http\Controllers;

use App\Enums\TenderType;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\DrawerService;
use App\Services\PricesChangedException;
use App\Services\PrintService;
use App\Services\SaleService;
use App\Services\ScanService;
use App\Support\Money;
use App\Support\PosItem;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The till.
 *
 * The page is one Alpine component that holds the basket in the browser, so
 * scanning never waits on the network for anything but the lookup. The basket
 * only reaches the server when it is paid for or put on hold.
 */
class PosController extends Controller
{
    public const SESSION_KEY = 'pos.register_id';

    public function __construct(
        private readonly SaleService $sales,
        private readonly PrintService $printing,
    ) {}

    public function index(Request $request, DrawerService $drawers): View
    {
        Register::ensureOne();

        $registers = Register::query()->active()->get();
        $register = $this->currentRegister($request, $registers);

        if (! $register) {
            return view('pos.choose-register', ['registers' => $registers]);
        }

        if (! $register->openDrawer) {
            return view('pos.open-drawer', [
                'register' => $register,
                'registers' => $registers,
                'suggestedFloatPaisa' => $drawers->suggestedFloatPaisa($register),
            ]);
        }

        $user = $request->user();

        return view('pos.index', [
            'register' => $register,
            'registers' => $registers,
            'drawer' => $register->openDrawer,
            'config' => [
                'userId' => $user->id,
                'register' => ['id' => $register->id, 'name' => $register->name],
                'paper' => $register->paper(),
                'supervises' => $user->supervises(),
                'pricesIncludeTax' => (bool) Setting::read('tax.prices_include_tax'),
                'roundToRupee' => (bool) Setting::read('sales.round_to_rupee'),
                'allowNegativeStock' => (bool) Setting::read('sales.allow_negative_stock'),
                'discountLimit' => (float) Setting::read('sales.cashier_discount_limit'),
                'tenders' => collect(TenderType::cases())
                    ->map(fn (TenderType $tender): array => [
                        'value' => $tender->value,
                        'label' => __($tender->label()),
                        'needs_customer' => $tender->needsCustomer(),
                        'is_cash' => $tender->isCash(),
                    ])
                    ->all(),
                'heldCount' => Sale::query()->held()->count(),
                'urls' => [
                    'lookup' => route('pos.lookup'),
                    'search' => route('pos.search'),
                    'store' => route('pos.store'),
                    'held' => route('pos.held.index'),
                    'hold' => route('pos.held.store'),
                    'resume' => route('pos.held.resume', ['sale' => '__ID__']),
                    'discard' => route('pos.held.destroy', ['sale' => '__ID__']),
                    'customers' => route('pos.customers.index'),
                    'addCustomer' => route('pos.customers.store'),
                    'receipt' => route('sales.receipt', ['sale' => '__ID__']),
                    'sale' => route('sales.show', ['sale' => '__ID__']),
                    'drawer' => route('drawer.show', $register->openDrawer),
                ],
            ],
        ]);
    }

    /**
     * Which counter this phone or PC is standing at. Remembered for the
     * session, and sent with every sale so two tabs cannot disagree.
     */
    public function chooseRegister(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'register_id' => ['required', 'integer', Rule::exists('registers', 'id')->where('is_active', true)],
        ]);

        $request->session()->put(self::SESSION_KEY, (int) $validated['register_id']);

        return redirect()->route('pos.index');
    }

    /**
     * One scan. A barcode names an exact size; failing that, a typed SKU or
     * name finds the product at its usual selling size.
     */
    public function lookup(Request $request, ScanService $scanner): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return response()->json(['found' => false, 'code' => ''], 404);
        }

        $productUnit = $scanner->resolve($code);

        if ($productUnit && $productUnit->product?->is_active) {
            return response()->json(['found' => true, 'item' => PosItem::fromProductUnit($productUnit)]);
        }

        if ($productUnit) {
            return response()->json([
                'found' => false,
                'code' => $code,
                'message' => __(':name has been switched off and cannot be sold.', ['name' => $productUnit->product?->name]),
            ], 404);
        }

        $product = Product::query()
            ->active()
            ->where('sku', $code)
            ->with(['baseUnit', 'productUnits.unit'])
            ->first();

        if (! $product && ! $this->looksLikeABarcode($code)) {
            $matches = $scanner->search($code, 2);
            $product = $matches->count() === 1 ? $matches->first() : null;
        }

        if (! $product) {
            return response()->json([
                'found' => false,
                'code' => $code,
                'search' => ! $this->looksLikeABarcode($code),
                'message' => $this->looksLikeABarcode($code)
                    ? __('No item has the barcode :code.', ['code' => $code])
                    : __('Nothing matches ":code". Try searching.', ['code' => $code]),
            ], 404);
        }

        return response()->json(['found' => true, 'item' => PosItem::fromProduct($product)]);
    }

    /**
     * For loose items and torn labels.
     */
    public function search(Request $request, ScanService $scanner): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['items' => []]);
        }

        return response()->json([
            'items' => $scanner->search($term, 15)
                ->map(fn (Product $product): array => PosItem::fromProduct($product))
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        try {
            $sale = $this->sales->complete(
                cashier: $request->user(),
                register: $request->register(),
                lines: $request->lines(),
                payments: $request->payments(),
                customer: $request->customer(),
                billDiscount: $request->billDiscount(),
                expectedTotalPaisa: $request->expectedTotalPaisa(),
                note: $request->note(),
                offlineUid: $request->offlineUid(),
                rungUpAt: $request->rungUpAt(),
            );
        } catch (PricesChangedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'repriced' => [
                    'prices' => $exception->prices,
                    'total_paisa' => $exception->totalPaisa,
                ],
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        /* The slip and the drawer are the shop's business, not the sale's: if
           the printer is jammed the bill still stands, and the till says so
           by sending the browser to print instead. */
        $printed = $this->atTheCounter($sale)
            && $this->printing->afterSale($sale, $request->tookCash());

        return response()->json([
            'message' => __(':invoice is complete.', ['invoice' => $sale->invoiceNumber()]),
            'sale' => [
                'id' => $sale->id,
                'invoice' => $sale->invoiceNumber(),
                'total_paisa' => $sale->total_paisa,
                'paid_paisa' => $sale->paid_paisa,
                'change_paisa' => $sale->change_given_paisa,
                'due_paisa' => $sale->due_paisa,
                'customer' => $sale->customer?->displayName(),
                'customer_balance' => $sale->customer ? Money::withSymbol($sale->customer->fresh()->balance_paisa) : null,
            ],
            'receipt_url' => route('sales.receipt', $sale),
            'printed' => $printed,
        ], 201);
    }

    /**
     * Whether the customer is still standing there.
     *
     * A bill that was rung up while the line was down reaches the server long
     * after the money changed hands, and a bill sent twice was already dealt
     * with the first time. Neither should make the drawer jump or push a slip
     * out of a printer nobody is watching.
     */
    private function atTheCounter(Sale $sale): bool
    {
        if (! $sale->wasRecentlyCreated) {
            return false;
        }

        return $sale->offline_rung_at === null
            || $sale->offline_rung_at->greaterThan(now()->subMinutes(2));
    }

    /**
     * The remembered counter, or the only one there is to be at.
     *
     * Picked out of the list already fetched for the screen rather than asked
     * for again — the till page is the most-opened page in the shop.
     *
     * @param  Collection<int, Register>  $registers
     */
    private function currentRegister(Request $request, Collection $registers): ?Register
    {
        $id = (int) $request->session()->get(self::SESSION_KEY);

        $register = $id ? $registers->firstWhere('id', $id) : null;

        if (! $register && $registers->count() === 1) {
            $register = $registers->first();
            $request->session()->put(self::SESSION_KEY, $register->id);
        }

        return $register;
    }

    private function looksLikeABarcode(string $code): bool
    {
        return (bool) preg_match('/^\d{6,}$/', $code);
    }
}
