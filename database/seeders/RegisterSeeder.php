<?php

namespace Database\Seeders;

use App\Models\Register;
use Illuminate\Database\Seeder;

/**
 * The shop's first counter. More are added from Settings → Counters.
 */
class RegisterSeeder extends Seeder
{
    public function run(): void
    {
        Register::ensureOne();
    }
}
