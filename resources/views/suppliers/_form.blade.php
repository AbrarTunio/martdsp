{{--
    Shared by create and edit. The opening balance is asked only once, when
    the supplier is added: after that the account moves only through bills,
    payments, returns and corrections, each of which leaves a line.
--}}
<div class="space-y-5">
    <x-card :title="__('Who they are')">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" :label="__('Name')" required
                     :hint="__('The name you know them by — usually the salesman.')">
                <x-text-input id="name" name="name" class="block w-full" required autofocus autocomplete="off"
                              :value="old('name', $supplier->name)" />
            </x-field>

            <x-field name="company" :label="__('Company')" :hint="__('Optional. The name printed on their bills.')">
                <x-text-input id="company" name="company" class="block w-full" autocomplete="off"
                              :value="old('company', $supplier->company)" />
            </x-field>

            {{-- The number is the one thing that is only ever this supplier's,
                 so it is what keeps the same salesman from being entered twice.
                 Stored as +92 and the number without the 0, however it is typed. --}}
            <x-field name="phone" :label="__('Phone')" required
                     :hint="__('Leave off the 0 in front. Two suppliers cannot share a number.')">
                <div class="flex gap-2">
                    <select aria-label="{{ __('Country code') }}"
                            class="w-24 shrink-0 rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <option value="+92">{{ \App\Support\PhoneNumber::COUNTRY_CODE }}</option>
                    </select>
                    <x-text-input id="phone" name="phone" type="tel" inputmode="numeric" maxlength="10" required
                                  class="block w-full min-w-0 font-mono" autocomplete="off" placeholder="3001234567"
                                  :value="\App\Support\PhoneNumber::national(old('phone', $supplier->phone))" />
                </div>
            </x-field>

            <x-field name="payment_terms_days" :label="__('Days to pay')"
                     :hint="__('How long they give you before a bill is due. 0 means cash on delivery.')">
                <x-text-input id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="365"
                              inputmode="numeric" class="block w-full"
                              :value="old('payment_terms_days', $supplier->payment_terms_days ?? 0)" />
            </x-field>

            <div class="sm:col-span-2">
                <x-field name="address" :label="__('Address')">
                    <x-text-input id="address" name="address" class="block w-full" autocomplete="off"
                                  :value="old('address', $supplier->address)" />
                </x-field>
            </div>

            <div class="sm:col-span-2">
                <x-field name="notes" :label="__('Notes')" :hint="__('Which days they visit, what they carry, anything worth remembering.')">
                    <textarea id="notes" name="notes" rows="2"
                              class="block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ old('notes', $supplier->notes) }}</textarea>
                </x-field>
            </div>

            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active ?? true))
                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                {{ __('Show them when entering a purchase') }}
            </label>
        </div>
    </x-card>

    @unless ($supplier->exists)
        <x-card :title="__('Starting balance')"
                :description="__('Only if there is already money between you — a bill from before you started using this app.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="opening_balance" :label="__('Amount')" :hint="__('Leave empty if you are settled.')">
                    <x-text-input id="opening_balance" name="opening_balance" inputmode="decimal" class="block w-full"
                                  autocomplete="off" placeholder="0.00" :value="old('opening_balance')" />
                </x-field>

                <fieldset>
                    <legend class="block text-sm font-medium">{{ __('Which way?') }}</legend>
                    <div class="mt-1.5 space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="opening_is_advance" value="0"
                                   @checked(! old('opening_is_advance'))
                                   class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('I owe them this much') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="opening_is_advance" value="1"
                                   @checked(old('opening_is_advance'))
                                   class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('I paid them in advance') }}
                        </label>
                    </div>
                </fieldset>
            </div>
        </x-card>
    @endunless
</div>
