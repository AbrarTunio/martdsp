@php
    use App\Support\Navigation;

    $sections = [
        'main' => null,
        'inventory' => 'Inventory',
        'money' => 'Money',
        'system' => 'System',
    ];
    $grouped = Navigation::grouped();
@endphp

{{-- Desktop only. Phones navigate from the bottom tab bar instead. --}}
<aside class="no-print hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
    <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-gray-200 px-5 dark:border-gray-800">
        <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-600 text-white">
            <x-icon name="cart" class="h-5 w-5" />
        </span>
        <span class="truncate text-base font-semibold tracking-tight">{{ config('app.name') }}</span>
    </div>

    <nav class="scroll-slim flex-1 overflow-y-auto overscroll-contain px-3 py-4">
        @foreach ($sections as $key => $heading)
            @if (! empty($grouped[$key]))
                <div @class(['mt-6' => ! $loop->first])>
                    @if ($heading)
                        <p class="px-2 pb-1.5 text-[0.6875rem] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {{ __($heading) }}
                        </p>
                    @endif

                    <ul class="space-y-0.5">
                        @foreach ($grouped[$key] as $item)
                            @php($active = Navigation::isActive($item['route']))
                            <li>
                                <a href="{{ route($item['route']) }}"
                                   @class([
                                       'group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition',
                                       'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $active,
                                       'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' => ! $active,
                                   ])
                                   @if ($active) aria-current="page" @endif>
                                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                    {{ __($item['label']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>

    <div class="border-t border-gray-200 p-3 dark:border-gray-800">
        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                {{ Str::upper(Str::substr(auth()->user()->name, 0, 2)) }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ auth()->user()->role->label() }}
                </span>
            </span>
        </a>
    </div>
</aside>
