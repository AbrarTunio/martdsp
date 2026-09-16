<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khata numbers, written the one way.
 *
 * The customers table has always refused the same number twice, but only as
 * it happened to be typed: 0300 1234567 and +923001234567 are the same
 * neighbour and went in as two khatas, which is how half a debt ends up on an
 * account nobody looks at. Every number is rewritten into the one shape the
 * rest of the shop uses (see App\Support\PhoneNumber), the same shape a
 * supplier's number is stored in.
 *
 * Where that turns two rows into the same number the later one keeps its
 * khata and loses its number, so no balance is lost and the shopkeeper can
 * sort the two out afterwards. A number that cannot be read as a Pakistani
 * one is left exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];

        foreach (DB::table('customers')->orderBy('id')->get(['id', 'phone']) as $customer) {
            $tidied = PhoneNumber::normalise($customer->phone);

            if ($tidied !== null && isset($seen[$tidied])) {
                $tidied = null;
            }

            if ($tidied !== null) {
                $seen[$tidied] = true;
            }

            if ($tidied !== $customer->phone) {
                DB::table('customers')->where('id', $customer->id)->update(['phone' => $tidied]);
            }
        }
    }

    /**
     * Nothing to undo: the numbers still reach the same people, and the shapes
     * they were typed in were never recorded.
     */
    public function down(): void {}
};
