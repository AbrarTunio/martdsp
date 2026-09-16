<x-app-layout :title="__('Staff')">
    <x-flash />

    <x-page-header :title="__('Staff')"
                   :description="__('Everyone who can sign in to the till.')">
        <a href="{{ route('settings.staff.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('Add staff') }}
        </a>
    </x-page-header>

    <div class="mt-5 space-y-3">
        @foreach ($staff as $member)
            <div @class([
                'flex items-center gap-3 rounded-xl border bg-white p-3 dark:bg-gray-900',
                'border-gray-200 dark:border-gray-800' => $member->is_active,
                'border-dashed border-gray-300 opacity-60 dark:border-gray-700' => ! $member->is_active,
            ])>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">
                    {{ Str::upper(Str::substr($member->name, 0, 2)) }}
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="truncate text-sm font-medium">{{ $member->name }}</span>
                        <x-badge :tone="$member->isOwner() ? 'info' : 'neutral'">{{ $member->role->label() }}</x-badge>
                        @unless ($member->is_active)
                            <x-badge tone="danger">{{ __('Disabled') }}</x-badge>
                        @endunless
                        @if ($member->is(auth()->user()))
                            <x-badge tone="success">{{ __('You') }}</x-badge>
                        @endif
                    </div>
                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ $member->email }}{{ $member->phone ? ' · '.$member->phone : '' }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <a href="{{ route('settings.staff.edit', $member) }}"
                       class="tap-target inline-flex items-center rounded-lg px-3 text-xs font-semibold text-brand-700 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
                        {{ __('Edit') }}
                    </a>

                    @if ($member->is_active && ! $member->is(auth()->user()))
                        <form method="POST" action="{{ route('settings.staff.destroy', $member) }}"
                              x-data
                              @submit="if (! confirm(@js(__('Stop :name from signing in? Their past sales stay on record.', ['name' => $member->name])))) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="tap-target inline-flex items-center rounded-lg px-3 text-xs font-semibold text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                                {{ __('Disable') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        {{ __('Accounts are disabled, never deleted, so that every sale and drawer count stays attributable to the person who made it. Re-enable an account by editing it.') }}
    </p>
</x-app-layout>
