@php($inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<x-app-layout :title="__('Counters')">
    <x-flash />

    <x-page-header :title="__('Counters')"
                   :description="__('Each till in the shop. Every counter keeps its own cash drawer, and can print on a different paper from the rest.')">
        <a href="{{ route('settings.printers.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="printer" class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
            {{ __('Printers') }}
        </a>

        <a href="{{ route('settings.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to settings') }}
        </a>
    </x-page-header>

    <x-input-error :messages="$errors->get('register')" class="mt-4" />

    <x-card :title="__('Add a counter')" class="mt-4">
        <form method="POST" action="{{ route('settings.registers.store') }}" class="grid gap-3 sm:grid-cols-12">
            @csrf

            <div class="sm:col-span-3">
                <label for="name" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Name') }}</label>
                <input id="name" name="name" required maxlength="60" autocomplete="off" value="{{ old('name') }}"
                       placeholder="{{ __('Counter 2') }}" class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-2">
                <label for="location" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Where') }}</label>
                <input id="location" name="location" maxlength="120" autocomplete="off" value="{{ old('location') }}"
                       placeholder="{{ __('By the door') }}" class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-3">
                <label for="printer_id" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Printer') }}</label>
                <select id="printer_id" name="printer_id" class="{{ $inputClass }} mt-1">
                    <option value="">{{ __('Through the browser') }}</option>
                    @foreach ($printers as $printer)
                        <option value="{{ $printer->id }}" @selected((string) old('printer_id') === (string) $printer->id)>{{ $printer->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label for="printer_profile" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Paper') }}</label>
                <select id="printer_profile" name="printer_profile" class="{{ $inputClass }} mt-1">
                    <option value="">{{ __('As the printer') }}</option>
                    @foreach ($papers as $value => $label)
                        <option value="{{ $value }}" @selected(old('printer_profile') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end sm:col-span-2">
                <button type="submit"
                        class="tap-target w-full rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Add') }}
                </button>
            </div>
        </form>

        @foreach (['name', 'location', 'printer_id', 'printer_profile'] as $field)
            <x-input-error :messages="$errors->get($field)" class="mt-2" />
        @endforeach
    </x-card>

    <ul class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @foreach ($registers as $register)
            <li @class(['px-3 py-3', 'bg-gray-50 dark:bg-gray-800/40' => ! $register->is_active])>
                <div class="flex flex-wrap items-start gap-2">
                    <form method="POST" action="{{ route('settings.registers.update', $register) }}"
                          class="grid min-w-0 flex-1 gap-2 sm:grid-cols-12 sm:items-center">
                        @csrf
                        @method('PATCH')

                        <input name="name" value="{{ $register->name }}" required maxlength="60" autocomplete="off"
                               aria-label="{{ __('Name') }}" class="{{ $inputClass }} sm:col-span-2">

                        <input name="location" value="{{ $register->location }}" maxlength="120" autocomplete="off"
                               placeholder="{{ __('Where') }}" aria-label="{{ __('Where') }}" class="{{ $inputClass }} sm:col-span-2">

                        <select name="printer_id" aria-label="{{ __('Printer') }}" class="{{ $inputClass }} sm:col-span-3">
                            <option value="">{{ __('Through the browser') }}</option>
                            @foreach ($printers as $printer)
                                <option value="{{ $printer->id }}" @selected($register->printer_id === $printer->id)>{{ $printer->name }}</option>
                            @endforeach
                        </select>

                        <select name="printer_profile" aria-label="{{ __('Receipt paper') }}" class="{{ $inputClass }} sm:col-span-2">
                            <option value="">{{ __('As the printer') }}</option>
                            @foreach ($papers as $value => $label)
                                <option value="{{ $value }}" @selected($register->printer_profile === (string) $value)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <div class="flex items-center gap-2 sm:col-span-3">
                            <label class="tap-target inline-flex flex-1 items-center gap-2 text-sm">
                                <input type="checkbox" name="is_active" value="1" @checked($register->is_active)
                                       class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ __('In use') }}
                            </label>

                            <button type="submit"
                                    class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                {{ __('Save') }}
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('settings.registers.destroy', $register) }}" class="shrink-0"
                          x-data x-on:submit="if (! confirm(@js($register->sales_count > 0 ? __('This counter has sales on record, so it will be switched off rather than removed. Go ahead?') : __('Remove this counter?')))) $event.preventDefault()">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="tap-target grid place-items-center rounded-lg px-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                aria-label="{{ __('Remove') }}">
                            <x-icon name="close" class="h-4 w-4" />
                        </button>
                    </form>
                </div>

                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ trans_choice('{0}No sales yet|{1}1 sale on record|[2,*]:count sales on record', $register->sales_count, ['count' => number_format($register->sales_count)]) }}
                    · {{ __('prints on') }} {{ $papers[$register->paper()] ?? $register->paper() }}
                    {{ $register->printer ? __('at :printer', ['printer' => $register->printer->name]) : __('through the browser') }}
                    @unless ($register->is_active) · <span class="font-medium">{{ __('switched off') }}</span> @endunless
                </p>
            </li>
        @endforeach
    </ul>
</x-app-layout>
