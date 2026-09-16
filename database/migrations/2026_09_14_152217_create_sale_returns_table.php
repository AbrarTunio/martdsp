<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods a customer brought back.
     *
     * Always against a bill. The shop needs the price that was actually paid,
     * the GST that was charged on it and what the goods cost on the day, and
     * only the bill has all three; refunding from memory is how a shop gives
     * back more than it took.
     *
     * Like a purchase return, it is posted the moment it is saved. The
     * customer is standing at the counter — there is no draft to come back to.
     */
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            /** Copied from the bill, so a walk-in return simply has none. */
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /** The counter it was taken back at, which is whose drawer pays. */
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason', 20);

            /** Cash out of the drawer, or credit against the customer's khata. */
            $table->string('settlement', 12)->default('cash');

            /**
             * Whether the goods went back into stock. Expired and damaged
             * goods do not: recording them as stock would only show up as a
             * shortage at the next shelf count.
             */
            $table->boolean('restocked')->default(true);

            /** What the customer gets back, and the GST inside it. */
            $table->unsignedBigInteger('total_paisa')->default(0);
            $table->unsignedBigInteger('tax_paisa')->default(0);

            /** What the goods cost the shop on the day they were sold. */
            $table->unsignedBigInteger('cost_value_paisa')->default(0);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->index(['sale_id', 'returned_at']);
            $table->index(['customer_id', 'returned_at']);
            $table->index(['reason', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
