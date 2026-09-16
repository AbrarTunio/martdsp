<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The first account, which exists only because there is no public
 * registration. Everyone else is created from Settings -> Staff.
 *
 * Safe to run more than once: an existing owner is left untouched rather
 * than having their password reset out from under them.
 */
class OwnerSeeder extends Seeder
{
    private const EMAIL = 'owner@supermart.test';

    private const PASSWORD = 'password';

    public function run(): void
    {
        $owner = User::firstWhere('email', self::EMAIL);

        if ($owner) {
            $this->command?->warn("Owner {$owner->email} already exists — left as it is.");

            return;
        }

        $owner = User::create([
            'name' => 'Shop Owner',
            'email' => self::EMAIL,
            'role' => Role::Owner,
            'password' => self::PASSWORD,
            'is_active' => true,
        ]);

        $this->command?->info("Owner account created: {$owner->email} / ".self::PASSWORD);
        $this->command?->warn('Change that password the first time you sign in.');
    }
}
