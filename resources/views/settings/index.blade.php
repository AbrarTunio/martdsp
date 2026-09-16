@php
    /*
     | Setting keys contain literal dots ("shop.name"), which old() cannot
     | reach because it treats dots as array traversal. So the previous input
     | is pulled out once as a plain array and indexed directly.
     */
    $old = (array) old('settings', []);
    $current = fn (string $key) => $old[$key] ?? $values[$key];
@endphp

<x-app-layout :title="__('Settings')">
    <x-flash />

    <x-page-header :title="__('Settings')"
                   :description="__('Your shop details, tax and receipt options.')">
        @can('manage-users')
            <a href="{{ route('settings.staff.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="user" class="h-4.5 w-4.5" />
                {{ __('Staff') }}
            </a>
        @endcan

        <a href="{{ route('settings.registers.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="cart" class="h-4.5 w-4.5" />
            {{ __('Counters') }}
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('settings.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('PUT')

        <x-card :title="__('Shop')"
                :description="__('These details are printed on every receipt, invoice and khata statement.')">
            <div class="space-y-4">
                <x-field name="settings.shop.name" :label="__('Shop name')" required>
                    <x-text-input name="settings[shop.name]" id="settings.shop.name" class="block w-full"
                                  :value="$current('shop.name')" required />
                </x-field>

                <x-field name="settings.shop.address" :label="__('Address')">
                    <textarea name="settings[shop.address]" id="settings.shop.address" rows="2"
                              class="block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $current('shop.address') }}</textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="settings.shop.phone" :label="__('Phone')">
                        <x-text-input name="settings[shop.phone]" id="settings.shop.phone" class="block w-full"
                                      inputmode="tel" :value="$current('shop.phone')" />
                    </x-field>

                    <x-field name="settings.shop.ntn" :label="__('NTN')" :hint="__('FBR tax number')">
                        <x-text-input name="settings[shop.ntn]" id="settings.shop.ntn" class="block w-full"
                                      :value="$current('shop.ntn')" />
                    </x-field>

                    <x-field name="settings.shop.strn" :label="__('STRN')" :hint="__('Sales-tax number')">
                        <x-text-input name="settings[shop.strn]" id="settings.shop.strn" class="block w-full"
                                      :value="$current('shop.strn')" />
                    </x-field>
                </div>
            </div>
        </x-card>

        <x-card :title="__('Tax and rounding')"
                :description="__('Worth getting right before your first sale. Changing it later will not alter sales already recorded.')">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="settings.tax.gst_rate" :label="__('GST rate (%)')" required
                             :hint="__('The standard rate in Pakistan is 18%.')">
                        <x-text-input name="settings[tax.gst_rate]" id="settings.tax.gst_rate" type="number"
                                      step="0.01" min="0" max="100" inputmode="decimal" class="block w-full"
                                      :value="$current('tax.gst_rate')" required />
                    </x-field>

                    <x-field name="settings.receipt.paper_width" :label="__('Receipt paper')">
                        <select name="settings[receipt.paper_width]" id="settings.receipt.paper_width"
                                class="block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($paperWidths as $value => $label)
                                <option value="{{ $value }}" @selected((string) $current('receipt.paper_width') === (string) $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <x-settings.toggle
                    name="settings[tax.prices_include_tax]"
                    :label="__('Shelf prices already include GST')"
                    :hint="__('Most Pakistani shops price this way. Leave it on unless you add tax at the till.')"
                    :checked="(bool) $current('tax.prices_include_tax')" />

                <x-settings.toggle
                    name="settings[sales.round_to_rupee]"
                    :label="__('Round the bill total to the nearest rupee')"
                    :hint="__('Paisa coins are not in circulation. The rounding appears as its own line on the receipt rather than being hidden in the total.')"
                    :checked="(bool) $current('sales.round_to_rupee')" />

                <x-settings.toggle
                    name="settings[sales.allow_negative_stock]"
                    :label="__('Allow selling an item that shows zero stock')"
                    :hint="__('Useful while your stock counts are still being corrected, but it hides genuine shortages. Turn it off once your counts are trustworthy.')"
                    :checked="(bool) $current('sales.allow_negative_stock')" />
            </div>
        </x-card>

        <x-card :title="__('Till and khata')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="settings.sales.cashier_discount_limit" :label="__('Most a cashier may discount (%)')" required
                         :hint="__('Of the whole bill. Owners and managers are not limited.')">
                    <x-text-input name="settings[sales.cashier_discount_limit]" id="settings.sales.cashier_discount_limit"
                                  type="number" step="0.5" min="0" max="100" inputmode="decimal" class="block w-full"
                                  :value="$current('sales.cashier_discount_limit')" required />
                </x-field>

                <x-field name="settings.khata.credit_days" :label="__('Days to pay a khata bill')" required
                         :hint="__('After this many days an unpaid khata sale shows as overdue.')">
                    <x-text-input name="settings[khata.credit_days]" id="settings.khata.credit_days"
                                  type="number" step="1" min="0" max="365" inputmode="numeric" class="block w-full"
                                  :value="$current('khata.credit_days')" required />
                </x-field>
            </div>
        </x-card>

        <x-card :title="__('Cash drawer')"
                :description="__('How the drawer is counted at the end of a shift.')">
            <div class="space-y-4">
                <x-field name="settings.drawer.variance_tolerance" :label="__('A count may be out by up to (Rs.)')" required
                         :hint="__('A bigger gap, short or over, needs a reason and a manager to sign it off.')">
                    <x-text-input name="settings[drawer.variance_tolerance]" id="settings.drawer.variance_tolerance"
                                  type="number" step="1" min="0" max="100000" inputmode="numeric" class="block w-full sm:w-1/2"
                                  :value="$current('drawer.variance_tolerance')" required />
                </x-field>

                <x-settings.toggle
                    name="settings[drawer.blind_count]"
                    :label="__('Cashiers count without seeing the expected amount')"
                    :hint="__('Recommended. A cashier who can see what the drawer should hold tends to count until it matches. Managers always see it.')"
                    :checked="(bool) $current('drawer.blind_count')" />

                <x-settings.toggle
                    name="settings[drawer.pulse_on_cash]"
                    :label="__('Pop the drawer by itself on a cash sale')"
                    :hint="__('Needs a drawer wired into a counter printer. Card and khata bills never open it.')"
                    :checked="(bool) $current('drawer.pulse_on_cash')" />
            </div>
        </x-card>

        <x-card :title="__('Receipt')">
            <div class="space-y-4">
                <x-field name="settings.receipt.footer_note" :label="__('Footer message')"
                         :hint="__('Printed at the bottom of every receipt.')">
                    <x-text-input name="settings[receipt.footer_note]" id="settings.receipt.footer_note"
                                  class="block w-full" :value="$current('receipt.footer_note')" />
                </x-field>

                <x-settings.toggle
                    name="settings[receipt.auto_print]"
                    :label="__('Print the slip as soon as a bill is paid')"
                    :hint="__('Only works at a counter with a printer set up. Selling from a phone still prints through the browser.')"
                    :checked="(bool) $current('receipt.auto_print')" />
            </div>
        </x-card>

        <div class="flex justify-end">
            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                {{ __('Save settings') }}
            </button>
        </div>
    </form>

    <div class="mt-5 space-y-5">
        <x-card :title="__('Printing')"
                :description="__('Where the shop sends a receipt, and which counter uses which printer.')">
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('settings.printers.index') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="printer" class="h-4.5 w-4.5" />
                    {{ __('Printers') }}
                </a>

                <a href="{{ route('settings.registers.index') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('Counters') }}
                </a>
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Add the thermal printer once, send it a test print, and pop the cash drawer to prove the wiring. Until a printer is set up, every receipt goes through the browser print dialog — which is what a phone uses anyway.') }}
            </p>
        </x-card>

        <x-card :title="__('AI insights')"
                :description="__('The Insights button on every page, and the account it writes through.')">
            <a href="{{ route('settings.ai.edit') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="sparkles" class="h-4.5 w-4.5" />
                {{ __('Set up AI insights') }}
            </a>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Pick a company — Claude, Gemini, OpenAI, Groq, Z.ai or Ollama Cloud — paste your own key, fetch the models it can reach, test one, and set the most you will spend in a month. Without a key the button still works: it explains the page from the shop\'s own checks.') }}
            </p>
        </x-card>

        <x-card :title="__('Backups')"
                :description="__('A copy of the whole shop, written to this computer every night.')">
            <a href="{{ route('settings.backups.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4.5 w-4.5" />
                {{ __('Backups') }}
            </a>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Choose the folder — a USB drive is the point of it — how many days of copies to keep, and the hour to write them. You can also make a copy on the spot and download it, which is worth doing before a stock take.') }}
            </p>
        </x-card>
    </div>
</x-app-layout>
