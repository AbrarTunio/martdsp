@php
    $inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    // The form asks for a different thing depending on the connection, so the
    // label, the placeholder and the drawer question all follow the choice.
    $meta = collect($channels)->mapWithKeys(fn ($channel) => [$channel->value => [
        'label' => __($channel->label()),
        'hint' => __($channel->hint()),
        'target' => $channel->targetLabel() ? __($channel->targetLabel()) : null,
        'placeholder' => $channel->targetPlaceholder(),
        'direct' => $channel->isDirect(),
    ]])->all();
@endphp

<x-app-layout :title="__('Printers')">
    <x-flash />

    <x-page-header :title="__('Printers')"
                   :description="__('A thermal printer is only set up once it has printed something. Add it here, press Test print, and look at what comes out of the machine.')">
        <a href="{{ route('settings.registers.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Counters') }}
        </a>

        <a href="{{ route('settings.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to settings') }}
        </a>
    </x-page-header>

    <x-input-error :messages="$errors->get('printer')" class="mt-4" />

    <x-card :title="__('Add a printer')"
            :description="__('The shop will remember how to reach it. Nothing here changes the printer itself.')"
            class="mt-4">
        <form method="POST" action="{{ route('settings.printers.store') }}" class="grid gap-3 sm:grid-cols-12"
              x-data="{
                  meta: @js($meta),
                  channel: @js(old('channel', 'windows_share')),
                  get current() { return this.meta[this.channel] ?? {}; },
              }">
            @csrf

            <div class="sm:col-span-4">
                <label for="printer-name" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Name') }}</label>
                <input id="printer-name" name="name" required maxlength="60" autocomplete="off" value="{{ old('name') }}"
                       placeholder="{{ __('Counter printer') }}" class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-4">
                <label for="printer-channel" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('How it is connected') }}</label>
                <select id="printer-channel" name="channel" x-model="channel" class="{{ $inputClass }} mt-1">
                    @foreach ($channels as $channel)
                        <option value="{{ $channel->value }}" @selected(old('channel', 'windows_share') === $channel->value)>
                            {{ __($channel->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-4" x-show="current.direct" x-cloak>
                <label for="printer-target" class="block text-xs font-medium text-gray-600 dark:text-gray-400"
                       x-text="current.target">{{ __('Share name') }}</label>
                <input id="printer-target" name="target" maxlength="255" autocomplete="off" value="{{ old('target') }}"
                       x-bind:placeholder="current.placeholder" class="{{ $inputClass }} mt-1 font-mono">
            </div>

            <p class="text-xs text-gray-500 sm:col-span-12 dark:text-gray-400" x-text="current.hint"></p>

            <div class="sm:col-span-3">
                <label for="printer-paper" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Roll width') }}</label>
                <select id="printer-paper" name="paper" class="{{ $inputClass }} mt-1">
                    @foreach ($papers as $value => $columns)
                        <option value="{{ $value }}" @selected(old('paper', '80') === (string) $value)>
                            {{ __(':mm mm · :columns characters', ['mm' => $value, 'columns' => $columns]) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-3">
                <label for="printer-feed" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Blank lines at the end') }}</label>
                <input id="printer-feed" type="number" name="feed_lines" min="0" max="10" value="{{ old('feed_lines', 4) }}"
                       class="{{ $inputClass }} mt-1">
            </div>

            <div class="flex flex-wrap items-end gap-4 sm:col-span-4">
                <label class="tap-target inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="cuts" value="1" @checked(old('cuts', true))
                           class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                    {{ __('It cuts the paper') }}
                </label>

                <label class="tap-target inline-flex items-center gap-2 text-sm" x-show="current.direct" x-cloak>
                    <input type="checkbox" name="has_drawer" value="1" @checked(old('has_drawer'))
                           class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                    {{ __('A cash drawer is plugged into it') }}
                </label>
            </div>

            <div class="flex items-end sm:col-span-2">
                <button type="submit"
                        class="tap-target w-full rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Add') }}
                </button>
            </div>
        </form>

        @foreach (['name', 'channel', 'target', 'paper', 'cuts', 'has_drawer', 'drawer_pin', 'feed_lines'] as $field)
            <x-input-error :messages="$errors->get($field)" class="mt-2" />
        @endforeach
    </x-card>

    @if ($printers->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="printer" :title="__('No printer is set up yet')"
                           :description="__('Until one is, every receipt goes through the browser print dialog. That works, and it is the only way to print from a phone — but the cash drawer will not pop by itself.')" />
        </div>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($printers as $printer)
                <li @class([
                    'rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900',
                    'opacity-70' => ! $printer->is_active,
                ])>
                    <form method="POST" action="{{ route('settings.printers.update', $printer) }}"
                          class="grid gap-2 sm:grid-cols-12 sm:items-center"
                          x-data="{
                              meta: @js($meta),
                              channel: @js($printer->channel->value),
                              get current() { return this.meta[this.channel] ?? {}; },
                          }">
                        @csrf
                        @method('PATCH')

                        <input name="name" value="{{ $printer->name }}" required maxlength="60" autocomplete="off"
                               aria-label="{{ __('Name') }}" class="{{ $inputClass }} sm:col-span-3">

                        <select name="channel" x-model="channel" aria-label="{{ __('How it is connected') }}"
                                class="{{ $inputClass }} sm:col-span-3">
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->value }}" @selected($printer->channel === $channel)>
                                    {{ __($channel->label()) }}
                                </option>
                            @endforeach
                        </select>

                        <input name="target" value="{{ $printer->target }}" maxlength="255" autocomplete="off"
                               x-show="current.direct" x-cloak x-bind:placeholder="current.placeholder"
                               x-bind:aria-label="current.target" class="{{ $inputClass }} font-mono sm:col-span-3">

                        <select name="paper" aria-label="{{ __('Roll width') }}" class="{{ $inputClass }} sm:col-span-2">
                            @foreach ($papers as $value => $columns)
                                <option value="{{ $value }}" @selected($printer->paper === (string) $value)>{{ $value }} mm</option>
                            @endforeach
                        </select>

                        <button type="submit"
                                class="tap-target rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 sm:col-span-1 dark:border-gray-700 dark:hover:bg-gray-800">
                            {{ __('Save') }}
                        </button>

                        <div class="flex flex-wrap items-center gap-4 sm:col-span-12">
                            <label class="tap-target inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="is_active" value="1" @checked($printer->is_active)
                                       class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ __('In use') }}
                            </label>

                            <label class="tap-target inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="cuts" value="1" @checked($printer->cuts)
                                       class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ __('Cuts the paper') }}
                            </label>

                            <label class="tap-target inline-flex items-center gap-2 text-sm" x-show="current.direct" x-cloak>
                                <input type="checkbox" name="has_drawer" value="1" @checked($printer->has_drawer)
                                       class="h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ __('Cash drawer plugged in') }}
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm" x-show="current.direct" x-cloak>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Drawer pin') }}</span>
                                <select name="drawer_pin" aria-label="{{ __('Drawer pin') }}"
                                        class="tap-target rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <option value="0" @selected($printer->drawer_pin === 0)>{{ __('Pin 2 (usual)') }}</option>
                                    <option value="1" @selected($printer->drawer_pin === 1)>{{ __('Pin 5') }}</option>
                                </select>
                            </label>

                            <label class="inline-flex items-center gap-2 text-sm">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Blank lines') }}</span>
                                <input type="number" name="feed_lines" min="0" max="10" value="{{ $printer->feed_lines }}"
                                       aria-label="{{ __('Blank lines at the end') }}"
                                       class="tap-target w-20 rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            </label>
                        </div>
                    </form>

                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <p class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                            {{ $printer->describe() }}
                            @if ($printer->registers_count > 0)
                                · {{ trans_choice(':count counter prints here|:count counters print here', $printer->registers_count, ['count' => $printer->registers_count]) }}
                            @endif
                        </p>

                        @if ($printer->isDirect())
                            <form method="POST" action="{{ route('settings.printers.test', $printer) }}">
                                @csrf
                                <button type="submit"
                                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                                    <x-icon name="printer" class="h-4.5 w-4.5" />
                                    {{ __('Test print') }}
                                </button>
                            </form>
                        @endif

                        @if ($printer->canPulse() && auth()->user()->can('supervise'))
                            <form method="POST" action="{{ route('settings.printers.drawer', $printer) }}">
                                @csrf
                                <button type="submit"
                                        class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                    <x-icon name="wallet" class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
                                    {{ __('Open drawer') }}
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('settings.printers.destroy', $printer) }}"
                              x-data x-on:submit="if (! confirm(@js($printer->registers_count > 0 ? __('A counter still points at this printer, so it will be switched off rather than removed. Go ahead?') : __('Remove this printer?')))) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="tap-target grid place-items-center rounded-lg px-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                    aria-label="{{ __('Remove') }}">
                                <x-icon name="close" class="h-4 w-4" />
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <x-card :title="__('If the test print does nothing')" class="mt-4">
        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('Share the printer in Windows and give it a short share name with no spaces, then write it here as \\\\localhost\\THERMAL.') }}</li>
            <li>{{ __('If the shop server runs as a Windows service, it does not see printers you mapped while logged in. Share the printer for Everyone, or give the service your own Windows account.') }}</li>
            <li>{{ __('A printer with a network port is the surer route: give it a fixed address on the shop router and use that instead.') }}</li>
            <li>{{ __('Phones can never print directly. A phone sends the sale, the PC at the counter prints it.') }}</li>
        </ul>
    </x-card>
</x-app-layout>
