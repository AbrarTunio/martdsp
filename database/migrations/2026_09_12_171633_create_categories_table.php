<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_ur', 80)->nullable();

            /** One level of sub-category only. Deeper trees confuse the aisle-to-screen mapping. */
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
