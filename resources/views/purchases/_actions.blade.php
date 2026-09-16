{{--
    The buttons under the delivery form, included from inside its Alpine
    scope. Receive asks first, because it is the one step that cannot be
    edited afterwards.
--}}
<div>
<div class="flex flex-wrap justify-end gap-2">
    <a href="{{ $cancelUrl }}"
       class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
        {{ __('Cancel') }}
    </a>

    <button type="submit" name="action" value="draft"
            class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
        {{ __('Save, receive later') }}
    </button>

    <button type="submit" name="action" value="receive"
            x-on:click="confirmReceive($event)"
            class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
        <x-icon name="check" class="h-4.5 w-4.5" />
        {{ __('Receive into stock') }}
    </button>
</div>

<p class="mt-2 text-right text-xs text-gray-500 dark:text-gray-400">
    {{ __('Receiving puts the goods on the shelf, updates what each item costs you, and adds the bill to the supplier\'s account.') }}
</p>
</div>
