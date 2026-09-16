<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bill at the till.
     *
     * A sale is either held (a basket parked while the next customer is
     * served — it has touched nothing), completed (stock has left and the
     * money is in), or void (a supervisor cancelled it and everything it did
     * was put back). The drawer shift it belongs to is added in Phase 5.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();

            /**
             * Given only when the sale completes, one after another with no
             * gaps, so a missing number on the day's list means something.
             * Held sales have none.
             */
            $table->unsignedBigInteger('invoice_no')->nullable()->unique();

            /** Null is a walk-in customer. */
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('register_id')->constrained()->restrictOnDelete();

            /** The cashier who rang it up. */
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('status', 20);

            /** Qty × price over every line, before any discount. */
            $table->unsignedBigInteger('subtotal_paisa')->default(0);

            /**
             * What was typed as the discount on the whole bill — "50" or "5%" —
             * kept as typed so a held sale comes back exactly as it was left.
             */
            $table->string('bill_discount', 20)->nullable();

            /** Every discount on the bill, line and whole-bill together. */
            $table->unsignedBigInteger('discount_paisa')->default(0);

            $table->unsignedBigInteger('tax_paisa')->default(0);

            /** Whether shelf prices included GST when this sale was made. */
            $table->boolean('prices_include_tax')->default(true);

            /**
             * Paisa shaved off to reach a whole rupee. Positive means the
             * customer paid less than the exact figure; it prints as its own line.
             */
            $table->integer('round_off_paisa')->default(0);

            $table->unsignedBigInteger('total_paisa')->default(0);

            /** Taken at the till, net of change: cash, card and wallets. */
            $table->unsignedBigInteger('paid_paisa')->default(0);

            $table->unsignedBigInteger('change_given_paisa')->default(0);

            /** Put on the customer's khata. */
            $table->unsignedBigInteger('due_paisa')->default(0);

            $table->string('note', 255)->nullable();

            $table->timestamp('sold_at')->nullable();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'sold_at']);
            $table->index(['register_id', 'status']);
            $table->index(['user_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
