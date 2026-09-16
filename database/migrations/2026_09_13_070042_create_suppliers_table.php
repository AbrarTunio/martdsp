<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            /** The distributor behind the salesman, where they differ. */
            $table->string('company', 120)->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();

            /** Days of credit the supplier gives. A bill's due date is worked out from it. */
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            /**
             * What was already owed when the shop started using the system.
             * Written once, as the ledger's opening entry, and never edited
             * afterwards — a mistake here is fixed with an adjustment.
             */
            $table->bigInteger('opening_balance_paisa')->default(0);

            /**
             * Cached balance: positive means the shop owes the supplier. The
             * supplier_ledger_entries table remains the source of truth.
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
        Schema::dropIfExists('suppliers');
    }
};
