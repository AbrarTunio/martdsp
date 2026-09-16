{{--
    Shared by create and edit. The opening balance is asked only once, when the
    khata is opened: after that the balance moves only through sales, payments,
    returns and corrections, each of which leaves a line anyone can read back.
--}}
<div class="space-y-5">
    <x-card :title="__('Who they are')">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" :label="__('Name')" required
                     :hint="__('What you would call out in the shop.')">
                <x-text-input id="name" name="name" class="block w-full" required autofocus autocomplete="off"
                              :value="old('name', $customer->name)" />
            </x-field>

            <x-field name="name_ur" :label="__('Name in Urdu')" :hint="__('Optional. Printed on their statement.')">
                <x-text-input id="name_ur" name="name_ur" class="block w-full" autocomplete="off" dir="rtl"
                              :value="old('name_ur', $customer->name_ur)" />
            </x-field>

            <x-field name="phone" :label="__('Phone')"
                     :hint="__('Leave off the 0 in front. Needed to send a khata reminder on WhatsApp, and it is what keeps one neighbour from getting two khatas.')">
                <div class="flex gap-2">
                    <select aria-label="{{ __('Country code') }}"
                            class="w-24 shrink-0 rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <option value="+92">{{ \App\Support\PhoneNumber::COUNTRY_CODE }}</option>
                    </select>
                    <x-text-input id="phone" name="phone" type="tel" inputmode="numeric" maxlength="10"
                                  class="block w-full min-w-0 font-mono" autocomplete="off" placeholder="3001234567"
                                  :value="\App\Support\PhoneNumber::national(old('phone', $customer->phone))" />
                </div>
            </x-field>

            <x-field name="credit_limit" :label="__('Credit limit')"
                     :hint="__('The most they may owe at one time. Leave empty for no limit.')">
                <x-text-input id="credit_limit" name="credit_limit" inputmode="decimal" class="block w-full"
                              autocomplete="off" placeholder="0.00"
                              :value="old('credit_limit', $customer->credit_limit_paisa ? number_format($customer->credit_limit_paisa / 100, 2, '.', '') : '')" />
            </x-field>

            <div class="sm:col-span-2">
                <x-field name="address" :label="__('Address')" :hint="__('Optional. The mohalla or a shop name is usually enough.')">
                    <x-text-input id="address" name="address" class="block w-full" autocomplete="off"
                                  :value="old('address', $customer->address)" />
                </x-field>
            </div>

            <div class="sm:col-span-2">
                <x-field name="notes" :label="__('Notes')" :hint="__('Who they are related to, when they usually pay, anything worth remembering.')">
                    <textarea id="notes" name="notes" rows="2"
                              class="block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ old('notes', $customer->notes) }}</textarea>
                </x-field>
            </div>

            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $customer->is_active ?? true))
                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                {{ __('Show them at the till') }}
            </label>
        </div>
    </x-card>

    @unless ($customer->exists)
        <x-card :title="__('Starting balance')"
                :description="__('What is already written on their page in your old register, from before this app.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="opening_balance" :label="__('Amount')" :hint="__('Leave empty if their khata is clear.')">
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
                            {{ __('They owe me this much') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="opening_is_advance" value="1"
                                   @checked(old('opening_is_advance'))
                                   class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('They have paid me in advance') }}
                        </label>
                    </div>
                </fieldset>
            </div>
        </x-card>
    @endunless
</div>
