@php
    use App\Models\Sale;
    use App\Support\Money;

    $balance = (int) $customer->balance_paisa;
    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $openForm = old('direction') ? 'adjust' : (old('method') ? 'pay' : (old('note') ? 'write-off' : null));
    $headroom = $customer->hasCreditLimit() ? (int) $customer->credit_limit_paisa - $balance : null;
@endphp

<x-app-layout :title="$customer->name">
    <x-flash />

    <x-page-header :title="$customer->displayName()"
                   :description="collect([$customer->name_ur, $customer->address])->filter()->implode(' · ') ?: __('No Urdu name or address saved.')">
        <x-ai-insight-button />

        <a href="{{ route('customers.statement', $customer) }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Print statement') }}
        </a>

        @can('supervise')
            <a href="{{ route('customers.edit', $customer) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Edit') }}
            </a>
        @endcan

        @if ($balance > 0 && $customer->phone)
            <a href="{{ route('customers.reminder', $customer) }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="receipt" class="h-4.5 w-4.5" />
                {{ __('Send a reminder') }}
            </a>
        @endif
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        @unless ($customer->is_active)
            <x-badge>{{ __('Hidden') }}</x-badge>
        @endunless

        <x-badge tone="info">
            {{ $customer->hasCreditLimit()
                ? __('Limit :amount', ['amount' => Money::withSymbol($customer->credit_limit_paisa)])
                : __('No credit limit') }}
        </x-badge>

        <x-badge tone="neutral">
            {{ trans_choice(':count day to pay|:count days to pay', $creditDays, ['count' => $creditDays]) }}
        </x-badge>

        @if ($aging->isOverdue())
            <x-badge tone="danger">
                {{ trans_choice('Late by :count day|Late by :count days', $aging->daysLate(), ['count' => $aging->daysLate()]) }}
            </x-badge>
        @endif

        @if ($headroom !== null && $headroom <= 0)
            <x-badge tone="danger">{{ __('No room left on their limit') }}</x-badge>
        @endif
    </div>

    @if ($customer->notes)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $customer->notes }}</p>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="$balance >= 0 ? __('They owe you') : __('Advance you hold')"
                     :value="Money::withSymbol(abs($balance))"
                     icon="book" :tone="$balance > 0 ? 'warning' : ($balance < 0 ? 'success' : 'neutral')" />

        <x-stat-card :label="__('Overdue')" :value="Money::rounded($aging->overduePaisa())"
                     icon="alert" :tone="$aging->isOverdue() ? 'danger' : 'neutral'"
                     :hint="__('Past the day it was due')" />

        <x-stat-card :label="__('Bought this month')" :value="Money::rounded($stats['bought_this_month_paisa'])" icon="cart" />

        <x-stat-card :label="__('Last payment')"
                     :value="$stats['last_payment'] ? Money::rounded($stats['last_payment']->credit_paisa) : __('None yet')"
                     icon="check"
                     :hint="$stats['last_payment']?->entry_date->diffForHumans()" />
    </div>

    @if ($aging->owedPaisa > 0)
        <x-card class="mt-4" :title="__('How old the money is')"
                :description="__('Payments clear the oldest purchases first, the way a khata page is read.')">
            <ul class="grid gap-2 sm:grid-cols-5">
                @foreach ($aging->lines() as $line)
                    <li class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $line['label'] }}</p>
                        <p @class([
                            'mt-0.5 text-sm font-semibold tabular-nums',
                            'text-gray-400 dark:text-gray-600' => $line['paisa'] === 0,
                            'text-money-out' => $line['paisa'] > 0 && $line['tone'] !== 'neutral',
                        ])>
                            {{ Money::format($line['paisa']) }}
                        </p>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-3" x-data="{ open: @js($openForm), method: @js(old('method', 'cash')) }">
        <div class="space-y-3 lg:col-span-1">
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-1">
                <button type="button" x-on:click="open = open === 'pay' ? null : 'pay'"
                        class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="wallet" class="h-4.5 w-4.5" />
                    {{ __('Take a payment') }}
                </button>

                <a href="{{ route('pos.index') }}"
                   class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    <x-icon name="cart" class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
                    {{ __('Sell to them') }}
                </a>
            </div>

            <div x-show="open === 'pay'" x-cloak x-transition>
                <x-card :title="__('Payment from :name', ['name' => $customer->name])"
                        :description="$balance > 0
                            ? __('They owe :amount.', ['amount' => Money::withSymbol($balance)])
                            : __('Their khata is clear. Anything taken now is held as an advance.')">
                    <form method="POST" action="{{ route('customers.payments.store', $customer) }}" class="space-y-4">
                        @csrf

                        <x-field name="amount" :label="__('Amount')" required>
                            <x-text-input id="amount" name="amount" inputmode="decimal" class="block w-full" required
                                          autocomplete="off" placeholder="0.00"
                                          :value="old('method') ? old('amount') : ($balance > 0 ? number_format($balance / 100, 2, '.', '') : '')" />
                        </x-field>

                        <x-field name="method" :label="__('Paid by')" required>
                            <select id="method" name="method" class="{{ $selectClass }}" x-model="method">
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}" @selected(old('method', 'cash') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <div x-show="method === 'cash'">
                            <x-field name="register_id" :label="__('Cash goes into')"
                                     :hint="__('Cash taken over the counter must go into an open drawer, so the count at the end of the shift answers for it.')">
                                <select id="register_id" name="register_id" class="{{ $selectClass }}">
                                    @foreach ($registers as $register)
                                        <option value="{{ $register->id }}"
                                                @selected((int) old('register_id', $defaultRegister?->id) === $register->id)>
                                            {{ $register->name }}
                                            {{ $register->openDrawer ? '' : __('(drawer closed)') }}
                                        </option>
                                    @endforeach
                                </select>
                            </x-field>
                        </div>

                        <x-field name="paid_on" :label="__('Paid on')" required>
                            <x-text-input id="paid_on" name="paid_on" type="date" class="block w-full" required
                                          :max="today()->toDateString()"
                                          :value="old('paid_on', today()->toDateString())" />
                        </x-field>

                        <x-field name="note" :label="__('Note')" :hint="__('Who handed it over, a receipt number, anything you want on the statement.')">
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

            @can('supervise')
                <div x-show="open === 'adjust'" x-cloak x-transition>
                    <x-card :title="__('Correct the khata')"
                            :description="__('For when their page and yours disagree and you have settled the difference between you. It is not a payment.')">
                        <form method="POST" action="{{ route('customers.adjustments.store', $customer) }}" class="space-y-4">
                            @csrf

                            {{-- Plain labels: x-field would point at the payment form's inputs of the same name. --}}
                            <div>
                                <label for="adjust_amount" class="block text-sm font-medium">{{ __('Amount') }}</label>
                                <x-text-input id="adjust_amount" name="amount" inputmode="decimal" class="mt-1.5 block w-full" required
                                              autocomplete="off" placeholder="0.00"
                                              :value="old('direction') ? old('amount') : ''" />
                            </div>

                            <fieldset>
                                <legend class="block text-sm font-medium">{{ __('After this, they owe') }}</legend>
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

                @if ($balance > 0)
                    <div x-show="open === 'write-off'" x-cloak x-transition>
                        <x-card :title="__('Write it off')"
                                :description="__('Money you have decided will never come back. The khata keeps every line — this adds one more that clears the debt.')">
                            <form method="POST" action="{{ route('customers.write-off', $customer) }}" class="space-y-4">
                                @csrf

                                <div>
                                    <label for="writeoff_amount" class="block text-sm font-medium">{{ __('Amount') }}</label>
                                    <x-text-input id="writeoff_amount" name="amount" inputmode="decimal" class="mt-1.5 block w-full" required
                                                  autocomplete="off" :value="number_format($balance / 100, 2, '.', '')" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('At most :amount, which is what they owe.', ['amount' => Money::withSymbol($balance)]) }}
                                    </p>
                                </div>

                                <div>
                                    <label for="writeoff_note" class="block text-sm font-medium">{{ __('Why') }}</label>
                                    <x-text-input id="writeoff_note" name="note" class="mt-1.5 block w-full" required minlength="5" autocomplete="off" />
                                </div>

                                <button type="submit"
                                        class="tap-target inline-flex w-full items-center justify-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10"
                                        x-on:click="if (! confirm('{{ __('Write this off? It cannot be collected afterwards without a correction.') }}')) $event.preventDefault()">
                                    {{ __('Write off') }}
                                </button>
                            </form>
                        </x-card>
                    </div>
                @endif
            @endcan

            <x-card :title="__('Latest bills')" :padded="false">
                @forelse ($sales as $sale)
                    <a href="{{ route('sales.show', $sale) }}"
                       class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm font-medium">{{ $sale->reference }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $sale->sold_at?->format('d M Y, g:i A') }}</p>
                        </div>
                        <x-money :paisa="$sale->total_paisa" rounded class="shrink-0 text-sm font-medium" />
                    </a>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing bought yet.') }}</p>
                @endforelse
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card :title="__('Khata')"
                    :description="__('Newest first. A sale on khata makes the balance go up; a payment or a return brings it down.')"
                    :padded="false">
                <x-slot:actions>
                    @can('supervise')
                        <button type="button" x-on:click="open = open === 'adjust' ? null : 'adjust'"
                                class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-400">
                            {{ __('Correct') }}
                        </button>

                        @if ($balance > 0)
                            <button type="button" x-on:click="open = open === 'write-off' ? null : 'write-off'"
                                    class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-400">
                                {{ __('Write off') }}
                            </button>
                        @endif
                    @endcan
                </x-slot:actions>

                @if ($entries->isEmpty())
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Nothing on this khata yet. It starts with their first sale on credit.') }}
                    </p>
                @else
                    <ul>
                        @foreach ($entries as $entry)
                            <li class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-800">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-badge :tone="$entry->type->tone()">{{ $entry->type->label() }}</x-badge>

                                        @if ($entry->reference instanceof Sale)
                                            <a href="{{ route('sales.show', $entry->reference) }}"
                                               class="font-mono text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                                                {{ $entry->reference->reference }}
                                            </a>
                                        @endif

                                        @if ($entry->method)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->method->label() }}</span>
                                        @endif

                                        @if ($entry->due_date && $entry->debit_paisa > 0 && $entry->due_date->lt(today()))
                                            <x-badge tone="danger">{{ __('Due :date', ['date' => $entry->due_date->format('d M')]) }}</x-badge>
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
                                        {{ __('balance') }} {{ Money::format($entry->balance_after_paisa) }}
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

    @can('supervise')
        @if ($customer->is_active)
            <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm font-semibold">{{ __('Not giving them credit any more?') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Hiding keeps the khata and every line on it, and takes them off the till. You can show them again from Edit.') }}
                </p>

                <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="mt-3"
                      x-data x-on:submit="if (! confirm('{{ __('Hide this customer?') }}')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')

                    <button type="submit"
                            class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                        {{ __('Hide customer') }}
                    </button>
                </form>
            </div>
        @endif
    @endcan
</x-app-layout>
