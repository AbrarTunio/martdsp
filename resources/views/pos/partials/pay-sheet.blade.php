{{--
    Taking the money. Cash is the first row, focused, with the likely notes one
    tap away; card, wallets and khata are chosen from the chips below it and
    take the whole bill, which switches the cash box off so the same amount
    cannot be entered twice. Typing a smaller amount on one of those rows hands
    the rest back to cash, which is how a bill gets split. Enter in the cash
    box completes the sale.
--}}
<x-pos.sheet name="pay" :title="__('Take payment')">
    <div class="text-center">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('To pay') }}</p>
        <p class="text-4xl font-bold tabular-nums tracking-tight" x-text="format(total)"></p>
    </div>

    <dl class="mt-3 space-y-1 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800/60">
        <div class="flex justify-between gap-3">
            <dt class="text-gray-600 dark:text-gray-400">{{ __('Items') }}</dt>
            <dd class="tabular-nums" x-text="format(bill.subtotal_paisa)"></dd>
        </div>
        <template x-if="bill.discount_paisa > 0">
            <div class="flex justify-between gap-3 text-money-in">
                <dt>{{ __('Discount') }}</dt>
                <dd class="tabular-nums" x-text="'−' + format(bill.discount_paisa)"></dd>
            </div>
        </template>
        <template x-if="bill.tax_paisa > 0">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600 dark:text-gray-400"
                    x-text="config.pricesIncludeTax ? @js(__('GST (included)')) : @js(__('GST'))"></dt>
                <dd class="tabular-nums" x-text="(config.pricesIncludeTax ? '' : '+') + format(bill.tax_paisa)"></dd>
            </div>
        </template>
        <template x-if="bill.round_off_paisa !== 0">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Rounded') }}</dt>
                <dd class="tabular-nums" x-text="(bill.round_off_paisa > 0 ? '−' : '+') + format(Math.abs(bill.round_off_paisa))"></dd>
            </div>
        </template>
    </dl>

    <div class="mt-3">
        <label for="pos-bill-discount" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Discount on the whole bill') }}</label>
        <input id="pos-bill-discount" type="text" inputmode="decimal" autocomplete="off" maxlength="20"
               x-model="billDiscount" placeholder="{{ __('50 or 5%') }}"
               class="{{ $inputClass }} mt-1">
        <template x-if="discountWarning">
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-300" x-text="discountWarning"></p>
        </template>
    </div>

    {{-- Cash. Switched off while a card or a wallet is holding the whole bill. --}}
    <template x-if="cashRow">
        <div class="mt-4">
            <label for="pos-cash" class="block text-sm font-semibold"
                   x-bind:class="! cashIsOpen && 'text-gray-400 dark:text-gray-600'">{{ __('Cash received') }}</label>
            <input id="pos-cash" x-ref="cashInput" type="text" inputmode="decimal" autocomplete="off"
                   x-model="cashRow.amount" placeholder="0" x-on:keydown.enter.prevent="complete()"
                   x-on:focus="$el.select()" x-bind:disabled="! cashIsOpen"
                   class="mt-1 block h-14 w-full rounded-xl border-gray-300 text-right text-2xl font-semibold tabular-nums shadow-xs focus:border-brand-500 focus:ring-brand-500 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:disabled:border-gray-800 dark:disabled:bg-gray-800/60 dark:disabled:text-gray-600">

            <template x-if="! cashIsOpen">
                <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                    {{ __('The whole bill is on') }} <span class="font-semibold" x-text="billIsOnLabel"></span>.
                    {{ __('Take that off to count cash instead.') }}
                </p>
            </template>

            <div class="mt-2 grid grid-cols-4 gap-1.5">
                <template x-for="(amount, position) in quickCash" x-bind:key="amount">
                    <button type="button" x-on:click="setCash(amount)" x-bind:disabled="! cashIsOpen"
                            class="h-11 rounded-lg border border-gray-300 px-1 text-sm font-semibold tabular-nums hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:border-gray-700 dark:hover:bg-gray-800"
                            x-bind:class="position === 0 && cashIsOpen && 'border-brand-400 bg-brand-50 text-brand-800 dark:border-brand-500/50 dark:bg-brand-500/10 dark:text-brand-200'"
                            x-text="position === 0 ? @js(__('Exact')) : $money(amount).replace('.00', '')"></button>
                </template>
            </div>
        </div>
    </template>

    {{-- Other ways to pay --}}
    <div class="mt-4">
        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Paid another way, or split') }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-500">{{ __('Tap one to put the bill on it. Tap it again to go back to cash.') }}</p>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
            <template x-for="option in tenders.filter((candidate) => ! candidate.is_cash)" x-bind:key="option.value">
                <button type="button" x-on:click="addTender(option.value)"
                        class="tap-target rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                        x-bind:class="payments.some((row) => row.method === option.value) && 'border-brand-400 bg-brand-50 text-brand-800 dark:border-brand-500/50 dark:bg-brand-500/10 dark:text-brand-200'"
                        x-bind:aria-pressed="payments.some((row) => row.method === option.value)"
                        x-text="option.label"></button>
            </template>
        </div>
    </div>

    <ul class="mt-3 space-y-2">
        <template x-for="row in payments.filter((candidate) => candidate.method !== 'cash')" x-bind:key="row.uid">
            <li class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <p class="w-24 shrink-0 text-sm font-semibold" x-text="tender(row.method)?.label"></p>
                    <input type="text" inputmode="decimal" autocomplete="off" x-model="row.amount" placeholder="0"
                           x-on:focus="$el.select()"
                           class="h-11 min-w-0 flex-1 rounded-md border-gray-300 text-right text-base font-semibold tabular-nums shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                           x-bind:aria-label="`${tender(row.method)?.label} {{ __('amount') }}`">
                    <button type="button" x-on:click="removeTender(row)"
                            class="tap-target grid shrink-0 place-items-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                            aria-label="{{ __('Remove') }}">
                        <x-icon name="close" class="h-4.5 w-4.5" />
                    </button>
                </div>

                <template x-if="tender(row.method)?.needs_customer">
                    <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                        {{ __('On the khata of') }}
                        <button type="button" x-on:click="openCustomers()" class="font-semibold text-brand-700 hover:underline dark:text-brand-400"
                                x-text="customer ? customer.label : @js(__('choose a customer'))"></button>
                        <template x-if="customer">
                            <span>· {{ __('owes') }} <span x-text="customer.balance"></span> {{ __('now') }}</span>
                        </template>
                    </p>
                </template>

                <template x-if="! tender(row.method)?.needs_customer">
                    <input type="text" autocomplete="off" maxlength="60" x-model="row.reference"
                           placeholder="{{ __('Reference or last 4 digits (optional)') }}"
                           class="mt-1.5 h-10 w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                </template>
            </li>
        </template>
    </ul>

    {{-- Where that leaves us --}}
    <div class="mt-4 space-y-2">
        <template x-if="shortPaisa > 0">
            <div class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2.5 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                <span class="text-sm font-medium">{{ __('Still to pay') }}</span>
                <span class="text-lg font-bold tabular-nums" x-text="format(shortPaisa)"></span>
            </div>
        </template>

        <template x-if="changePaisa > 0">
            <div class="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2.5 text-brand-900 dark:bg-brand-500/10 dark:text-brand-200">
                <span class="text-sm font-medium">{{ __('Change to give back') }}</span>
                <span class="text-2xl font-bold tabular-nums" x-text="format(changePaisa)"></span>
            </div>
        </template>

        <template x-if="nonCashPaisa > total">
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">
                {{ __('Card, wallet and khata together come to more than the bill. Only cash can be given back as change.') }}
            </p>
        </template>

        <template x-if="khataPaisa > 0 && ! customer">
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">
                {{ __('Choose the customer whose khata this goes on.') }}
            </p>
        </template>

        <template x-if="creditWarning()">
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" x-text="creditWarning()"></p>
        </template>

        <template x-if="payError">
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-800 dark:bg-red-500/10 dark:text-red-200" role="alert" x-text="payError"></p>
        </template>
    </div>

    <div class="mt-4">
        <label for="pos-note" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Note on the bill (optional)') }}</label>
        <input id="pos-note" type="text" autocomplete="off" maxlength="255" x-model="note" class="{{ $inputClass }} mt-1">
    </div>

    <x-slot:footer>
        <div class="flex gap-2">
            <button type="button" x-on:click="hold()" x-bind:disabled="busy || paying"
                    class="inline-flex h-14 shrink-0 items-center gap-1.5 rounded-xl border border-gray-300 px-4 text-sm font-semibold hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="pause" class="h-4.5 w-4.5" />
                {{ __('Hold') }}
            </button>

            <button type="button" x-on:click="complete()" x-bind:disabled="! canComplete"
                    class="inline-flex h-14 flex-1 items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 text-lg font-bold text-white shadow-sm hover:bg-brand-700 disabled:opacity-40">
                <x-icon name="check" class="h-5 w-5" />
                <span x-text="paying ? @js(__('Saving…')) : @js(__('Complete sale'))"></span>
            </button>
        </div>
    </x-slot:footer>
</x-pos.sheet>
