@php
    use App\Support\Money;

    $rupees = fn (int $paisa): string => $paisa > 0 ? rtrim(rtrim(number_format($paisa / 100, 2, '.', ''), '0'), '.') : '0';
    $input = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<x-app-layout :title="__('Cash drawer')">
    <x-flash />

    <x-page-header :title="__('Cash drawer')"
                   :description="__('Open each counter\'s drawer with its starting cash, note every rupee added or taken out, and count it at the end of the shift.')">
        <x-ai-insight-button />
    </x-page-header>

    <ul class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($registers as $register)
            @php($session = $register->openDrawer)

            <li class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <span @class([
                        'grid h-11 w-11 shrink-0 place-items-center rounded-lg',
                        'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' => $session,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $session,
                    ])>
                        <x-icon :name="$session ? 'unlock' : 'lock'" class="h-5.5 w-5.5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <p class="text-base font-semibold">{{ $register->name }}</p>
                            <x-badge :tone="$session ? 'success' : 'neutral'">{{ $session ? __('Open') : __('Closed') }}</x-badge>
                        </div>

                        @if ($session)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ __('Opened :time by :name with :amount', [
                                    'time' => $session->opened_at->isToday() ? $session->opened_at->format('g:i A') : $session->opened_at->format('d M, g:i A'),
                                    'name' => $session->opener?->name ?? __('someone'),
                                    'amount' => Money::withSymbol($session->opening_float_paisa),
                                ]) }}
                            </p>
                        @elseif ($register->location)
                            <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $register->location }}</p>
                        @endif
                    </div>
                </div>

                @if ($session)
                    @if ($expected->has($register->id))
                        <div class="mt-3 flex items-baseline justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Should be in the drawer') }}</span>
                            <x-money :paisa="$expected[$register->id]" class="text-base font-semibold" />
                        </div>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('drawer.show', $session) }}"
                           class="tap-target inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            {{ __('Open the drawer page') }}
                        </a>
                        <a href="{{ route('drawer.close.create', $session) }}"
                           class="tap-target inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon name="lock" class="h-4 w-4" />
                            {{ __('Count & close') }}
                        </a>
                    </div>
                @else
                    @php($mine = (string) old('register_id') === (string) $register->id)

                    <form method="POST" action="{{ route('drawer.open') }}" class="mt-3 space-y-2">
                        @csrf
                        <input type="hidden" name="register_id" value="{{ $register->id }}">
                        <input type="hidden" name="back_to" value="drawer">

                        <label for="float-{{ $register->id }}" class="block text-sm font-medium">{{ __('Cash in the drawer to start') }}</label>
                        <div class="flex gap-2">
                            <input id="float-{{ $register->id }}" name="float" type="text" inputmode="decimal" required autocomplete="off"
                                   value="{{ $mine ? old('float') : $rupees($suggestedFloats[$register->id] ?? 0) }}"
                                   class="{{ $input }} tabular-nums">
                            <button type="submit"
                                    class="tap-target inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="unlock" class="h-4 w-4" />
                                {{ __('Open') }}
                            </button>
                        </div>
                        @if (($suggestedFloats[$register->id] ?? 0) > 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('The last shift left this much behind. Count it before you start.') }}</p>
                        @endif
                        @if ($mine)
                            <x-input-error :messages="$errors->get('float')" />
                        @endif
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($waiting->isNotEmpty())
        <x-card :title="__('Counts waiting for you')" :description="__('These drawers were out by more than the allowed amount when they were closed. Look at the reason and sign them off.')"
                :padded="false" class="mt-5 border-red-200 dark:border-red-500/30">
            <ul>
                @foreach ($waiting as $session)
                    <li class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                        <a href="{{ route('drawer.show', $session) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ $session->register?->name }} · {{ $session->closed_at?->format('d M, g:i A') }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Closed by :name', ['name' => $session->closer?->name ?? __('someone')]) }}
                                    @if ($session->variance_reason) · “{{ $session->variance_reason }}” @endif
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <x-badge :tone="$session->varianceTone()">{{ $session->varianceLabel() }}</x-badge>
                                <x-money :paisa="$session->variance_paisa" signed class="mt-1 block text-sm font-semibold" />
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <h2 class="mt-6 text-base font-semibold">{{ __('Every shift') }}</h2>

    <form method="GET" action="{{ route('drawer.index') }}" class="mt-2"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:max-w-2xl">
            <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" max="{{ today()->toDateString() }}"
                   aria-label="{{ __('Date') }}"
                   class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <select name="status" aria-label="{{ __('Status') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every shift') }}</option>
                <option value="open" @selected(($filters['status'] ?? '') === 'open')>{{ __('Still open') }}</option>
                <option value="closed" @selected(($filters['status'] ?? '') === 'closed')>{{ __('Closed') }}</option>
                @if ($seesExpected)
                    <option value="waiting" @selected(($filters['status'] ?? '') === 'waiting')>{{ __('Waiting for sign-off') }}</option>
                @endif
            </select>

            @if ($allRegisters->count() > 1)
                <select name="register" aria-label="{{ __('Counter') }}"
                        class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">{{ __('Every counter') }}</option>
                    @foreach ($allRegisters as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['register'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($history->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="safe"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No shifts yet')"
                           :description="__('Each time a drawer is opened a shift starts here, and it stays on record once it is counted and closed.')" />
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($history as $session)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('drawer.show', $session) }}" class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="text-sm font-semibold">{{ $session->register?->name }}</p>
                                <x-badge :tone="$session->status->tone()">{{ $session->status->label() }}</x-badge>
                                @if ($seesExpected && $session->isAwaitingApproval())
                                    <x-badge tone="danger">{{ __('Needs sign-off') }}</x-badge>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Opened :date by :name', [
                                    'date' => $session->opened_at->format('d M, g:i A'),
                                    'name' => $session->opener?->name ?? __('someone'),
                                ]) }}
                                @if ($session->closed_at)
                                    <br>{{ __('Closed :date by :name', [
                                        'date' => $session->closed_at->format('d M, g:i A'),
                                        'name' => $session->closer?->name ?? __('someone'),
                                    ]) }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right text-xs text-gray-500 dark:text-gray-400">
                            <p>{{ __('Started') }} <span class="font-medium text-gray-800 tabular-nums dark:text-gray-200">{{ Money::rounded($session->opening_float_paisa) }}</span></p>
                            @if ($session->counted_cash_paisa !== null)
                                <p class="mt-0.5">{{ __('Counted') }} <span class="font-medium text-gray-800 tabular-nums dark:text-gray-200">{{ Money::rounded($session->counted_cash_paisa) }}</span></p>
                            @endif
                            @if ($seesExpected && $session->variance_paisa !== null)
                                <p class="mt-0.5">
                                    @if ($session->variance_paisa === 0)
                                        <span class="font-medium text-money-in">{{ __('Exact') }}</span>
                                    @else
                                        {{ $session->varianceLabel() }} <x-money :paisa="$session->variance_paisa" signed class="font-medium" />
                                    @endif
                                </p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $history->links() }}
        </div>
    @endif
</x-app-layout>
