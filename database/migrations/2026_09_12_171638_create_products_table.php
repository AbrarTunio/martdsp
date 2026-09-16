<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 32)->unique();
            $table->string('name', 120);
            $table->string('name_ur', 120)->nullable();

            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            /**
             * The smallest sellable piece. Every quantity in the system is a
             * whole number of these, which is why repacking a carton into
             * loose sachets needs no stock movement at all.
             *
             * A weighted product uses the gram as its base so the invariant
             * still holds with integers.
             */
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_weighted')->default(false);
            $table->boolean('track_batches')->default(false);
            $table->boolean('track_expiry')->default(false);

            $table->unsignedBigInteger('reorder_level_base')->default(0);
            $table->unsignedBigInteger('reorder_qty_base')->default(0);

            /** Moving weighted average cost of one base unit. Written by Phase 2. */
            $table->unsignedBigInteger('avg_cost_base_paisa')->default(0);

            /** Cached balance. The stock_movements ledger remains the source of truth. */
            $table->bigInteger('stock_qty_base')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /**
             * The product list is always filtered to active rows and sorted by
             * name. category_id and brand_id need no index here: InnoDB
             * creates one for each foreign key already.
             */
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
