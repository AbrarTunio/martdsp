<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The khata — what each customer owes the shop, and why.
     *
     * Kept the way a khata book is: a debit means the customer owes more (a
     * sale on credit), a credit means they owe less (a payment, a return).
     * The screens never use those two words.
     *
     * Append-only. A wrong entry is corrected with an adjustment, never by
     * editing the row.
     */
    public function up(): void
    {
        Schema::create('customer_ledger_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);

            /** How a payment changed hands. Null for everything else. */
            $table->string('method', 20)->nullable();

            $table->unsignedBigInteger('debit_paisa')->default(0);
            $table->unsignedBigInteger('credit_paisa')->default(0);

            /** What was owed after this row, so a statement reconciles by reading. */
            $table->bigInteger('balance_after_paisa');

            /** The sale or payment this came from, if any. */
            $table->nullableMorphs('reference');

            $table->date('entry_date');

            /** When a sale on credit should have been paid. What makes aging possible. */
            $table->date('due_date')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            /** A customer's statement is read in this order. */
            $table->index(['customer_id', 'id']);
            $table->index(['type', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
    }
};
