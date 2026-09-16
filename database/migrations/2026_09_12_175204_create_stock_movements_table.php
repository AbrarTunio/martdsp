<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /**
             * The packaging level that was actually scanned or typed, kept so
             * the ledger can say "2 cartons" rather than "576 sachets". It is
             * nullable because a recount and a rebuild both speak in base
             * units only.
             */
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** Signed, always in base units. Positive arrived, negative left. */
            $table->bigInteger('qty_base');

            $table->string('type', 20);

            /** The purchase, sale or adjustment that caused this. */
            $table->nullableMorphs('reference');

            /**
             * Cost of one base unit in this movement, and the moving average
             * afterwards. Both are snapshots: a report valuing last month's
             * stock must never re-read today's cost.
             */
            $table->unsignedBigInteger('unit_cost_base_paisa')->default(0);
            $table->unsignedBigInteger('avg_cost_after_paisa')->default(0);

            /** The balance this row left behind, so the ledger reconciles by reading. */
            $table->bigInteger('balance_after_base');

            /**
             * Constrained in Phase 8, when stock_batches exists. Left
             * unconstrained rather than omitted so the shape of a ledger row
             * does not change once purchases and sales are writing to it.
             */
            $table->unsignedBigInteger('batch_id')->nullable();

            /** Null for anything the system did on its own, such as a rebuild. */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('note', 255)->nullable();

            /**
             * When the stock actually moved, which is not always when the row
             * was written — a delivery entered the next morning happened the
             * night before.
             */
            $table->timestamp('occurred_at');

            $table->timestamps();

            /** Every stock report and the per-product ledger read this order. */
            $table->index(['product_id', 'occurred_at', 'id']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
