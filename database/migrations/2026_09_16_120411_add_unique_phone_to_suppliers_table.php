<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One number, one supplier.
 *
 * A distributor entered twice becomes two accounts, and half the money owed
 * sits on the account nobody is looking at. The phone number is the one thing
 * about a supplier that is genuinely theirs alone, so it becomes the thing
 * the shop is stopped from entering twice.
 *
 * Numbers already in the table are rewritten into the one shape first (see
 * App\Support\PhoneNumber); where that turns two rows into the same number,
 * the later row keeps its account and loses its number, so nothing is deleted
 * and the shopkeeper can sort the two out afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->tidyExistingNumbers();

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
        });
    }

    private function tidyExistingNumbers(): void
    {
        $seen = [];

        foreach (DB::table('suppliers')->orderBy('id')->get(['id', 'phone']) as $supplier) {
            $tidied = PhoneNumber::normalise($supplier->phone);

            if ($tidied !== null && isset($seen[$tidied])) {
                $tidied = null;
            }

            if ($tidied !== null) {
                $seen[$tidied] = true;
            }

            if ($tidied !== $supplier->phone) {
                DB::table('suppliers')->where('id', $supplier->id)->update(['phone' => $tidied]);
            }
        }
    }
};
