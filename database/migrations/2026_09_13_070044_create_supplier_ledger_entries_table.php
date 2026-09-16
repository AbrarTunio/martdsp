<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier ledger — what the shop owes each supplier, and why.
     *
     * Written the way the shop's own books would: a supplier is money owed,
     * so a credit increases the balance (a bill received) and a debit reduces
     * it (a payment made, goods sent back). The screens never use those two
     * words; they say "you owe more" and "you owe less".
     *
     * Append-only. A wrong entry is corrected with an adjustment, never by
     * editing the row.
     */
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);

            /** How a payment or refund changed hands. Null for everything else. */
            $table->string('method', 20)->nullable();

            $table->unsignedBigInteger('debit_paisa')->default(0);
            $table->unsignedBigInteger('credit_paisa')->default(0);

            /** What was owed after this row, so a statement reconciles by reading. */
            $table->bigInteger('balance_after_paisa');

            /** The purchase or return this came from, if any. */
            $table->nullableMorphs('reference');

            $table->date('entry_date');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            /** A supplier's statement is read in this order. */
            $table->index(['supplier_id', 'id']);
            $table->index(['type', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
