<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods sent back to a supplier.
     *
     * A return is posted the moment it is saved. It is short — a few expired
     * packets handed to the salesman — and there is nothing to come back to
     * later, unlike a delivery or a shelf count.
     */
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->unique();

            /** Null only for goods bought for cash from the market. */
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();

            /** The bill the goods came in on, when it is known. */
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason', 20);

            /**
             * How the supplier made good: a credit against what the shop owes,
             * or cash handed back there and then.
             */
            $table->string('settlement', 12)->default('credit');

            /** What the supplier is giving back for the goods. */
            $table->unsignedBigInteger('total_paisa')->default(0);

            /** What the goods were carried at, which is what left the stock value. */
            $table->unsignedBigInteger('cost_value_paisa')->default(0);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->index(['supplier_id', 'returned_at']);
            $table->index(['reason', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
