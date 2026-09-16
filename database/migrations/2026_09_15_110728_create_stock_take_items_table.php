<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One item on a count sheet.
     *
     * What was counted is kept as typed and in base units, with the moment
     * it was counted. What the books said at that moment, the difference, and
     * what that difference cost are worked out at posting, from the ledger.
     */
    public function up(): void
    {
        Schema::create('stock_take_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stock_take_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /** The size it was counted in — cartons on the top shelf, loose sachets below. */
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            /** As typed, in the chosen size. */
            $table->unsignedBigInteger('counted_qty')->default(0);

            /** The same count flattened to base units, which is what is posted. */
            $table->unsignedBigInteger('counted_base')->default(0);

            /**
             * False for a line added at posting because the item was in the
             * section and nobody scanned it. Kept apart so the report can say
             * "not found" rather than "counted as none".
             */
            $table->boolean('was_counted')->default(true);

            /**
             * When the item was first scanned, by the shop's clock. The shop
             * keeps selling through a count, so the count is measured against
             * the books as they stood at this moment — a packet scanned and
             * then sold is neither missing nor there twice.
             */
            $table->timestamp('counted_at')->nullable();

            /** What the books said at the moment it was counted. */
            $table->bigInteger('system_qty_base')->nullable();

            /** Counted minus the books. Negative is stock missing. */
            $table->bigInteger('variance_base')->default(0);

            $table->unsignedBigInteger('unit_cost_base_paisa')->default(0);
            $table->bigInteger('variance_value_paisa')->default(0);

            $table->string('note', 255)->nullable();
            $table->timestamps();

            /** One line per item per count: two would post the difference twice. */
            $table->unique(['stock_take_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_items');
    }
};
