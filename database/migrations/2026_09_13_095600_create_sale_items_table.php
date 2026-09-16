<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /**
             * The size it was sold in. Packaging can be redrawn later, so the
             * name and size are copied onto the line as well.
             */
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** Copied from the product at the time of sale, for the receipt. */
            $table->string('name', 160);
            $table->string('unit_name', 60);

            /** Three decimals, for loose goods sold by weight. */
            $table->decimal('qty', 12, 3);

            /** What actually left the shelf. */
            $table->unsignedBigInteger('qty_base');

            /** Price of one of this size, as it stood when sold. */
            $table->unsignedBigInteger('unit_price_paisa');

            /** Qty × price, before discounts. */
            $table->unsignedBigInteger('gross_paisa');

            /** As typed on the line — "20" or "10%". */
            $table->string('discount', 20)->nullable();
            $table->unsignedBigInteger('discount_paisa')->default(0);

            /** This line's share of the whole-bill discount. */
            $table->unsignedBigInteger('bill_discount_paisa')->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_paisa')->default(0);

            /** What the customer pays for this line. */
            $table->unsignedBigInteger('line_total_paisa');

            /**
             * The moving average cost of one base unit at the moment of sale.
             * Profit on this line is worked out from this and never from
             * today's cost.
             */
            $table->unsignedBigInteger('cost_at_sale_base_paisa')->default(0);

            $table->timestamps();

            /** Sales history per product, for reports and insights. */
            $table->index(['product_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
