<x-app-layout :title="$take->reference">
    <x-flash />

    <x-page-header :title="__('Carry on counting :reference', ['reference' => $take->reference])"
                   :description="__('This count has not been posted, so nothing has changed yet.')" />

    <form method="POST" action="{{ route('stock.takes.update', $take) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('stock.takes._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('stock.takes.show', $take) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit" name="action" value="draft"
                    class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Save and carry on later') }}
            </button>

            <button type="submit" name="action" value="post"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                {{ __('Post the count') }}
            </button>
        </div>

        <p class="mt-2 text-right text-xs text-gray-500 dark:text-gray-400">
            {{ __('Posting sets stock to what you counted and cannot be undone — a mistake is fixed by counting again.') }}
        </p>
    </form>
</x-app-layout>
