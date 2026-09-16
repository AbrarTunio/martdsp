<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One shift on one counter's cash drawer: opened with a float, closed
     * with a count.
     *
     * The figures written at close — what should have been there, what was
     * counted, and the gap — are kept as they were on the night, so a later
     * change of setting or a late correction never rewrites a closed shift.
     */
    public function up(): void
    {
        Schema::create('drawer_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('register_id')->constrained()->restrictOnDelete();

            /**
             * The counter's id while the shift is open, null once it closes.
             * The unique index is what stops a counter having two open
             * drawers, on any database, even if two phones press Open at once.
             */
            $table->unsignedBigInteger('open_register_id')->nullable()->unique();

            $table->string('status', 20);

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');

            /** The cash put in the drawer to make change with. */
            $table->unsignedBigInteger('opening_float_paisa')->default(0);
            $table->string('opening_note', 255)->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();

            /** Float + cash in − cash out, worked out at the moment of closing. */
            $table->bigInteger('expected_cash_paisa')->nullable();

            /** What the notes and coins added up to. */
            $table->unsignedBigInteger('counted_cash_paisa')->nullable();

            /** Counted − expected. Negative is short, positive is over. */
            $table->bigInteger('variance_paisa')->nullable();
            $table->string('variance_reason', 255)->nullable();

            /**
             * Whether the gap was beyond the shop's tolerance when the drawer
             * closed. Stored rather than worked out, so changing the tolerance
             * later does not reopen or excuse old shifts.
             */
            $table->boolean('needs_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 255)->nullable();

            /**
             * Cash left in the drawer for the next shift. The rest of the
             * count went to the owner or the safe.
             */
            $table->unsignedBigInteger('left_in_drawer_paisa')->nullable();

            $table->timestamps();

            $table->index(['register_id', 'opened_at']);
            $table->index(['status', 'opened_at']);
            $table->index(['needs_approval', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawer_sessions');
    }
};
