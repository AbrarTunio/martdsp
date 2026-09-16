@php
    use App\Enums\DrawerEntryType;
    use App\Support\Money;

    $session = $summary->session;
    $open = $session->isOpen();
    $gap = $summary->gapFromPreviousPaisa();
    $safeDrops = $summary->handled->where('type', DrawerEntryType::SafeDrop);
    $timesEmptied = $safeDrops->count() + ($session->takenAtClosePaisa() > 0 ? 1 : 0);
    $when = fn ($moment): string => $moment?->isToday() ? $moment->format('g:i A') : ($moment?->format('d M Y, g:i A') ?? '');
    $input = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $reportName = $open ? __('X-report') : __('Z-report');
@endphp

<x-app-layout :title="__(':register drawer', ['register' => $session->register?->name])">
    <x-flash />

    <x-page-header :title="__(':register drawer', ['register' => $session->register?->name])"
                   :description="$open
                       ? __('Running now. Every rupee that goes in or out of this drawer is listed below.')
                       : __('Counted and closed :when.', ['when' => $when($session->closed_at)])">
        <x-ai-insight-button />

        <a href="{{ route('drawer.report', ['drawer' => $session, 'print' => 1]) }}" target="_blank" rel="noopener"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Print :report', ['report' => $reportName]) }}
        </a>

        @if ($open)
            <a href="{{ route('drawer.close.create', $session) }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="lock" class="h-4.5 w-4.5" />
                {{ __('Count & close') }}
            </a>
        @endif
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$session->status->tone()">{{ $session->status->label() }}</x-badge>
        @if ($seesExpected && $session->variance_paisa !== null)
            <x-badge :tone="$session->varianceTone()">{{ $session->varianceLabel() }}</x-badge>
        @endif
        @if ($session->isAwaitingApproval())
            <x-badge tone="danger">{{ __('Waiting for a manager to sign off') }}</x-badge>
        @endif
        <a href="{{ route('drawer.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('All drawers') }}</a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Started with')" :value="Money::withSymbol($session->opening_float_paisa)" icon="wallet" />

        <x-stat-card :label="__('Bills')" :value="number_format($summary->billCount)" icon="receipt"
                     :hint="$seesExpected
                         ? __(':amount sold', ['amount' => Money::rounded($summary->billTotalPaisa)]).($summary->voidedCount > 0 ? ' · '.trans_choice(':count cancelled|:count cancelled', $summary->voidedCount, ['count' => $summary->voidedCount]) : '')
                         : null" />

        @if ($seesExpected)
            <x-stat-card :label="$open ? __('Should be in the drawer') : __('Should have been there')"
                         :value="Money::withSymbol($summary->expectedPaisa)" icon="safe" tone="success" />
        @elseif (! $open)
            <x-stat-card :label="__('You counted')" :value="Money::withSymbol((int) $session->counted_cash_paisa)" icon="safe" />
        @endif

        <x-stat-card :label="__('Taken to the safe')" :value="Money::withSymbol($summary->takenAwayPaisa())" icon="lock"
                     :hint="$timesEmptied > 0 ? trans_choice('emptied :count time|emptied :count times', $timesEmptied, ['count' => $timesEmptied]) : __('not emptied yet')" />
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-5">
        <div class="space-y-4 lg:col-span-3">
            {{-- The story: who opened it, who emptied it, what the count said, what the next shift started with. --}}
            <x-card :title="__('What happened to this drawer')" :padded="false">
                <ol class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li class="flex gap-3 px-4 py-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-icon name="unlock" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1 text-sm">
                            <p>
                                <span class="font-semibold">{{ $session->opener?->name ?? __('Someone') }}</span>
                                {{ __('opened it at :when with', ['when' => $when($session->opened_at)]) }}
                                <span class="font-semibold tabular-nums">{{ Money::withSymbol($session->opening_float_paisa) }}</span>.
                            </p>
                            @if ($session->opening_note)
                                <p class="mt-0.5 text-gray-600 dark:text-gray-400">“{{ $session->opening_note }}”</p>
                            @endif
                            @if ($summary->previous)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    <a href="{{ route('drawer.show', $summary->previous) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('The shift before') }}</a>
                                    @if ($summary->previous->left_in_drawer_paisa !== null)
                                        {{ __('left :amount behind.', ['amount' => Money::withSymbol($summary->previous->left_in_drawer_paisa)]) }}
                                    @else
                                        {{ __('was never counted.') }}
                                    @endif
                                </p>
                                @if ($gap !== null && $gap !== 0)
                                    <p class="mt-1 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                                        {{ $gap < 0
                                            ? __(':amount less than the last shift left. Find out where it went between the two shifts.', ['amount' => Money::withSymbol(abs($gap))])
                                            : __(':amount more than the last shift left.', ['amount' => Money::withSymbol($gap)]) }}
                                    </p>
                                @endif
                            @endif
                        </div>
                    </li>

                    @foreach ($safeDrops as $drop)
                        <li class="flex gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                                <x-icon name="safe" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1 text-sm">
                                <p>
                                    <span class="font-semibold">{{ $drop->user?->name ?? __('Someone') }}</span>
                                    {{ __('emptied :amount to the safe at :when.', ['amount' => Money::withSymbol(abs($drop->amount_paisa)), 'when' => $when($drop->created_at)]) }}
                                </p>
                                @if ($drop->note)
                                    <p class="mt-0.5 text-gray-600 dark:text-gray-400">“{{ $drop->note }}”</p>
                                @endif
                            </div>
                        </li>
                    @endforeach

                    @if ($open)
                        <li class="flex gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                <x-icon name="clock" class="h-4 w-4" />
                            </span>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Still open. Count it and close it at the end of the shift.') }}</p>
                        </li>
                    @else
                        <li class="flex gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <x-icon name="lock" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1 text-sm">
                                <p>
                                    <span class="font-semibold">{{ $session->closer?->name ?? __('Someone') }}</span>
                                    {{ __('counted :amount and closed it at :when.', ['amount' => Money::withSymbol((int) $session->counted_cash_paisa), 'when' => $when($session->closed_at)]) }}
                                </p>
                                @if ($seesExpected)
                                    <p class="mt-0.5 text-gray-600 dark:text-gray-400">
                                        @if ($session->variance_paisa === 0)
                                            {{ __('Exactly what the drawer should have held.') }}
                                        @else
                                            {{ __('It should have held :expected, so it was', ['expected' => Money::withSymbol((int) $session->expected_cash_paisa)]) }}
                                            <x-money :paisa="(int) $session->variance_paisa" signed class="font-semibold" />
                                            {{ $session->variance_paisa < 0 ? __('short.') : __('over.') }}
                                        @endif
                                    </p>
                                @endif
                                @if ($session->variance_reason)
                                    <p class="mt-0.5 text-gray-600 dark:text-gray-400">{{ __('Reason given:') }} “{{ $session->variance_reason }}”</p>
                                @endif
                                @if ($session->approved_at && $session->needs_approval)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Signed off by :name at :when.', ['name' => $session->approver?->name ?? __('someone'), 'when' => $when($session->approved_at)]) }}
                                        @if ($session->approval_note) “{{ $session->approval_note }}” @endif
                                    </p>
                                @endif
                            </div>
                        </li>

                        <li class="flex gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                                <x-icon name="safe" class="h-4 w-4" />
                            </span>
                            <p class="text-sm">
                                {{ __(':taken went to the safe and :left was left in the drawer for the next shift.', [
                                    'taken' => Money::withSymbol($session->takenAtClosePaisa()),
                                    'left' => Money::withSymbol((int) $session->left_in_drawer_paisa),
                                ]) }}
                            </p>
                        </li>

                        <li class="flex gap-3 px-4 py-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-icon name="chevron-right" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1 text-sm">
                                @if ($summary->next)
                                    <p>
                                        <a href="{{ route('drawer.show', $summary->next) }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-400">{{ __('The next shift') }}</a>
                                        {{ __('was opened by :name at :when with :amount.', [
                                            'name' => $summary->next->opener?->name ?? __('someone'),
                                            'when' => $when($summary->next->opened_at),
                                            'amount' => Money::withSymbol($summary->next->opening_float_paisa),
                                        ]) }}
                                    </p>
                                    @if ($summary->next->opening_float_paisa !== (int) $session->left_in_drawer_paisa)
                                        <p class="mt-1 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                                            {{ __('That is :amount different from what this shift left behind.', [
                                                'amount' => Money::withSymbol(abs($summary->next->opening_float_paisa - (int) $session->left_in_drawer_paisa)),
                                            ]) }}
                                        </p>
                                    @endif
                                @else
                                    <p class="text-gray-600 dark:text-gray-400">{{ __('Nobody has opened this drawer since.') }}</p>
                                @endif
                            </div>
                        </li>
                    @endif
                </ol>
            </x-card>

            @if ($session->isAwaitingApproval())
                @can('supervise')
                    <x-card :title="__('Sign off this count')" class="border-red-200 dark:border-red-500/30"
                            :description="__('The count was out by more than the allowed amount. Check the reason with :name, then sign it off so it stops showing as waiting.', ['name' => $session->closer?->name ?? __('the cashier')])">
                        <form method="POST" action="{{ route('drawer.approve', $session) }}" class="space-y-2">
                            @csrf
                            <input name="note" maxlength="255" autocomplete="off" value="{{ old('note') }}"
                                   placeholder="{{ __('Optional — e.g. change was given twice, cashier will make it up') }}"
                                   aria-label="{{ __('Note') }}" class="{{ $input }}">
                            <x-input-error :messages="$errors->get('note')" />
                            <button type="submit"
                                    class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="check" class="h-4 w-4" />
                                {{ __('Sign it off') }}
                            </button>
                        </form>
                    </x-card>
                @endcan
            @endif

            @if ($open)
                @php($oldType = old('type', DrawerEntryType::PayIn->value))

                <x-card :title="__('Cash in or out')" :description="__('Anything that goes in or out of the drawer other than a sale. Write it down the moment it happens.')">
                    <form method="POST" action="{{ route('drawer.movements.store', $session) }}" class="space-y-3"
                          x-data="{ type: @js($oldType) }">
                        @csrf
                        <input type="hidden" name="type" x-bind:value="type" value="{{ $oldType }}">

                        <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800" role="radiogroup" aria-label="{{ __('What kind') }}">
                            @foreach ($manualTypes as $type)
                                <button type="button" role="radio" x-on:click="type = @js($type->value)"
                                        x-bind:aria-checked="type === @js($type->value)"
                                        x-bind:class="type === @js($type->value) ? 'bg-white shadow-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400'"
                                        class="tap-target inline-flex items-center justify-center gap-1 rounded-md px-2 text-xs font-semibold sm:text-sm">
                                    <x-icon :name="$type->icon()" class="h-4 w-4 shrink-0" />
                                    {{ __($type->label()) }}
                                </button>
                            @endforeach
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <span x-show="type === 'pay_in'">{{ __('More change put in, or money brought from the safe.') }}</span>
                            <span x-show="type === 'pay_out'" x-cloak>{{ __('Money paid from the drawer — a delivery, tea, a small bill. Say what it was for.') }}</span>
                            <span x-show="type === 'safe_drop'" x-cloak>{{ __('Taking the big notes out to the safe or the owner during the shift.') }}</span>
                        </p>
                        <x-input-error :messages="$errors->get('type')" />

                        <div>
                            <label for="amount" class="block text-sm font-medium">{{ __('Amount (Rs.)') }}</label>
                            <input id="amount" name="amount" type="text" inputmode="decimal" required autocomplete="off"
                                   value="{{ old('amount') }}" class="{{ $input }} mt-1.5 tabular-nums">
                            <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                        </div>

                        <div>
                            <label for="note" class="block text-sm font-medium">
                                {{ __('What for') }}
                                <span x-show="type === 'pay_out'" class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                            </label>
                            <input id="note" name="note" maxlength="255" autocomplete="off" value="{{ old('note') }}"
                                   x-bind:required="type === 'pay_out'" class="{{ $input }} mt-1.5">
                            <x-input-error :messages="$errors->get('note')" class="mt-1.5" />
                        </div>

                        <button type="submit"
                                class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                            {{ __('Record it') }}
                        </button>
                    </form>
                </x-card>
            @endif

            <x-card :title="__('Every movement')" :padded="false"
                    :description="$seesExpected ? __('Newest first.') : __('Newest first. Sales are left out so your count stays honest.')">
                @forelse ($timeline as $entry)
                    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 dark:border-gray-800">
                        <x-icon :name="$entry->type->icon()" class="h-4.5 w-4.5 shrink-0 text-gray-400" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ __($entry->type->label()) }}
                                @if ($entry->reference_id && in_array($entry->type, [DrawerEntryType::CashSale, DrawerEntryType::SaleVoid], true))
                                    · <a href="{{ route('sales.show', $entry->reference_id) }}" class="font-mono text-xs text-brand-700 hover:underline dark:text-brand-400">{{ $entry->note }}</a>
                                @endif
                            </p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $entry->created_at?->format('g:i A') }}
                                @if ($entry->user) · {{ $entry->user->name }} @endif
                                @if ($entry->note && ! in_array($entry->type, [DrawerEntryType::CashSale, DrawerEntryType::SaleVoid], true)) · {{ $entry->note }} @endif
                            </p>
                        </div>
                        <x-money :paisa="$entry->amount_paisa" signed class="shrink-0 text-sm font-medium" />
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing has gone in or out yet.') }}</p>
                @endforelse
            </x-card>
        </div>

        <div class="space-y-4 lg:col-span-2">
            <x-card :title="__('The drawer')" :padded="false">
                <dl class="text-sm">
                    @foreach ($summary->lines() as $line)
                        @continue(! $seesExpected && ! ($line['type']->isManual() || $line['type'] === DrawerEntryType::OpeningFloat))
                        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 dark:border-gray-800">
                            <dt class="text-gray-600 dark:text-gray-400">
                                {{ __($line['type']->label()) }}
                                @if ($line['count'] > 1) <span class="text-xs">× {{ $line['count'] }}</span> @endif
                            </dt>
                            <dd><x-money :paisa="$line['paisa']" :signed="$line['type'] !== DrawerEntryType::OpeningFloat" /></dd>
                        </div>
                    @endforeach
                    @if ($seesExpected)
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5 font-semibold">
                            <dt>{{ $open ? __('Should be in the drawer') : __('Should have been there') }}</dt>
                            <dd><x-money :paisa="$summary->expectedPaisa" /></dd>
                        </div>
                    @endif
                </dl>
            </x-card>

            @if ($seesExpected && $summary->tenderLines())
                <x-card :title="__('How bills were paid')" :padded="false">
                    <dl class="text-sm">
                        @foreach ($summary->tenderLines() as $line)
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 last:border-b-0 dark:border-gray-800">
                                <dt class="text-gray-600 dark:text-gray-400">{{ __($line['method']->label()) }} <span class="text-xs">× {{ $line['count'] }}</span></dt>
                                <dd><x-money :paisa="$line['paisa']" /></dd>
                            </div>
                        @endforeach
                        @if ($summary->discountPaisa > 0)
                            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-2 dark:border-gray-800">
                                <dt class="text-gray-600 dark:text-gray-400">{{ __('Discount given') }}</dt>
                                <dd><x-money :paisa="$summary->discountPaisa" /></dd>
                            </div>
                        @endif
                        @if ($summary->taxPaisa > 0)
                            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-2 dark:border-gray-800">
                                <dt class="text-gray-600 dark:text-gray-400">{{ __('GST collected') }}</dt>
                                <dd><x-money :paisa="$summary->taxPaisa" /></dd>
                            </div>
                        @endif
                    </dl>
                </x-card>
            @endif

            @if (! $open && $session->countLines->isNotEmpty())
                <x-card :title="__('The count')" :padded="false">
                    <dl class="text-sm">
                        @foreach ($session->countLines as $line)
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 dark:border-gray-800">
                                <dt class="tabular-nums text-gray-600 dark:text-gray-400">Rs. {{ number_format($line->denomination) }} × {{ $line->count }}</dt>
                                <dd><x-money :paisa="$line->subtotal_paisa" /></dd>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5 font-semibold">
                            <dt>{{ __('Counted') }}</dt>
                            <dd><x-money :paisa="(int) $session->counted_cash_paisa" /></dd>
                        </div>
                        @if ($seesExpected && $session->variance_paisa !== null)
                            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-2 dark:border-gray-800">
                                <dt class="text-gray-600 dark:text-gray-400">{{ $session->varianceLabel() }}</dt>
                                <dd><x-money :paisa="$session->variance_paisa" signed class="font-semibold" /></dd>
                            </div>
                        @endif
                    </dl>
                </x-card>
            @endif

            <x-card :title="__('Print the :report', ['report' => $reportName])"
                    :description="$open
                        ? __('A peek at the shift so far. It does not close anything.')
                        : __('The end-of-sale report for this shift.')">
                <div class="flex flex-wrap gap-2">
                    @foreach (['80' => __('80 mm roll'), '58' => __('58 mm roll'), 'a4' => __('A4 page')] as $paper => $label)
                        <a href="{{ route('drawer.report', ['drawer' => $session, 'paper' => $paper]) }}" target="_blank" rel="noopener"
                           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            <x-icon name="printer" class="h-4 w-4" />
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                @if ($counterPrinter)
                    <form method="POST" action="{{ route('drawer.print', $session) }}" class="mt-3">
                        @csrf
                        <button type="submit"
                                class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                            <x-icon name="printer" class="h-4 w-4" />
                            {{ __('Send straight to :printer', ['printer' => $counterPrinter->name]) }}
                        </button>
                    </form>

                    <x-input-error :messages="$errors->get('printer')" class="mt-2" />
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
