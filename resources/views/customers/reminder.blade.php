@php
    use App\Support\Money;

    $balance = (int) $customer->balance_paisa;
    $whatsappLink = $whatsapp ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($message) : null;
    $smsLink = $customer->phone ? 'sms:'.preg_replace('/[^0-9+]/', '', $customer->phone).'?body='.rawurlencode($message) : null;
@endphp

<x-app-layout :title="__('Remind :name', ['name' => $customer->name])">
    <x-flash />

    <x-page-header :title="__('Khata reminder')"
                   :description="__('The shop sends this itself, from its own number. All this page does is write the words and get the amount right.')">
        <a href="{{ route('customers.show', $customer) }}"
           class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to the khata') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="__('They owe')" :value="Money::withSymbol(max(0, $balance))" icon="book"
                     :tone="$balance > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Overdue')" :value="Money::rounded($aging->overduePaisa())" icon="alert"
                     :tone="$aging->isOverdue() ? 'danger' : 'neutral'"
                     :hint="$aging->isOverdue() ? trans_choice('oldest is :count day late|oldest is :count days late', $aging->daysLate(), ['count' => $aging->daysLate()]) : null" />

        <x-stat-card :label="__('Phone')" :value="$customer->phone ? \App\Support\PhoneNumber::forHumans($customer->phone) : __('Not saved')" icon="user"
                     :tone="$whatsapp ? 'neutral' : 'warning'"
                     :hint="$whatsapp ? __('Ready for WhatsApp') : __('Add a phone number to send this.')" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-5"
         x-data="{
             text: @js($message),
             labels: @js(['copy' => __('Copy the text'), 'copied' => __('Copied')]),
             prefix: @js($whatsapp ? 'https://wa.me/'.$whatsapp.'?text=' : ''),
             done: false,
             whatsappLink() { return this.prefix + encodeURIComponent(this.text); },
             copy() {
                 navigator.clipboard?.writeText(this.text).then(() => {
                     this.done = true;
                     setTimeout(() => this.done = false, 2000);
                 });
             },
         }">
        <div class="lg:col-span-3">
            <x-card :title="__('The message')"
                    :description="__('Change the wording if you like. Copying or sending uses whatever is in this box.')">
                <textarea x-model="text" rows="12"
                          class="block w-full rounded-md border-gray-300 font-sans text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $message }}</textarea>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" x-on:click="copy()"
                            class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                        <x-icon name="receipt" class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
                        <span x-text="done ? labels.copied : labels.copy">{{ __('Copy the text') }}</span>
                    </button>

                    @if ($whatsappLink)
                        <a :href="whatsappLink()" href="{{ $whatsappLink }}" target="_blank" rel="noopener"
                           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon name="chevron-right" class="h-4.5 w-4.5" />
                            {{ __('Open in WhatsApp') }}
                        </a>
                    @endif

                    @if ($smsLink)
                        <a href="{{ $smsLink }}"
                           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800 sm:hidden">
                            <x-icon name="chevron-right" class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
                            {{ __('Send as SMS') }}
                        </a>
                    @endif
                </div>

                @unless ($whatsapp)
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No usable phone number is saved, so this can only be copied. Add one from Edit and the WhatsApp button appears.') }}
                    </p>
                @endunless
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card :title="__('What they owe')" :padded="false">
                <ul>
                    @foreach ($aging->lines() as $line)
                        @continue($line['paisa'] === 0)
                        <li class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 dark:border-gray-800">
                            <span class="text-sm">{{ $line['label'] }}</span>
                            <x-money :paisa="$line['paisa']" class="text-sm font-medium tabular-nums" />
                        </li>
                    @endforeach

                    <li class="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-2.5 dark:border-gray-700">
                        <span class="text-sm font-semibold">{{ __('Total') }}</span>
                        <x-money :paisa="$aging->owedPaisa" class="text-sm font-semibold tabular-nums" />
                    </li>
                </ul>
            </x-card>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Nothing is sent from here and nothing is recorded. Sending is between you and your customer, on your own phone.') }}
            </p>
        </div>
    </div>
</x-app-layout>

