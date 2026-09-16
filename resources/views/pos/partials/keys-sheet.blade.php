@php
    /*
     | The counter keyboard, written out. A cashier who works the till all day
     | will learn three or four of these and never touch the mouse again.
     */
    $keys = [
        ['F1', __('Show or hide this list')],
        ['F2', __('Search for an item by name')],
        ['F3', __('Pick the customer for the khata')],
        ['F4', __('Baskets put on hold')],
        ['F6', __('Put this basket on hold')],
        ['F7', __('Take the last line off')],
        ['F8', __('Give a discount on the last line')],
        ['F9', __('Pay')],
        ['+ / −', __('One more, one fewer of the last line')],
        [__('Esc'), __('Close what is open, or clear the scan box')],
    ];
@endphp

<x-pos.sheet name="keys" :title="__('Keyboard')">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('The scan box keeps the cursor, so these work wherever you are on the till. Plus and minus only count when the scan box is empty, so a barcode is never interrupted.') }}
    </p>

    <dl class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
        @foreach ($keys as [$key, $does])
            <div class="flex items-center justify-between gap-4 py-2">
                <dt class="shrink-0">
                    <kbd class="rounded border border-gray-300 bg-gray-50 px-2 py-1 font-sans text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $key }}</kbd>
                </dt>
                <dd class="min-w-0 flex-1 text-right text-sm">{{ $does }}</dd>
            </div>
        @endforeach
    </dl>
</x-pos.sheet>
