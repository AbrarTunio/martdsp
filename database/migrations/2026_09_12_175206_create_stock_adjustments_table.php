<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();

            /** Human-readable, because this is what gets written on a paper note. */
            $table->string('reference', 20)->unique();

            $table->string('reason', 20);
            $table->string('note', 255)->nullable();

            /**
             * Draft until posted. Nothing reaches the stock ledger while an
             * adjustment is a draft, so a half-counted shelf can be left and
             * come back to.
             */
            $table->string('status', 12)->default('draft');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            /** Who signed it off. A cashier's loss entry needs a supervisor. */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * Total cost value of everything moved, signed, snapshotted at the
             * moment of posting. Shrinkage reports sum this column.
             */
            $table->bigInteger('value_paisa')->default(0);

            $table->timestamp('adjusted_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'adjusted_at']);
            $table->index(['reason', 'adjusted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
