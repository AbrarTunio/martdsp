<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BarcodeLabelController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAgingController;
use App\Http\Controllers\CustomerBalanceAdjustmentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\CustomerReminderController;
use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DrawerApprovalController;
use App\Http\Controllers\DrawerCloseController;
use App\Http\Controllers\DrawerController;
use App\Http\Controllers\DrawerMovementController;
use App\Http\Controllers\DrawerPrintController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosCustomerController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\PrinterTestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\PurchaseSuggestionController;
use App\Http\Controllers\QuickProductController;
use App\Http\Controllers\QuickSupplierController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalePrintController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SaleVoidController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StarterCatalogueController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockExpiryController;
use App\Http\Controllers\StockTakeController;
use App\Http\Controllers\SupplierBalanceAdjustmentController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    |----------------------------------------------------------------------
    | Selling
    |----------------------------------------------------------------------
    |
    | The till is open to everyone. Voiding a bill is for supervisors,
    | enforced in its form request.
    |
    */
    Route::prefix('sell')->name('pos.')->group(function (): void {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::post('/', [PosController::class, 'store'])->name('store');
        Route::post('counter', [PosController::class, 'chooseRegister'])->name('register');
        Route::get('lookup', [PosController::class, 'lookup'])->name('lookup');
        Route::get('search', [PosController::class, 'search'])->name('search');

        Route::get('held', [HeldSaleController::class, 'index'])->name('held.index');
        Route::post('held', [HeldSaleController::class, 'store'])->name('held.store');
        Route::post('held/{sale}/resume', [HeldSaleController::class, 'resume'])->name('held.resume');
        Route::delete('held/{sale}', [HeldSaleController::class, 'destroy'])->name('held.destroy');

        Route::get('customers', [PosCustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [PosCustomerController::class, 'store'])->name('customers.store');
    });

    Route::prefix('sales')->name('sales.')->group(function (): void {
        Route::get('/', [SaleController::class, 'index'])->name('index');

        /* Before {sale}, so /sales/returns is not read as a bill called
           "returns". */
        Route::get('returns', [SaleReturnController::class, 'index'])->name('returns.index');
        Route::get('returns/{return}', [SaleReturnController::class, 'show'])->name('returns.show');

        Route::get('{sale}', [SaleController::class, 'show'])->name('show');
        Route::get('{sale}/receipt', [SaleController::class, 'receipt'])->name('receipt');
        Route::post('{sale}/print', SalePrintController::class)->name('print');
        Route::post('{sale}/void', SaleVoidController::class)->name('void');
        Route::get('{sale}/return', [SaleReturnController::class, 'create'])->name('returns.create');
        Route::post('{sale}/return', [SaleReturnController::class, 'store'])->name('returns.store');
    });

    /*
    |----------------------------------------------------------------------
    | Cash drawer
    |----------------------------------------------------------------------
    |
    | Anyone on the till can open a drawer, move cash and count it at the
    | end of the shift. Signing off a count that was out is for supervisors.
    |
    */
    Route::prefix('drawer')->name('drawer.')->group(function (): void {
        Route::get('/', [DrawerController::class, 'index'])->name('index');
        Route::post('/', [DrawerController::class, 'store'])->name('open');
        Route::get('{drawer}', [DrawerController::class, 'show'])->name('show');
        Route::get('{drawer}/report', [DrawerController::class, 'report'])->name('report');
        Route::post('{drawer}/print', DrawerPrintController::class)->name('print');
        Route::post('{drawer}/movements', [DrawerMovementController::class, 'store'])->name('movements.store');
        Route::get('{drawer}/close', [DrawerCloseController::class, 'create'])->name('close.create');
        Route::post('{drawer}/close', [DrawerCloseController::class, 'store'])->name('close.store');
        Route::post('{drawer}/approve', DrawerApprovalController::class)->name('approve');
    });

    /*
    |----------------------------------------------------------------------
    | Catalogue
    |----------------------------------------------------------------------
    |
    | The fixed paths are declared before the products resource so that
    | /products/labels is not read as a product called "labels".
    |
    */
    Route::prefix('products')->name('products.')->group(function (): void {
        Route::get('lookup', [ProductController::class, 'lookup'])->name('lookup');
        Route::post('starter', StarterCatalogueController::class)->name('starter');

        Route::get('labels', [BarcodeLabelController::class, 'create'])->name('labels.create');
        Route::get('labels/sheet', [BarcodeLabelController::class, 'sheet'])->name('labels.sheet');

        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    Route::resource('products', ProductController::class)->except('show');

    /*
    |----------------------------------------------------------------------
    | Stock
    |----------------------------------------------------------------------
    |
    | Reading stock is open to everyone on the till; correcting it is not.
    | As with the catalogue, the fixed paths come first so that /stock/lookup
    | is not read as a product called "lookup".
    |
    */
    Route::prefix('stock')->name('stock.')->group(function (): void {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('lookup', [StockController::class, 'lookup'])->name('lookup');
        Route::get('expiry', StockExpiryController::class)->name('expiry');

        Route::prefix('adjustments')->name('adjustments.')->group(function (): void {
            Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
            Route::get('create', [StockAdjustmentController::class, 'create'])->name('create');
            Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
            Route::get('{adjustment}', [StockAdjustmentController::class, 'show'])->name('show');
            Route::get('{adjustment}/edit', [StockAdjustmentController::class, 'edit'])->name('edit');
            Route::put('{adjustment}', [StockAdjustmentController::class, 'update'])->name('update');
            Route::post('{adjustment}/post', [StockAdjustmentController::class, 'post'])->name('post');
            Route::delete('{adjustment}', [StockAdjustmentController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('takes')->name('takes.')->group(function (): void {
            Route::get('/', [StockTakeController::class, 'index'])->name('index');
            Route::get('create', [StockTakeController::class, 'create'])->name('create');
            Route::post('/', [StockTakeController::class, 'store'])->name('store');
            Route::get('{take}', [StockTakeController::class, 'show'])->name('show');
            Route::get('{take}/edit', [StockTakeController::class, 'edit'])->name('edit');
            Route::put('{take}', [StockTakeController::class, 'update'])->name('update');
            Route::post('{take}/post', [StockTakeController::class, 'post'])->name('post');
            Route::delete('{take}', [StockTakeController::class, 'destroy'])->name('destroy');
        });

        Route::get('{product}', [StockController::class, 'show'])->name('show');
    });

    /*
    |----------------------------------------------------------------------
    | Khata
    |----------------------------------------------------------------------
    |
    | Reading a khata is open to everyone on the till, because "kitna baaqi
    | hai?" is asked at the counter and answered there. Opening an account,
    | setting a credit limit and correcting a balance are the owner's, checked
    | in the controller and the form requests.
    |
    | /khata/aging is declared before the resource so it is not read as a
    | customer called "aging".
    |
    */
    Route::get('khata/aging', CustomerAgingController::class)->name('customers.aging');

    Route::resource('khata', CustomerController::class)
        ->parameters(['khata' => 'customer'])
        ->names('customers');

    Route::prefix('khata/{customer}')->name('customers.')->group(function (): void {
        Route::get('statement', CustomerStatementController::class)->name('statement');
        Route::get('reminder', CustomerReminderController::class)->name('reminder');
        Route::post('payments', [CustomerPaymentController::class, 'store'])->name('payments.store');
        Route::post('adjustments', [CustomerBalanceAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::post('write-off', [CustomerBalanceAdjustmentController::class, 'writeOff'])->name('write-off');
    });

    /*
    |----------------------------------------------------------------------
    | Buying
    |----------------------------------------------------------------------
    |
    | Supervisors only, enforced in each controller. The fixed paths under
    | /purchases come before {purchase} so that /purchases/returns is not
    | read as a purchase called "returns".
    |
    */
    Route::resource('suppliers', SupplierController::class);

    Route::prefix('suppliers/{supplier}')->name('suppliers.')->group(function (): void {
        Route::post('payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
        Route::post('adjustments', [SupplierBalanceAdjustmentController::class, 'store'])->name('adjustments.store');
    });

    Route::prefix('purchases')->name('purchases.')->group(function (): void {
        Route::get('lookup', [PurchaseController::class, 'lookup'])->name('lookup');
        Route::post('quick-product', [QuickProductController::class, 'store'])->name('quick-product');
        Route::post('quick-supplier', [QuickSupplierController::class, 'store'])->name('quick-supplier');

        Route::get('suggestions', [PurchaseSuggestionController::class, 'index'])->name('suggestions.index');
        Route::post('suggestions', [PurchaseSuggestionController::class, 'store'])->name('suggestions.store');

        Route::prefix('returns')->name('returns.')->group(function (): void {
            Route::get('/', [PurchaseReturnController::class, 'index'])->name('index');
            Route::get('create', [PurchaseReturnController::class, 'create'])->name('create');
            Route::post('/', [PurchaseReturnController::class, 'store'])->name('store');
            Route::get('{return}', [PurchaseReturnController::class, 'show'])->name('show');
        });

        Route::post('{purchase}/receive', [PurchaseController::class, 'receive'])->name('receive');
    });

    Route::resource('purchases', PurchaseController::class);

    /*
    |----------------------------------------------------------------------
    | Reports
    |----------------------------------------------------------------------
    |
    | Owner and managers only, checked in the controller. Every report is
    | reached by its key, e.g. /reports/daily-sales; an unknown key is a 404.
    |
    */
    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{key}', [ReportController::class, 'show'])->name('show');
        Route::get('{key}/print', [ReportController::class, 'print'])->name('print');
        Route::get('{key}/export', [ReportController::class, 'export'])->name('export');
    });

    /*
    |----------------------------------------------------------------------
    | AI Insights
    |----------------------------------------------------------------------
    |
    | One route behind every "Explain this page" button. It is throttled
    | because each press can cost the shop money.
    |
    */
    Route::post('insights/{page}', [InsightController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('insights.show');

    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::get('ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
        Route::put('ai', [AiSettingsController::class, 'update'])->name('ai.update');
        Route::delete('ai/key', [AiSettingsController::class, 'forget'])->name('ai.forget');
        Route::post('ai/models', [AiSettingsController::class, 'models'])->name('ai.models');
        Route::post('ai/test', [AiSettingsController::class, 'test'])->name('ai.test');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
        Route::resource('staff', StaffController::class)->except('show');
        Route::resource('registers', RegisterController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('printers', PrinterController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('printers/{printer}/test', [PrinterTestController::class, 'print'])->name('printers.test');
        Route::post('printers/{printer}/drawer', [PrinterTestController::class, 'pulse'])->name('printers.drawer');
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::put('backups', [BackupController::class, 'update'])->name('backups.update');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{name}', [BackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
