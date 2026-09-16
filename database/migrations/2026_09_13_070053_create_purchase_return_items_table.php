<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('qty')->default(0);
            $table->unsignedBigInteger('qty_base')->default(0);

            /** What the supplier gives back for one pack, and qty × that. */
            $table->unsignedBigInteger('unit_credit_paisa')->default(0);
            $table->unsignedBigInteger('line_total_paisa')->default(0);

            /**
             * The average cost the goods left the shelf at. The gap between
             * this and the credit is a loss or a gain on the return; it never
             * touches the average cost of what stays behind.
             */
            $table->unsignedBigInteger('cost_base_paisa')->default(0);

            $table->timestamps();

            /** One line per size per return: a second scan adds to the first. */
            $table->unique(['purchase_return_id', 'product_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
