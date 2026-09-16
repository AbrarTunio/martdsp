<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People the shop sells to on khata. A walk-in sale has no customer at
     * all, so this table only holds the regulars worth knowing by name.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('name_ur', 120)->nullable();

            /** How the shopkeeper finds them at the till, so no two share one. */
            $table->string('phone', 20)->nullable()->unique();

            $table->string('address', 255)->nullable();

            /** The most they may owe. Zero means no limit has been set. */
            $table->unsignedBigInteger('credit_limit_paisa')->default(0);

            /**
             * What they already owed when the shop started using the system.
             * Written once, as the khata's opening entry.
             */
            $table->bigInteger('opening_balance_paisa')->default(0);

            /**
             * Cached balance: positive means the customer owes the shop. The
             * customer_ledger_entries table remains the source of truth.
             */
            $table->bigInteger('balance_paisa')->default(0);

            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
