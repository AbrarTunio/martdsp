<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every time someone pressed "Explain this page" and an AI was asked.
     *
     * Three jobs in one table: the note is reused from here for a few hours
     * rather than paid for twice; the month's spend is summed from here
     * against the cap; and it is a record of what was advised and when. A
     * call that failed is kept too, with the reason, so Settings can say why
     * the panel fell back to the shop's own checks.
     */
    public function up(): void
    {
        Schema::create('ai_insight_runs', function (Blueprint $table): void {
            $table->id();

            /** Which page's figures: "stock", "khata", "sales"… */
            $table->string('page', 40);

            /** The page plus what it was looking at — dates, a customer — and the language, hashed. */
            $table->string('scope_hash', 64);

            /** "ai" when the note came back, "failed" when it did not. */
            $table->string('status', 12);

            $table->string('provider', 20);
            $table->string('model', 120);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            /** Estimated from the provider's list price, in paisa. */
            $table->unsignedBigInteger('cost_paisa')->default(0);

            $table->unsignedInteger('latency_ms')->nullable();

            /** Exactly what was sent — figures and findings, never a phone number. */
            $table->json('metric_pack');

            /** The note as it came back, already checked. */
            $table->json('response')->nullable();

            $table->string('error', 500)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['page', 'scope_hash', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insight_runs');
    }
};
