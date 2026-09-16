<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();

            /** The line on the bill this came off, which fixes the price. */
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** Sold loose by weight as well as by the packet, so not an integer. */
            $table->decimal('qty', 12, 3)->default(0);
            $table->unsignedBigInteger('qty_base')->default(0);

            /**
             * What one of them earned the shop after every discount on the
             * bill, including its share of the bill-wide one. Refunding the
             * ticket price would hand back a discount the customer never paid.
             */
            $table->unsignedBigInteger('unit_refund_paisa')->default(0);
            $table->unsignedBigInteger('tax_paisa')->default(0);
            $table->unsignedBigInteger('line_total_paisa')->default(0);

            /** What the goods cost on the day, carried over from the bill. */
            $table->unsignedBigInteger('cost_base_paisa')->default(0);

            $table->timestamps();

            /** One line per bill line per return: a second scan adds to the first. */
            $table->unique(['sale_return_id', 'sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
