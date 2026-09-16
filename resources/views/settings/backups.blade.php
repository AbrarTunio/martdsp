@php
    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    /** Bytes are meaningless to a shopkeeper; MB is not. */
    $size = static function (int $bytes): string {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    };

    $failed = $lastRun?->action === 'backup.failed';
@endphp

<x-app-layout :title="__('Backups')">
    <x-flash />

    <x-page-header :title="__('Backups')"
                   :description="__('One file a night holding the whole shop — every sale, every khata, every product. Point it at a USB drive and the shop survives the computer.')">
        <a href="{{ route('settings.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to settings') }}
        </a>
    </x-page-header>

    @if ($problem)
        <div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">
            {{ $problem }}
        </div>
    @endif

    <div class="mt-4 grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <form method="POST" action="{{ route('settings.backups.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-card :title="__('When and where')"
                        :description="__('The copy is made while the shop is shut. If the folder is on a drive that is unplugged at night, nothing is written — so pick a folder that is always there, and plug the drive in now and then to take a copy away.')">
                    <div class="space-y-4">
                        <x-settings.toggle
                            name="enabled"
                            :label="__('Make a copy every night')"
                            :hint="__('Switch this off only while moving the shop to another computer.')"
                            :checked="(bool) old('enabled', $values['enabled'])" />

                        <x-field name="folder" :label="__('Folder to write into')"
                                 :hint="__('Leave it blank to keep the copies inside the application. A drive letter such as E:\\SuperMart puts them on a USB stick instead.')">
                            <input type="text" name="folder" id="folder" maxlength="255" spellcheck="false"
                                   value="{{ old('folder', $values['folder']) }}"
                                   placeholder="{{ $folder }}"
                                   class="{{ $inputClass }} font-mono">
                        </x-field>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="keep_days" :label="__('Keep copies for (days)')" required
                                     :hint="__('Older ones are thrown away so the drive does not fill up.')">
                                <input type="number" name="keep_days" id="keep_days" required min="1" max="365"
                                       step="1" inputmode="numeric" value="{{ old('keep_days', $values['keep_days']) }}"
                                       class="{{ $inputClass }}">
                            </x-field>

                            <x-field name="hour" :label="__('Hour of the night')" required
                                     :hint="__('24-hour clock. 23 means eleven at night.')">
                                <input type="number" name="hour" id="hour" required min="0" max="23"
                                       step="1" inputmode="numeric" value="{{ old('hour', $values['hour']) }}"
                                       class="{{ $inputClass }}">
                            </x-field>
                        </div>
                    </div>
                </x-card>

                <div class="flex justify-end">
                    <button type="submit"
                            class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                        {{ __('Save backup settings') }}
                    </button>
                </div>
            </form>

            <x-card :title="__('Copies on this computer')"
                    :description="__('Newest first. Download one to keep it somewhere else — a copy that only exists on this PC is not really a backup.')">
                @forelse ($files as $file)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 text-sm last:border-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-xs font-medium">{{ $file['name'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $file['modified']->diffForHumans() }} · {{ $size($file['bytes']) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <a href="{{ route('settings.backups.download', $file['name']) }}"
                               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                <x-icon name="download" class="h-4 w-4" />
                                {{ __('Download') }}
                            </a>

                            <form method="POST" action="{{ route('settings.backups.destroy', $file['name']) }}"
                                  onsubmit="return confirm('{{ __('Delete this copy for good?') }}')">
                                @csrf
                                @method('DELETE')

                                <button type="submit" title="{{ __('Delete') }}"
                                        class="tap-target inline-flex items-center rounded-lg px-2 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="py-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No copies yet. Press "Back up now" to make the first one and see that it works.') }}
                    </p>
                @endforelse
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card :title="__('Back up now')">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Worth doing before a stock take, before month end, or any time you want a copy to take home.') }}
                </p>

                <form method="POST" action="{{ route('settings.backups.store') }}" class="mt-4">
                    @csrf

                    <button type="submit"
                            class="tap-target inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="download" class="h-4.5 w-4.5" />
                        {{ __('Back up now') }}
                    </button>
                </form>

                <p class="mt-3 break-words text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Writing into :folder', ['folder' => $folder]) }}
                </p>
            </x-card>

            <x-card :title="__('Last copy')">
                @if ($lastRun === null)
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Nothing has been copied yet.') }}
                    </p>
                @elseif ($failed)
                    <x-badge tone="danger">{{ __('Did not work') }}</x-badge>

                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ $lastRun->after['error'] ?? __('No reason was given.') }}
                    </p>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $lastRun->created_at->diffForHumans() }}</p>
                @else
                    <p class="font-mono text-xs font-medium">{{ $lastRun->after['file'] ?? '' }}</p>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ $lastRun->created_at->diffForHumans() }} · {{ $size((int) ($lastRun->after['bytes'] ?? 0)) }}
                    </p>
                @endif
            </x-card>

            <x-card :title="__('Putting the shop back')">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('A copy is a plain database file. Anyone who looks after computers can load it back in a few minutes — hand them the newest file and tell them it came from Super Mart. Keep at least one copy off this PC.') }}
                </p>
            </x-card>
        </div>
    </div>
</x-app-layout>
