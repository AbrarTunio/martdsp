<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The drawer shift a completed sale was rung up in, so the end-of-sale
     * report can list every tender taken during it. Held sales have none.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('drawer_session_id')->nullable()->after('register_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('drawer_session_id');
        });
    }
};
