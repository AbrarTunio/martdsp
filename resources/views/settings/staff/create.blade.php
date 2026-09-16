<x-app-layout :title="__('Add staff')">
    <x-flash />

    <x-page-header :title="__('Add staff')"
                   :description="__('Create a login for someone who works at the counter.')" />

    <form method="POST" action="{{ route('settings.staff.store') }}" class="mt-5 max-w-2xl space-y-5">
        @csrf

        <x-card>
            @include('settings.staff._form', ['staff' => null, 'roles' => $roles])
        </x-card>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('settings.staff.index') }}"
               class="tap-target inline-flex items-center px-3 text-sm font-medium text-gray-600 hover:underline dark:text-gray-400">
                {{ __('Cancel') }}
            </a>
            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Create account') }}
            </button>
        </div>
    </form>
</x-app-layout>
