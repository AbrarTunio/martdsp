<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The receipt printers the shop can reach, and which counter uses which.
     *
     * A printer is kept apart from the counter because one printer is often
     * shared by two tills standing side by side, and because the settings
     * that make it work — the share name, whether it can cut, which pin the
     * drawer is on — belong to the hardware, not to the till.
     */
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);

            /** windows_share, network or browser. See App\Enums\PrinterConnection. */
            $table->string('channel', 20)->default('browser');

            /** The share name or host:port. Empty for a browser printer. */
            $table->string('target', 255)->nullable();

            /** The roll this printer is loaded with: 80 or 58. */
            $table->string('paper', 4)->default('80');

            /**
             * Cheap printers lie about what they support, so each of these is
             * a switch the owner can turn off when the paper comes out with
             * rubbish on it.
             */
            $table->boolean('cuts')->default(true);
            $table->boolean('has_drawer')->default(false);

            /** Which pin the drawer's cable is on: 0 is pin 2, 1 is pin 5. */
            $table->unsignedTinyInteger('drawer_pin')->default(0);

            /** How many blank lines to feed before the cut, so the tear is clean. */
            $table->unsignedTinyInteger('feed_lines')->default(4);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('registers', function (Blueprint $table): void {
            $table->foreignId('printer_id')->nullable()->after('location')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('printer_id');
        });

        Schema::dropIfExists('printers');
    }
};
