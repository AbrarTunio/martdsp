<x-app-layout :title="__('My account')">
    <x-flash />

    <x-page-header :title="__('My account')"
                   :description="__('Your name, contact details and the password you sign in with.')" />

    <div class="mt-5 max-w-xl space-y-5">
        <x-card :title="__('Your details')">
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card :title="__('Password')"
                :description="__('Use something you can type quickly but nobody can guess.')">
            @include('profile.partials.update-password-form')
        </x-card>

        <x-card :title="__('Your role')">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ $user->role->label() }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Only the shop owner can change roles or close an account, so that every sale stays attributable.') }}
                    </p>
                </div>
                <x-badge :tone="$user->is_active ? 'success' : 'danger'">
                    {{ $user->is_active ? __('Active') : __('Disabled') }}
                </x-badge>
            </div>
        </x-card>
    </div>
</x-app-layout>
