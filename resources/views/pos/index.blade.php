@php
    $inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<x-app-layout :title="__('Sell')">
    <div x-data="pos(@js($config))"
         x-on:keydown.enter="guardEnter($event)"
         x-on:keydown.window="shortcut($event)"
         class="pb-24">

        {{-- Counter, customer and held baskets --}}
        <div class="flex flex-wrap items-center gap-2">
            @if ($registers->count() > 1)
                <form method="POST" action="{{ route('pos.register') }}" class="shrink-0">
                    @csrf
                    <label class="sr-only" for="pos-register">{{ __('Counter') }}</label>
                    <select id="pos-register" name="register_id" x-on:change="$el.form.requestSubmit()"
                            class="tap-target rounded-lg border-gray-300 py-0 text-sm font-medium shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        @foreach ($registers as $option)
                            <option value="{{ $option->id }}" @selected($option->is($register))>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <x-badge class="py-1">{{ $register->name }}</x-badge>
            @endif

            <button type="button" x-on:click="openCustomers()"
                    class="tap-target inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-lg border px-3 text-sm font-medium"
                    x-bind:class="customer
                        ? 'border-brand-300 bg-brand-50 text-brand-800 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-200'
                        : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'">
                <x-icon name="user" class="h-4.5 w-4.5 shrink-0" />
                <span class="truncate" x-text="customer ? customer.label : @js(__('Walk-in customer'))"></span>
            </button>

            <div class="ml-auto flex items-center gap-1">
                <a href="{{ route('drawer.show', $drawer) }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                   aria-label="{{ __('Cash drawer') }}">
                    <x-icon name="safe" class="h-5 w-5" />
                    <span class="hidden sm:inline">{{ __('Drawer') }}</span>
                </a>

                <button type="button" x-on:click="openHeld()"
                        class="tap-target relative inline-flex items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                    <x-icon name="pause" class="h-5 w-5" />
                    <span class="hidden sm:inline">{{ __('On hold') }}</span>
                    <span x-show="heldCount > 0" x-cloak x-text="heldCount"
                          class="grid h-5 min-w-5 place-items-center rounded-full bg-amber-500 px-1 text-xs font-semibold text-white"></span>
                </button>

                <button type="button" x-on:click="sheet = 'keys'"
                        class="tap-target hidden items-center rounded-lg px-2.5 text-sm font-medium text-gray-600 hover:bg-gray-100 lg:inline-flex dark:text-gray-300 dark:hover:bg-gray-800"
                        title="{{ __('Keyboard shortcuts (F1)') }}" aria-label="{{ __('Keyboard shortcuts') }}">
                    <kbd class="rounded border border-gray-300 px-1.5 py-0.5 font-sans text-xs font-semibold dark:border-gray-700">F1</kbd>
                </button>

                <x-ai-insight-button />
            </div>
        </div>

        {{-- Scan --}}
        <div class="mt-3 flex gap-2">
            <div class="relative min-w-0 flex-1">
                <x-icon name="scan" class="pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-gray-400" />
                <input x-ref="scanner" x-model="code" type="text" inputmode="search" autocomplete="off" autocapitalize="off"
                       spellcheck="false" enterkeyhint="go"
                       placeholder="{{ __('Scan a barcode, or type a name') }}"
                       x-on:keydown.enter.prevent="scan()"
                       class="block h-12 w-full rounded-xl border-gray-300 pl-10 font-mono text-base shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>

            <button type="button" x-on:click="openSearch(code)"
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    aria-label="{{ __('Search for an item') }}" title="{{ __('Search (F2)') }}">
                <x-icon name="search" class="h-5 w-5" />
            </button>

            <button type="button" x-show="camera.supported" x-cloak x-on:click="openCamera()"
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-gray-900 text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600"
                    aria-label="{{ __('Scan with the camera') }}">
                <x-icon name="camera" class="h-5 w-5" />
            </button>
        </div>

        @include('pos.partials.queue-banner')

        <template x-if="flash">
            <div class="mt-2 flex items-start gap-2 rounded-lg border px-3 py-2 text-sm"
                 x-bind:class="flash.tone === 'success'
                     ? 'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200'
                     : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'"
                 role="status">
                <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0" />
                <p class="flex-1" x-text="flash.text"></p>
                <button type="button" x-on:click="flash = null" class="shrink-0 opacity-60 hover:opacity-100" aria-label="{{ __('Dismiss') }}">
                    <x-icon name="close" class="h-4 w-4" />
                </button>
            </div>
        </template>

        {{-- Basket --}}
        <template x-if="isEmpty">
            <div class="mt-4 rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-gray-700">
                <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                    <x-icon name="cart" class="h-6 w-6" />
                </span>
                <p class="text-sm font-semibold">{{ __('Ready for the next customer') }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Scan the first item. Loose items like rice or eggs: tap the magnifier and search by name.') }}
                </p>
            </div>
        </template>

        <ul class="mt-3 space-y-2">
            <template x-for="(line, index) in lines" x-bind:key="line.uid">
                @include('pos.partials.cart-line')
            </template>
        </ul>

        {{-- The total, always in reach --}}
        <div class="fixed inset-x-0 bottom-[calc(var(--spacing-tabbar)+env(safe-area-inset-bottom,0px))] z-30 border-t border-gray-200 bg-white/95 px-4 py-2.5 backdrop-blur lg:bottom-0 lg:left-64 lg:px-8 dark:border-gray-800 dark:bg-gray-900/95">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="confirmClear()" x-bind:disabled="isEmpty"
                        class="tap-target grid shrink-0 place-items-center rounded-lg text-gray-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-500/10"
                        aria-label="{{ __('Empty the basket') }}">
                    <x-icon name="trash" class="h-5 w-5" />
                </button>

                <button type="button" x-on:click="hold()" x-bind:disabled="isEmpty || busy"
                        class="tap-target hidden shrink-0 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 disabled:opacity-40 sm:inline-flex dark:border-gray-700 dark:hover:bg-gray-800">
                    <x-icon name="pause" class="h-4.5 w-4.5" />
                    {{ __('Hold') }}
                    <kbd class="hidden rounded bg-gray-100 px-1.5 py-0.5 font-sans text-xs font-medium text-gray-500 lg:inline dark:bg-gray-800 dark:text-gray-400">F6</kbd>
                </button>

                <div class="min-w-0 flex-1 text-right">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="itemCount.toLocaleString('en-PK')"></span> {{ __('items') }}
                        <template x-if="bill.discount_paisa > 0">
                            <span>· <span x-text="format(bill.discount_paisa)"></span> {{ __('off') }}</span>
                        </template>
                    </p>
                    <p class="truncate text-2xl font-bold tabular-nums tracking-tight" x-text="format(total)"></p>
                </div>

                <button type="button" x-on:click="openPay()" x-bind:disabled="isEmpty"
                        class="inline-flex h-14 shrink-0 items-center gap-2 rounded-xl bg-brand-600 px-6 text-lg font-bold text-white shadow-sm hover:bg-brand-700 disabled:opacity-40">
                    {{ __('Pay') }}
                    <kbd class="hidden rounded bg-white/20 px-1.5 py-0.5 font-sans text-xs font-medium lg:inline">F9</kbd>
                </button>
            </div>
        </div>

        @include('pos.partials.pay-sheet')
        @include('pos.partials.done-sheet')
        @include('pos.partials.search-sheet')
        @include('pos.partials.customer-sheet')
        @include('pos.partials.held-sheet')
        @include('pos.partials.camera-sheet')
        @include('pos.partials.keys-sheet')

        {{-- Receipts print through this, so the till never leaves the page. --}}
        <iframe x-ref="printFrame" title="{{ __('Receipt') }}" class="no-print fixed h-0 w-0 border-0 opacity-0" aria-hidden="true" tabindex="-1"></iframe>
    </div>
</x-app-layout>
