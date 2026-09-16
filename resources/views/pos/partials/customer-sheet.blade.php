{{--
    Who is buying. Needed only for khata; a walk-in customer needs nothing.
    The list opens with whoever owes the most, since they are the ones who
    come back to add to it.
--}}
<x-pos.sheet name="customer" :title="__('Customer')">
    <template x-if="customer">
        <div class="mb-4 flex items-center gap-3 rounded-xl border border-brand-300 bg-brand-50 px-3 py-2.5 dark:border-brand-500/40 dark:bg-brand-500/10">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold" x-text="customer.label"></p>
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('Owes') }} <span x-text="customer.balance"></span></p>
            </div>
            <button type="button" x-on:click="clearCustomer()"
                    class="tap-target shrink-0 rounded-lg px-3 text-sm font-medium text-gray-700 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('Walk-in instead') }}
            </button>
        </div>
    </template>

    <div class="relative">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-gray-400" />
        <input x-ref="customerInput" type="search" autocomplete="off"
               x-model="customerTerm" x-on:input.debounce.250ms="searchCustomers()"
               placeholder="{{ __('Name or phone number') }}"
               class="block h-12 w-full rounded-xl border-gray-300 pl-10 text-base shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <ul class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800"
        x-show="customerResults.length > 0">
        <template x-for="result in customerResults" x-bind:key="result.id">
            <li>
                <button type="button" x-on:click="chooseCustomer(result)"
                        class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium" x-text="result.name"></span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="result.phone || @js(__('no phone'))"></span>
                    </span>
                    <span class="shrink-0 text-right text-sm tabular-nums"
                          x-bind:class="result.balance_paisa > 0 ? 'text-money-out font-semibold' : 'text-gray-500 dark:text-gray-400'"
                          x-text="result.balance_paisa > 0 ? result.balance : @js(__('owes nothing'))"></span>
                </button>
            </li>
        </template>
    </ul>

    <p x-show="! customerSearching && customerTerm.trim() !== '' && customerResults.length === 0" x-cloak
       class="mt-3 text-sm text-gray-500 dark:text-gray-400">
        {{ __('Nobody matches. Add them below.') }}
    </p>

    {{--
        Somebody new. The name is whatever is already in the search box above —
        that is where the cashier typed it looking for them, and a second name
        field beside it only ever gets the same thing typed in twice. So all
        that is left to fill in is the number, and if they searched by number,
        that is filled in for them too.
    --}}
    <div class="mt-5 rounded-xl border border-dashed border-gray-300 p-3 dark:border-gray-700"
         x-show="customerTerm.trim() !== ''" x-cloak>
        <p class="truncate text-sm font-semibold"
           x-text="typedName ? @js(__('Add')) + ' ' + typedName : @js(__('New customer'))"></p>

        <div class="mt-2 flex gap-2">
            <select aria-label="{{ __('Country code') }}"
                    class="tap-target w-24 shrink-0 rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="+92">{{ \App\Support\PhoneNumber::COUNTRY_CODE }}</option>
            </select>
            <input type="tel" inputmode="numeric" autocomplete="off" maxlength="10" x-model="newCustomerPhone"
                   x-on:input="newCustomerPhone = tidyNumber(newCustomerPhone)"
                   placeholder="3001234567" aria-label="{{ __('Phone') }}"
                   class="{{ $inputClass }} min-w-0 font-mono">
        </div>

        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400"
           x-text="typedName ? @js(__('Leave the 0 off the front of the number.')) : @js(__('Type their name in the box above to add them.'))"></p>

        <button type="button" x-on:click="addCustomer()" x-bind:disabled="addingCustomer || ! typedName"
                class="tap-target mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-40">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('Add and choose') }}
        </button>
    </div>
</x-pos.sheet>
