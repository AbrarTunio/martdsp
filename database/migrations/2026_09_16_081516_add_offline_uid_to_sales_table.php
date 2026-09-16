<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A name the till gives a bill before the server has ever seen it.
 *
 * When the internet drops mid-shift the till keeps selling and holds the bills
 * in the browser. Each one is stamped with its own name when it is rung up, so
 * when the queue is sent later the server can tell a bill it has already taken
 * from one it has not — a lost reply can no longer charge a customer twice.
 *
 * `offline_rung_at` remembers the moment the cashier actually took the money,
 * which the receipt shows. The sale's own `sold_at` stays the moment the
 * server accepted it, so a drawer counted at the end of the shift still adds up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->uuid('offline_uid')->nullable()->unique()->after('user_id');
            $table->timestamp('offline_rung_at')->nullable()->after('sold_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique(['offline_uid']);
            $table->dropColumn(['offline_uid', 'offline_rung_at']);
        });
    }
};
