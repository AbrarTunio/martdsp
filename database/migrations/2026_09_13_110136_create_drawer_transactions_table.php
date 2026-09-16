<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every rupee in or out of a drawer during a shift. Append-only: a mistake
     * is answered with another row, never an edit.
     *
     * The amount carries its own sign — into the drawer is positive, out of it
     * negative — so what should be in the drawer is simply the sum.
     */
    public function up(): void
    {
        Schema::create('drawer_transactions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('drawer_session_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);

            $table->bigInteger('amount_paisa');

            /** The sale, khata payment or refund behind the movement, if any. */
            $table->nullableMorphs('reference');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index(['drawer_session_id', 'type']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawer_transactions');
    }
};
