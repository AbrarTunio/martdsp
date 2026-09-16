<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A physical count of part of the shop, or all of it.
     *
     * Different from a correction: a correction fixes one shelf someone
     * noticed was wrong, a stock take walks a whole section with a scanner and
     * finds the shelves nobody noticed. The count is kept as its own record,
     * with what the books said next to what was found, because the gap
     * between the two is the number the owner actually wants to see.
     */
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table): void {
            $table->id();

            /** STK-000001. Written on the clipboard and read back over the phone. */
            $table->string('reference', 20)->unique();

            /** "Dairy fridge, Friday night" — whatever the counter will recognise. */
            $table->string('name', 80)->nullable();

            /** Still counting until posted. Nothing reaches the ledger before that. */
            $table->string('status', 12)->default('draft');

            /**
             * The section being counted. Null means the whole shop, which is
             * a full stock take rather than a spot check.
             */
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            /**
             * Whether anything in the section that was not scanned is taken to
             * be gone. This is what finds stock that is on the books and not
             * on the shelf — the whole point of counting a section rather than
             * a shelf — so it is a deliberate choice, and only allowed when a
             * section is chosen.
             */
            $table->boolean('missing_are_zero')->default(false);

            $table->string('note', 255)->nullable();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * Snapshotted at posting. Signed: a shop that found more than it
             * expected sees a positive number, which is itself worth asking
             * about.
             */
            $table->bigInteger('variance_value_paisa')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_takes');
    }
};
