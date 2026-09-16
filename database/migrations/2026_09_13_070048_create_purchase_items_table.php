<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /** The size it was bought in — usually the carton that was scanned. */
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** As on the bill, in that size. */
            $table->unsignedBigInteger('qty')->default(0);

            /**
             * Free goods on a trade scheme — the "12 + 1" a distributor gives.
             * They arrive at no cost, which is exactly why they must be
             * counted: they lower what every piece on the shelf cost.
             */
            $table->unsignedBigInteger('bonus_qty')->default(0);

            /** (qty + bonus_qty) in base units. What reaches the stock ledger. */
            $table->unsignedBigInteger('qty_base')->default(0);

            /** Price of one pack as printed on the bill, and qty × that. */
            $table->unsignedBigInteger('unit_cost_paisa')->default(0);
            $table->unsignedBigInteger('line_total_paisa')->default(0);

            /**
             * What one base unit really cost once the bill's discount and tax
             * were shared out and the free goods counted in. Set on receiving.
             */
            $table->unsignedBigInteger('cost_base_paisa')->default(0);

            /** Captured now; batches and expiry tracking are built on them in Phase 8. */
            $table->string('batch_no', 40)->nullable();
            $table->date('expiry_date')->nullable();

            $table->timestamps();

            /** "What did we last pay for this?" is asked on every scan. */
            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
