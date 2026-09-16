<x-app-layout :title="$adjustment->reference">
    <x-flash />

    <x-page-header :title="__('Edit :reference', ['reference' => $adjustment->reference])"
                   :description="__('This correction has not been posted, so nothing has changed yet.')" />

    <form method="POST" action="{{ route('stock.adjustments.update', $adjustment) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('stock.adjustments._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('stock.adjustments.show', $adjustment) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit" name="action" value="draft"
                    class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Save for later') }}
            </button>

            <button type="submit" name="action" value="post"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                {{ __('Post and correct stock') }}
            </button>
        </div>

        <p class="mt-2 text-right text-xs text-gray-500 dark:text-gray-400">
            {{ __('Posting writes to the stock ledger and cannot be undone — a mistake is fixed with another correction.') }}
        </p>
    </form>
</x-app-layout>
