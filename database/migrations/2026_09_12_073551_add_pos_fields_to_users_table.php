<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('cashier')->after('email');
            $table->string('phone', 20)->nullable()->after('role');
            $table->string('pin_code')->nullable()->after('password');
            $table->boolean('is_active')->default(true)->after('pin_code');

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn(['role', 'phone', 'pin_code', 'is_active']);
        });
    }
};
