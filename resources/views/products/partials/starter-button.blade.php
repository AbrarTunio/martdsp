{{--
    "Seed Products": the everyday grocery list, put in with one press. It
    works once. Afterwards it stays on the screen as a plain "Already
    seeded" so the shopkeeper can see it was done, and it cannot be pressed.
--}}
@if ($starterSeeded)
    <span class="tap-target inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-gray-200 px-3 text-sm font-medium text-gray-400 dark:border-gray-800 dark:text-gray-500"
          aria-disabled="true" title="{{ __('The ready-made product list has already been added.') }}">
        <x-icon name="check" class="h-4 w-4" />
        {{ __('Already seeded') }}
    </span>
@else
    <form method="POST" action="{{ route('products.starter') }}"
          x-data="{ busy: false }"
          x-on:submit="if (! confirm(@js(__('Add :count everyday grocery products with their packing and starting prices? No stock is added. This can only be done once.', ['count' => $starterCount])))) { $event.preventDefault() } else { busy = true }">
        @csrf
        <button type="submit" x-bind:disabled="busy"
                class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-brand-300 bg-brand-50 px-3 text-sm font-semibold text-brand-700 hover:bg-brand-100 disabled:opacity-50 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-300 dark:hover:bg-brand-500/20">
            <x-icon name="sparkles" class="h-4 w-4" />
            <span x-text="busy ? @js(__('Adding products…')) : @js(__('Seed Products'))">{{ __('Seed Products') }}</span>
        </button>
    </form>
@endif
