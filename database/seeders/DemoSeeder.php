<?php

namespace Database\Seeders;

use App\Models\Sale;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * A whole shop, already trading, for somebody to learn on.
 *
 * Run this on a fresh install to fill the system with a month of believable
 * kiryana business: forty products with their sachets and cartons, five
 * distributors, fourteen khata customers, a manager and two cashiers, and
 * every day's sales, deliveries, returns, khata payments and drawer counts.
 * Every screen then has something real to show, which is the only way to
 * learn what the numbers mean before trusting the system with your own.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * It will not run on a database that already has sales in it. Demo figures
 * mixed into a real shop's books cannot be told apart afterwards, and there
 * is no undo for that.
 */
class DemoSeeder extends Seeder
{
    /**
     * How many days of trading to make. The shop is set up the day before
     * the first one, so the opening stock has a date of its own.
     */
    public int $days = 28;

    public function run(): void
    {
        $this->refuseToSpoilARealShop();

        $this->call([
            OwnerSeeder::class,
            CatalogueSeeder::class,
            RegisterSeeder::class,
        ]);

        /* The shop is set up before the first day's trading, so the products,
           the staff and the opening stock are all dated to the day it opened
           rather than to this afternoon. */
        Carbon::setTestNow(Carbon::today()->subDays($this->days + 1)->setTime(8, 0));

        try {
            $this->call(DemoShopSeeder::class);
        } finally {
            Carbon::setTestNow();
        }

        $this->callWith(DemoHistorySeeder::class, ['days' => $this->days]);

        $this->command?->info('Demo shop ready. Sign in as owner@supermart.test with the password "password".');
        $this->command?->warn('This is made-up data. Do not add real sales on top of it.');
    }

    /**
     * @throws RuntimeException when this is not a database that can be thrown away
     */
    private function refuseToSpoilARealShop(): void
    {
        if (App::isProduction()) {
            throw new RuntimeException('The demo data is for learning on. It will not be put into a live shop.');
        }

        if (Sale::query()->exists()) {
            throw new RuntimeException('This database already has sales in it. Make a fresh one before seeding the demo shop.');
        }
    }
}
