<form method="post" action="{{ route('password.update') }}" class="space-y-4">
    @csrf
    @method('put')

    <div>
        <x-input-label for="update_password_current_password" :value="__('Current password')" />
        <x-text-input id="update_password_current_password" name="current_password" type="password"
                      class="mt-1 block w-full" autocomplete="current-password" />
        <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="update_password_password" :value="__('New password')" />
        <x-text-input id="update_password_password" name="password" type="password"
                      class="mt-1 block w-full" autocomplete="new-password" />
        <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('At least 8 characters.') }}</p>
    </div>

    <div>
        <x-input-label for="update_password_password_confirmation" :value="__('Confirm new password')" />
        <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password"
                      class="mt-1 block w-full" autocomplete="new-password" />
        <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
    </div>

    <div class="flex items-center gap-3">
        <x-primary-button>{{ __('Change password') }}</x-primary-button>

    </div>
</form>
