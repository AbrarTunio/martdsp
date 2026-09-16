@php
    use App\Models\Purchase;
    use App\Models\PurchaseReturn;
    use App\Support\Money;

    $balance = (int) $supplier->balance_paisa;
    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $openForm = old('direction') ? 'adjust' : (old('method') ? 'pay' : null);
@endphp

<x-app-layout :title="$supplier->name">
    <x-flash />

    <x-page-header :title="$supplier->displayName()"
                   :description="collect([\App\Support\PhoneNumber::forHumans($supplier->phone), $supplier->address])->filter()->implode(' · ') ?: __('No phone or address saved.')">
        <x-ai-insight-button />

        <a href="{{ route('suppliers.edit', $supplier) }}"
           class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Edit') }}
        </a>

        <a href="{{ route('purchases.create', ['supplier' => $supplier->id]) }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="truck" class="h-4.5 w-4.5" />
            {{ __('New delivery') }}
        </a>
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        @unless ($supplier->is_active)
            <x-badge>{{ __('Hidden') }}</x-badge>
        @endunless
        <x-badge tone="info">
            {{ $supplier->payment_terms_days > 0
                ? trans_choice('Pays in :count day|Pays in :count days', $supplier->payment_terms_days, ['count' => $supplier->payment_terms_days])
                : __('Cash on delivery') }}
        </x-badge>
        @if ($stats['drafts'] > 0)
            <a href="{{ route('purchases.index', ['supplier' => $supplier->id, 'status' => 'draft']) }}">
                <x-badge tone="warning">
                    {{ trans_choice(':count delivery not received yet|:count deliveries not received yet', $stats['drafts'], ['count' => $stats['drafts']]) }}
                </x-badge>
            </a>
        @endif
    </div>

    @if ($supplier->notes)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $supplier->notes }}</p>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="$balance >= 0 ? __('You owe them') : __('They hold for you')"
                     :value="Money::withSymbol(abs($balance))"
                     icon="wallet" :tone="$balance > 0 ? 'warning' : ($balance < 0 ? 'success' : 'neutral')" />

        <x-stat-card :label="__('Overdue')" :value="Money::rounded($stats['overdue_paisa'])"
                     icon="alert" :tone="$stats['overdue_paisa'] > 0 ? 'danger' : 'neutral'"
                     :hint="__('Bills past their due date')" />

        <x-stat-card :label="__('Bought this month')" :value="Money::rounded($stats['bought_this_month_paisa'])" icon="truck" />

        <x-stat-card :label="__('Last payment')"
                     :value="$stats['last_payment'] ? Money::rounded($stats['last_payment']->debit_paisa) : __('None yet')"
                     icon="check"
                     :hint="$stats['last_payment']?->entry_date->diffForHumans()" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-3" x-data="{ open: @js($openForm) }">
        <div class="space-y-3 lg:col-span-1">
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-1">
                <button type="button" x-on:click="open = open === 'pay' ? null : 'pay'"
                        class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="wallet" class="h-4.5 w-4.5" />
                    {{ __('Record a payment') }}
                </button>

                <a href="{{ route('purchases.returns.create', ['supplier' => $supplier->id]) }}"
                   class="tap-target inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('Send goods back') }}
                </a>
            </div>

            <div x-show="open === 'pay'" x-cloak x-transition>
                <x-card :title="__('Pay :name', ['name' => $supplier->name])"
                        :description="$balance > 0 ? __('You owe :amount.', ['amount' => Money::withSymbol($balance)]) : __('You do not owe anything right now. A payment will be held as an advance.')">
                    <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}" class="space-y-4">
                        @csrf

                        <x-field name="amount" :label="__('Amount')" required>
                            <x-text-input id="amount" name="amount" inputmode="decimal" class="block w-full" required
                                          autocomplete="off" placeholder="0.00"
                                          :value="old('method') ? old('amount') : ($balance > 0 ? number_format($balance / 100, 2, '.', '') : '')" />
                        </x-field>

                        <x-field name="method" :label="__('Paid by')" required>
                            <select id="method" name="method" class="{{ $selectClass }}">
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}" @selected(old('method', 'cash') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field name="paid_on" :label="__('Paid on')" required>
                            <x-text-input id="paid_on" name="paid_on" type="date" class="block w-full" required
                                          :max="today()->toDateString()"
                                          :value="old('paid_on', today()->toDateString())" />
                        </x-field>

                        <x-field name="note" :label="__('Note')" :hint="__('Cheque number, receipt number, who took it.')">
                            <x-text-input id="note" name="note" class="block w-full" autocomplete="off"
                                          :value="old('method') ? old('note') : ''" />
                        </x-field>

                        <button type="submit"
                                class="tap-target inline-flex w-full items-center justify-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                            {{ __('Save payment') }}
                        </button>
                    </form>
                </x-card>
            </div>

            <div x-show="open === 'adjust'" x-cloak x-transition>
                <x-card :title="__('Correct the balance')"
                        :description="__('For when their book and yours disagree and you have agreed the difference. Use it rarely — it is not a payment.')">
                    <form method="POST" action="{{ route('suppliers.adjustments.store', $supplier) }}" class="space-y-4">
                        @csrf

                        {{-- Plain labels: x-field would point at the payment form's inputs of the same name. --}}
                        <div>
                            <label for="adjust_amount" class="block text-sm font-medium">{{ __('Amount') }}</label>
                            <x-text-input id="adjust_amount" name="amount" inputmode="decimal" class="mt-1.5 block w-full" required
                                          autocomplete="off" placeholder="0.00"
                                          :value="old('direction') ? old('amount') : ''" />
                        </div>

                        <fieldset>
                            <legend class="block text-sm font-medium">{{ __('After this, you owe them') }}</legend>
                            <div class="mt-1.5 flex gap-4">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="radio" name="direction" value="more" @checked(old('direction', 'more') === 'more')
                                           class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                    {{ __('More') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="radio" name="direction" value="less" @checked(old('direction') === 'less')
                                           class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                    {{ __('Less') }}
                                </label>
                            </div>
                            <x-input-error :messages="$errors->get('direction')" class="mt-1.5" />
                        </fieldset>

                        <div>
                            <label for="adjust_note" class="block text-sm font-medium">{{ __('Why') }}</label>
                            <x-text-input id="adjust_note" name="note" class="mt-1.5 block w-full" required minlength="5" autocomplete="off"
                                          :value="old('direction') ? old('note') : ''" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required. Whoever reads this later needs to know.') }}</p>
                        </div>

                        <button type="submit"
                                class="tap-target inline-flex w-full items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            {{ __('Save correction') }}
                        </button>
                    </form>
                </x-card>
            </div>

            <x-card :title="__('Latest deliveries')" :padded="false">
                <x-slot:actions>
                    <a href="{{ route('purchases.index', ['supplier' => $supplier->id]) }}"
                       class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('All') }}</a>
                </x-slot:actions>

                @forelse ($purchases as $purchase)
                    <a href="{{ route('purchases.show', $purchase) }}"
                       class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm font-medium">{{ $purchase->reference }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $purchase->purchase_date->format('d M Y') }}
                                @if ($purchase->invoice_no) · {{ __('bill') }} {{ $purchase->invoice_no }} @endif
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <x-money :paisa="$purchase->total_paisa" rounded class="block text-sm font-medium" />
                            @unless ($purchase->status === \App\Enums\PurchaseStatus::Received)
                                <x-badge :tone="$purchase->status->tone()">{{ $purchase->status->label() }}</x-badge>
                            @endunless
                        </div>
                    </a>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing bought from them yet.') }}</p>
                @endforelse
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card :title="__('Statement')"
                    :description="__('Newest first. A bill makes what you owe go up; a payment or a return brings it down.')"
                    :padded="false">
                <x-slot:actions>
                    <button type="button" x-on:click="open = open === 'adjust' ? null : 'adjust'"
                            class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-400">
                        {{ __('Correct the balance') }}
                    </button>
                </x-slot:actions>

                @if ($entries->isEmpty())
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Nothing on the account yet. It starts with the first bill you receive from them.') }}
                    </p>
                @else
                    <ul>
                        @foreach ($entries as $entry)
                            @php
                                $link = match (true) {
                                    $entry->reference instanceof Purchase => route('purchases.show', $entry->reference),
                                    $entry->reference instanceof PurchaseReturn => route('purchases.returns.show', $entry->reference),
                                    default => null,
                                };
                            @endphp

                            <li class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-800">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-badge :tone="$entry->type->tone()">{{ $entry->type->label() }}</x-badge>
                                        @if ($link)
                                            <a href="{{ $link }}" class="font-mono text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                                                {{ $entry->reference->reference }}
                                            </a>
                                        @endif
                                        @if ($entry->method)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->method->label() }}</span>
                                        @endif
                                    </div>

                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $entry->entry_date->format('d M Y') }}
                                        @if ($entry->user) · {{ $entry->user->name }} @endif
                                    </p>

                                    @if ($entry->note)
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $entry->note }}</p>
                                    @endif
                                </div>

                                <div class="shrink-0 text-right">
                                    <p @class([
                                        'text-sm font-semibold tabular-nums',
                                        'text-money-out' => $entry->changePaisa() > 0,
                                        'text-money-in' => $entry->changePaisa() < 0,
                                    ])>
                                        {{ $entry->changePaisa() > 0 ? '+' : '−' }} {{ Money::format(abs($entry->changePaisa())) }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('owe') }} {{ Money::format($entry->balance_after_paisa) }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($entries->hasPages())
                        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                            {{ $entries->links() }}
                        </div>
                    @endif
                @endif
            </x-card>
        </div>
    </div>

    @if ($supplier->is_active)
        <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm font-semibold">{{ __('No longer buying from them?') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Hiding keeps their account and every bill, and takes them off the purchase screen. You can show them again from Edit.') }}
            </p>

            <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" class="mt-3"
                  x-data x-on:submit="if (! confirm('{{ __('Hide this supplier?') }}')) $event.preventDefault()">
                @csrf
                @method('DELETE')

                <button type="submit"
                        class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                    {{ __('Hide supplier') }}
                </button>
            </form>
        </div>
    @endif
</x-app-layout>
