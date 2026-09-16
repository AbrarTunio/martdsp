<x-app-layout :title="$staff->name">
    <x-flash />

    <x-page-header :title="$staff->name"
                   :description="__('Change what this person can do, or stop them signing in.')" />

    <form method="POST" action="{{ route('settings.staff.update', $staff) }}" class="mt-5 max-w-2xl space-y-5">
        @csrf
        @method('PUT')

        <x-card>
            @include('settings.staff._form', ['staff' => $staff, 'roles' => $roles])
        </x-card>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('settings.staff.index') }}"
               class="tap-target inline-flex items-center px-3 text-sm font-medium text-gray-600 hover:underline dark:text-gray-400">
                {{ __('Cancel') }}
            </a>
            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Save changes') }}
            </button>
        </div>
    </form>
</x-app-layout>
