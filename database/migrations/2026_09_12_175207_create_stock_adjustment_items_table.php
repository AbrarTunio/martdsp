<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /** The size the quantity was entered in — cartons, boxes, sachets. */
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** As typed, in the chosen size. Kept so the line reads back the way it was entered. */
            $table->unsignedBigInteger('qty')->default(0);

            /**
             * The signed effect on stock, in base units. For a recount this is
             * the variance, worked out against the balance at posting time.
             */
            $table->bigInteger('qty_base')->default(0);

            /** What the shelf held when the line was written, for a recount's variance. */
            $table->bigInteger('system_qty_base')->nullable();

            /** Cost of one base unit at posting time, and the line's signed value. */
            $table->unsignedBigInteger('unit_cost_base_paisa')->default(0);
            $table->bigInteger('value_paisa')->default(0);

            $table->string('note', 255)->nullable();
            $table->timestamps();

            /** One line per product per adjustment: two would post twice for the same shelf. */
            $table->unique(['stock_adjustment_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
