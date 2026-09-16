<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shop-wide vocabulary of unit names: Sachet, Box, Carton, Kg, Litre.
     * A unit says nothing about size on its own — a "Box" is only 24 sachets
     * in the context of one product, which is what product_units records.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('short_name', 12);
            $table->string('type', 10)->default('count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
