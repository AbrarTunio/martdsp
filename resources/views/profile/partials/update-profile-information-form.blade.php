{{--
    Email is kept here because it is the login identifier. There is no
    verification prompt: this is a shop till, the owner creates the accounts,
    and an unverifiable address would lock a cashier out mid-shift.
--}}
<form method="post" action="{{ route('profile.update') }}" class="space-y-4">
    @csrf
    @method('patch')

    <x-field name="name" :label="__('Name')" required>
        <x-text-input id="name" name="name" type="text" class="block w-full"
                      :value="old('name', $user->name)" required autocomplete="name" />
    </x-field>

    <x-field name="email" :label="__('Email')" required :hint="__('You sign in with this.')">
        <x-text-input id="email" name="email" type="email" inputmode="email" class="block w-full"
                      :value="old('email', $user->email)" required autocomplete="username" />
    </x-field>

    <x-field name="phone" :label="__('Phone')">
        <x-text-input id="phone" name="phone" type="text" inputmode="tel" class="block w-full"
                      :value="old('phone', $user->phone)" autocomplete="tel" />
    </x-field>

    <div class="flex items-center gap-3">
        <x-primary-button>{{ __('Save') }}</x-primary-button>

    </div>
</form>
