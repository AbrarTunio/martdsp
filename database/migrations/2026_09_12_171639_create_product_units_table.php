<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per packaging level that can be bought or sold.
     *
     * Each row stores both how the shopkeeper described it and the flattened
     * number the till uses: "1 carton = 12 boxes" is kept in parent_unit_id
     * and qty_per_parent so the edit screen can show the familiar figures,
     * while conversion_factor holds the 288 sachets a carton actually is.
     * A scan must resolve in one indexed lookup, not by walking a chain.
     */
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            $table->foreignId('parent_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->unsignedBigInteger('qty_per_parent')->default(1);
            $table->unsignedBigInteger('conversion_factor')->default(1);

            $table->unsignedBigInteger('sale_price_paisa')->default(0);
            $table->unsignedBigInteger('mrp_paisa')->nullable();

            $table->boolean('is_base')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);

            /** Unit pickers list a product's packaging largest-first. */
            $table->index(['product_id', 'conversion_factor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
