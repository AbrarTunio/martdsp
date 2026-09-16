<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();

            /** Ours, readable aloud: PUR-000001. */
            $table->string('reference', 20)->unique();

            /**
             * Null for goods bought for cash from the market, where there is
             * nobody to owe. Such a purchase must be paid in full.
             */
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();

            /** The number printed on the supplier's bill — theirs, not ours. */
            $table->string('invoice_no', 40)->nullable();

            $table->date('purchase_date');

            /** purchase_date plus the supplier's credit terms, set on receiving. */
            $table->date('due_on')->nullable();

            /**
             * Draft until received. A delivery can be scanned in over half an
             * hour and checked against the bill before anything moves.
             */
            $table->string('status', 12)->default('draft');

            /**
             * The bill's figures, all worked out again on the server when the
             * purchase is received. The discount and the tax are spread over
             * the lines by value, so the cost that reaches the moving average
             * is what the goods actually cost the shop.
             */
            $table->unsignedBigInteger('subtotal_paisa')->default(0);
            $table->unsignedBigInteger('discount_paisa')->default(0);
            $table->unsignedBigInteger('tax_paisa')->default(0);
            $table->unsignedBigInteger('total_paisa')->default(0);

            /** Paid against this bill when it was received. Never more than the total. */
            $table->unsignedBigInteger('paid_paisa')->default(0);
            $table->string('payment_method', 20)->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('note', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'purchase_date']);
            $table->index(['supplier_id', 'purchase_date']);

            /** Catching a bill entered twice starts from this lookup. */
            $table->index(['supplier_id', 'invoice_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
