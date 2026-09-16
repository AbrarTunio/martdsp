<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock kept in layers, for the goods where it matters.
     *
     * Milk, medicine and bread are not one heap: what arrived on Monday and
     * what arrived on Friday go off on different days, and the shopkeeper
     * needs to know which is which. A batch is one such layer — what came in
     * on one delivery under one expiry date — and it carries how much of that
     * layer is still on the shelf.
     *
     * The product's own `stock_qty_base` stays the one true total. These rows
     * say how that total is made up for products that are flagged for it, and
     * are what the expiring-stock list is read from.
     */
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /** Where it came from, when it came from a delivery. */
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();

            /** The number printed on the carton, when the supplier prints one. */
            $table->string('batch_no', 40)->nullable();

            /** Null means a batch with no date — tracked for its number alone. */
            $table->date('expiry_date')->nullable();

            /** What arrived, and what is left of it. Both in base units. */
            $table->unsignedBigInteger('received_base')->default(0);
            $table->unsignedBigInteger('qty_base')->default(0);

            /** What one base unit of this layer landed at. */
            $table->unsignedBigInteger('cost_base_paisa')->default(0);

            $table->timestamp('received_at')->useCurrent();

            $table->timestamps();

            /**
             * First-expiry-first-out reads this index on every sale: the open
             * layers of one product, soonest date first.
             */
            $table->index(['product_id', 'expiry_date', 'id']);

            /** The expiring-stock list sweeps every product by date. */
            $table->index(['expiry_date', 'qty_base']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
