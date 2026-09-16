@php
    use App\Support\Money;

    $input = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<x-app-layout :title="__('Count the drawer')">
    <x-flash />

    <x-page-header :title="__('Count the :register drawer', ['register' => $drawer->register?->name])"
                   :description="__('Take every note and coin out and type how many of each you have. The total adds up as you go.')">
        <x-ai-insight-button />

        <a href="{{ route('drawer.show', $drawer) }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back') }}
        </a>
    </x-page-header>

    @unless ($seesExpected)
        <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200">
            {{ __('Just count what is there. You will not see what the drawer should hold — that keeps the count honest, and a manager checks it afterwards.') }}
        </div>
    @endunless

    <form method="POST" action="{{ route('drawer.close.store', $drawer) }}"
          x-data="drawerCount(@js($config))"
          x-on:submit="if (! confirm(@js(__('Close this drawer with the count you entered? This cannot be changed afterwards.')))) $event.preventDefault()"
          class="mt-4 grid gap-4 lg:grid-cols-5">
        @csrf

        <div class="lg:col-span-3">
            <x-card :title="__('Notes and coins')" :padded="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($config['denominations'] as $value)
                        <li class="flex items-center gap-2 px-3 py-2 sm:gap-3 sm:px-4">
                            <label for="count-{{ $value }}" class="w-20 shrink-0 text-sm font-semibold tabular-nums sm:w-24">
                                Rs. {{ number_format($value) }}
                            </label>

                            <div class="flex items-center gap-1">
                                <button type="button" x-on:click="step({{ $value }}, -1)"
                                        class="tap-target grid w-11 place-items-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                        aria-label="{{ __('One fewer Rs. :value', ['value' => number_format($value)]) }}">
                                    <x-icon name="minus" class="h-4 w-4" />
                                </button>
                                <input id="count-{{ $value }}" name="counts[{{ $value }}]" type="number" min="0" max="100000" step="1" inputmode="numeric"
                                       x-model="counts[{{ $value }}]" value="{{ $config['old']['counts'][$value] ?? '' }}" placeholder="0"
                                       class="tap-target w-16 rounded-md border-gray-300 text-center text-sm shadow-xs tabular-nums focus:border-brand-500 focus:ring-brand-500 sm:w-20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <button type="button" x-on:click="step({{ $value }}, 1)"
                                        class="tap-target grid w-11 place-items-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                        aria-label="{{ __('One more Rs. :value', ['value' => number_format($value)]) }}">
                                    <x-icon name="plus" class="h-4 w-4" />
                                </button>
                            </div>

                            <span class="ml-auto text-right text-sm tabular-nums"
                                  x-bind:class="subtotal({{ $value }}) > 0 ? 'font-medium' : 'text-gray-400'"
                                  x-text="format(subtotal({{ $value }}))"></span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
            <x-input-error :messages="$errors->get('counts')" class="mt-1.5" />
        </div>

        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 lg:sticky lg:top-4 dark:border-gray-800 dark:bg-gray-900">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="font-semibold">{{ __('You counted') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums" x-text="format(counted)">{{ Money::withSymbol(0) }}</dd>
                    </div>

                    @if ($seesExpected)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-gray-600 dark:text-gray-400">{{ __('Should be in the drawer') }}</dt>
                            <dd class="tabular-nums">{{ Money::withSymbol((int) $config['expectedPaisa']) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-t border-gray-200 pt-2 dark:border-gray-700">
                            <dt class="text-gray-600 dark:text-gray-400"
                                x-text="variance === 0 ? @js(__('Exact')) : (variance < 0 ? @js(__('Short')) : @js(__('Over')))"></dt>
                            <dd class="font-semibold tabular-nums"
                                x-bind:class="variance === 0 ? 'text-money-in' : (beyondTolerance ? 'text-money-out' : 'text-amber-600 dark:text-amber-400')"
                                x-text="signed(variance)"></dd>
                        </div>
                        <p x-show="beyondTolerance" x-cloak
                           class="rounded-md bg-red-50 px-2 py-1.5 text-xs text-red-900 dark:bg-red-500/10 dark:text-red-200">
                            {{ __('That is more than the :amount allowed. Count again — and if it is still out, say why below. A manager will be asked to sign it off.', ['amount' => Money::withSymbol($config['tolerancePaisa'])]) }}
                        </p>
                    @endif
                </dl>
            </div>

            <x-card>
                <div class="space-y-3">
                    <div>
                        <label for="left_in_drawer" class="block text-sm font-medium">{{ __('Leave in the drawer for the next shift (Rs.)') }}</label>
                        <input id="left_in_drawer" name="left_in_drawer" type="text" inputmode="decimal" required autocomplete="off"
                               x-model="left" x-on:input="leftTouched = true" value="{{ old('left_in_drawer') }}"
                               class="{{ $input }} mt-1.5 tabular-nums">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Usually the same change you started with (:amount).', ['amount' => Money::withSymbol($config['floatPaisa'])]) }}
                        </p>
                        <p x-show="leftTooMuch" x-cloak class="mt-1 text-xs text-red-600 dark:text-red-400">{{ __('You cannot leave more than you counted.') }}</p>
                        <x-input-error :messages="$errors->get('left_in_drawer')" class="mt-1.5" />
                    </div>

                    <div class="flex items-baseline justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800/60">
                        <span class="text-gray-600 dark:text-gray-400">{{ __('Goes to the safe') }}</span>
                        <span class="font-semibold tabular-nums" x-text="format(takenPaisa)"></span>
                    </div>

                    <div>
                        <label for="reason" class="block text-sm font-medium">
                            {{ __('Anything to explain?') }}
                            @if ($seesExpected)
                                <span x-show="beyondTolerance" x-cloak class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                            @endif
                        </label>
                        <input id="reason" name="reason" maxlength="255" autocomplete="off" value="{{ old('reason') }}"
                               @if ($seesExpected) x-bind:required="beyondTolerance" @endif
                               placeholder="{{ __('e.g. gave Rs. 500 change instead of Rs. 50') }}"
                               class="{{ $input }} mt-1.5">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Needed when the count is out by more than the allowed amount.') }}</p>
                        <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                    </div>

                    <button type="submit" x-bind:disabled="leftTooMuch"
                            class="tap-target inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                        <x-icon name="lock" class="h-4.5 w-4.5" />
                        {{ __('Close the drawer') }}
                    </button>
                </div>
            </x-card>
        </div>
    </form>
</x-app-layout>
