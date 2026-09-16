<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A separate table because the same box genuinely arrives with different
     * supplier barcodes, and the shop prints its own labels for loose goods.
     *
     * The code is unique across the whole table: a scan has to identify one
     * packaging level of one product with no further questions asked.
     */
    public function up(): void
    {
        Schema::create('barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcodes');
    }
};
