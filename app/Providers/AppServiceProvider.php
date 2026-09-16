<?php

namespace App\Providers;

use App\Models\DrawerSession;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
        $this->definePasswordRules();
        $this->keepSettingsFreshForEachJob();
    }

    /**
     * The settings are read once and kept for the rest of the request, which
     * is what makes a scan cheap. A queue worker outlives any one request, so
     * it is given a fresh copy before each job rather than working all evening
     * from what the shop's settings were when it started.
     */
    private function keepSettingsFreshForEachJob(): void
    {
        Setting::forgetSnapshot();

        Event::listen(JobProcessing::class, function (): void {
            Setting::forgetSnapshot();
        });
    }

    /**
     * Role-based abilities.
     *
     * Roles are deliberately coarse — owner, manager, cashier — because the
     * shop has a handful of staff, and a granular permission matrix would be
     * one more thing for a non-technical owner to get wrong.
     */
    private function registerGates(): void
    {
        Gate::define('see-financials', fn (User $user): bool => $user->seesFinancials());

        Gate::define('supervise', fn (User $user): bool => $user->supervises());

        Gate::define('manage-settings', fn (User $user): bool => $user->supervises());

        Gate::define('manage-users', fn (User $user): bool => $user->isOwner());

        /**
         * A backup file is the whole shop in one piece — every sale, every
         * customer, every staff account. Only the owner may take one away.
         */
        Gate::define('manage-backups', fn (User $user): bool => $user->isOwner());

        /**
         * The AI notes read the shop's takings, costs and khata aloud, so
         * whoever may see the money may ask for them.
         */
        Gate::define('use-insights', fn (User $user): bool => $user->seesFinancials());

        /**
         * A cashier can look back at their own bills — to reprint a receipt
         * for a customer — but not browse the rest of the shop's takings.
         */
        Gate::define('view-sale', fn (User $user, Sale $sale): bool => $user->supervises() || $sale->user_id === $user->id);

        /**
         * Anyone can see a drawer that is running now, because every cashier
         * works from it. A closed shift is shown to the people who ran it.
         */
        Gate::define('view-drawer', fn (User $user, DrawerSession $session): bool => $user->supervises()
            || $session->isOpen()
            || in_array($user->id, [$session->opened_by, $session->closed_by], true));

        /**
         * With blind counting on, a cashier never sees what the drawer should
         * hold — only what they counted.
         */
        Gate::define('see-expected-cash', fn (User $user): bool => $user->supervises()
            || ! (bool) Setting::read('drawer.blind_count'));

        /**
         * An inactive account keeps its history but can do nothing, so a
         * departed cashier's sales stay attributable.
         */
        Gate::before(fn (User $user): ?bool => $user->is_active ? null : false);
    }

    /**
     * Shop staff pick their own passwords on a phone keypad. Eight characters
     * is the floor; complexity rules here would only push them to write it on
     * a sticky note by the till.
     */
    private function definePasswordRules(): void
    {
        Password::defaults(fn () => Password::min(8));
    }
}
