<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a sale was paid. A table of its own so that "Rs. 500 cash, the
     * rest on khata" is one sale with two rows, not a special case.
     */
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            $table->string('method', 20);

            /** What this tender paid off the bill. */
            $table->unsignedBigInteger('amount_paisa');

            /**
             * What was handed over. Differs from the amount only for cash,
             * where the difference went back as change.
             */
            $table->unsignedBigInteger('tendered_paisa');

            /** A card slip or wallet transaction number. */
            $table->string('reference', 60)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['method', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
