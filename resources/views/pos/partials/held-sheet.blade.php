{{-- Baskets parked while a customer fetches something they forgot. Shared by every counter. --}}
<x-pos.sheet name="held" :title="__('Baskets on hold')">
    <template x-if="! isEmpty">
        <button type="button" x-on:click="hold()" x-bind:disabled="busy"
                class="tap-target mb-4 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-40 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            <x-icon name="pause" class="h-4.5 w-4.5" />
            {{ __('Put this basket on hold') }}
        </button>
    </template>

    <p x-show="heldLoading" x-cloak class="text-sm text-gray-500 dark:text-gray-400">{{ __('Loading…') }}</p>

    <template x-if="! heldLoading && held.length === 0">
        <p class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Nothing on hold. When a customer steps away, hold their basket and serve the next one.') }}
        </p>
    </template>

    <ul class="space-y-2">
        <template x-for="entry in held" x-bind:key="entry.id">
            <li class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" x-text="entry.customer"></p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="entry.items"></span> {{ __('lines') }}
                            · <span x-text="entry.held_at"></span>
                            <template x-if="entry.by"><span>· <span x-text="entry.by"></span></span></template>
                            <template x-if="entry.register"><span>· <span x-text="entry.register"></span></span></template>
                        </p>
                        <template x-if="entry.note">
                            <p class="mt-1 text-xs italic text-gray-600 dark:text-gray-400" x-text="entry.note"></p>
                        </template>
                    </div>
                    <p class="shrink-0 text-sm font-semibold tabular-nums" x-text="entry.total"></p>
                </div>

                <div class="mt-2 flex gap-2">
                    <button type="button" x-on:click="resume(entry)"
                            class="tap-target inline-flex flex-1 items-center justify-center rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">
                        {{ __('Bring back') }}
                    </button>
                    <button type="button" x-on:click="discard(entry)"
                            class="tap-target grid shrink-0 place-items-center rounded-lg border border-gray-300 px-3 text-gray-500 hover:bg-red-50 hover:text-red-600 dark:border-gray-700 dark:hover:bg-red-500/10"
                            aria-label="{{ __('Throw away') }}">
                        <x-icon name="trash" class="h-4.5 w-4.5" />
                    </button>
                </div>
            </li>
        </template>
    </ul>
</x-pos.sheet>
