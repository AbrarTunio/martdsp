{{--
    Bills rung up while the line was down.

    This sits at the top of the till because it is the one thing a cashier must
    not walk away from: the money is in the drawer but the shop does not know
    about it yet. It disappears by itself the moment everything has gone
    through.
--}}
<div x-show="queue.length > 0" x-cloak class="mt-2 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-500/40 dark:bg-amber-500/10">
    <div class="flex items-start gap-2">
        <x-icon name="clock" class="mt-0.5 h-5 w-5 shrink-0 text-amber-700 dark:text-amber-300" />

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                <span x-text="queue.length"></span>
                <span x-text="queue.length === 1 ? '{{ __('bill is waiting to be sent') }}' : '{{ __('bills are waiting to be sent') }}'"></span>
            </p>

            <p class="mt-0.5 text-xs text-amber-800 dark:text-amber-300/90">
                {{ __('Keep this tab open. They go by themselves when the connection comes back.') }}
            </p>

            <p x-show="stuckCount > 0" x-cloak class="mt-0.5 text-xs font-semibold text-red-700 dark:text-red-400">
                <span x-text="stuckCount"></span>
                {{ __('could not be accepted and need a look.') }}
            </p>
        </div>

        <button type="button" x-on:click="flush()" x-bind:disabled="flushing"
                class="shrink-0 rounded-lg border border-amber-400 px-2.5 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-500/40 dark:text-amber-200 dark:hover:bg-amber-500/10">
            <span x-text="flushing ? '{{ __('Sending...') }}' : '{{ __('Send now') }}'"></span>
        </button>
    </div>

    <ul class="mt-2 space-y-1.5">
        <template x-for="entry in queue" x-bind:key="entry.uid">
            <li class="rounded-lg bg-white/70 px-2.5 py-2 text-xs dark:bg-gray-900/50">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-semibold tabular-nums" x-text="format(entry.total_paisa)"></span>
                    <span class="text-gray-500 dark:text-gray-400" x-text="new Date(entry.rungAt).toLocaleTimeString()"></span>
                </div>

                <p x-show="entry.customer" x-cloak class="text-gray-600 dark:text-gray-400" x-text="entry.customer"></p>

                <template x-if="entry.problem">
                    <div class="mt-1">
                        <p class="text-red-700 dark:text-red-400" x-text="entry.problem"></p>

                        <div class="mt-1 flex gap-2">
                            <button type="button" x-on:click="retry(entry)"
                                    class="rounded border border-gray-300 px-2 py-1 font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                {{ __('Try again') }}
                            </button>

                            @can('supervise')
                                <button type="button" x-on:click="discard(entry)"
                                        class="rounded border border-red-300 px-2 py-1 font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                                    {{ __('Throw it away') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </template>
            </li>
        </template>
    </ul>
</div>
