<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The count at close, note by note: "how many 1000s?" rather than "type
     * the total", which is quicker and far harder to get wrong.
     */
    public function up(): void
    {
        Schema::create('cash_count_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('drawer_session_id')->constrained()->cascadeOnDelete();

            /** In rupees: 5000, 1000, 500 … 1. */
            $table->unsignedInteger('denomination');

            $table->unsignedInteger('count');

            $table->unsignedBigInteger('subtotal_paisa');

            $table->timestamps();

            $table->unique(['drawer_session_id', 'denomination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_lines');
    }
};
