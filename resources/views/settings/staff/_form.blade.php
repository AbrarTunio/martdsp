{{--
    Shared by create and edit. On edit, a blank password or PIN means
    "leave it alone" — the controller unsets blank values.
--}}
@php($isEdit = $staff !== null)

<div class="space-y-4">
    <x-field name="name" :label="__('Name')" required>
        <x-text-input id="name" name="name" class="block w-full"
                      :value="old('name', $staff?->name)" required autocomplete="off" />
    </x-field>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="email" :label="__('Email')" required :hint="__('They sign in with this.')">
            <x-text-input id="email" name="email" type="email" inputmode="email" class="block w-full"
                          :value="old('email', $staff?->email)" required autocomplete="off" />
        </x-field>

        <x-field name="phone" :label="__('Phone')">
            <x-text-input id="phone" name="phone" inputmode="tel" class="block w-full"
                          :value="old('phone', $staff?->phone)" autocomplete="off" />
        </x-field>
    </div>

    <x-field name="role" :label="__('Role')" required>
        <select id="role" name="role" required
                class="block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            @foreach ($roles as $value => $label)
                <option value="{{ $value }}" @selected(old('role', $staff?->role?->value) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <ul class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <li>{{ __('Cashier — sells, takes khata payments, counts their own drawer. Cannot see cost or profit.') }}</li>
            <li>{{ __('Manager — everything a cashier can do, plus stock, purchases, reports and approving drawer differences.') }}</li>
            <li>{{ __('Owner — everything, including settings and staff.') }}</li>
        </ul>
    </x-field>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="password"
                 :label="$isEdit ? __('New password') : __('Password')"
                 :required="! $isEdit"
                 :hint="$isEdit ? __('Leave blank to keep the current one.') : __('At least 8 characters.')">
            <x-text-input id="password" name="password" type="password" class="block w-full"
                          autocomplete="new-password" :required="! $isEdit" />
        </x-field>

        <x-field name="password_confirmation" :label="__('Confirm password')" :required="! $isEdit">
            <x-text-input id="password_confirmation" name="password_confirmation" type="password"
                          class="block w-full" autocomplete="new-password" :required="! $isEdit" />
        </x-field>
    </div>

    <x-field name="pin_code"
             :label="__('Till PIN')"
             :hint="$isEdit
                ? __('4 to 6 digits, for switching users at the counter. Leave blank to keep the current one.')
                : __('Optional. 4 to 6 digits, for switching users at the counter without typing a password.')">
        <x-text-input id="pin_code" name="pin_code" type="text" inputmode="numeric"
                      pattern="[0-9]*" maxlength="6" class="block w-full sm:max-w-[10rem]"
                      autocomplete="off" />
    </x-field>

    <x-settings.toggle
        name="is_active"
        :label="__('Allowed to sign in')"
        :hint="__('Turn this off when someone leaves. Their sales history stays intact.')"
        :checked="(bool) old('is_active', $staff?->is_active ?? true)" />
</div>
