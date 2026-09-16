<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The physical counters. Every sale records which one it was rung up on,
     * and in Phase 5 every drawer shift belongs to one.
     */
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('location', 120)->nullable();

            /** Which receipt printer this counter prints to. Filled in by Phase 7. */
            $table->string('printer_profile', 40)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
