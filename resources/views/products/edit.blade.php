<x-app-layout :title="$product->name">
    <x-flash />

    <x-page-header :title="$product->name" :description="$product->sku">
        <a href="{{ route('products.labels.create', ['product' => $product->id]) }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Labels') }}
        </a>
    </x-page-header>

    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('In stock now') }}</p>
        <p class="mt-0.5 font-semibold">{{ $product->stockBreakdown() }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Stock is changed by purchases, sales and adjustments, never by editing this page.') }}
        </p>
    </div>

    <form method="POST" action="{{ route('products.update', $product) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('products._form')

        <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
            @if ($product->is_active)
                <button type="submit" form="hide-product"
                        class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                    {{ __('Hide from the till') }}
                </button>
            @else
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('This item is hidden from the till.') }}</span>
            @endif

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('products.index') }}"
                   class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('Cancel') }}
                </a>

                <button type="submit"
                        class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                    {{ __('Save changes') }}
                </button>
            </div>
        </div>
    </form>

    {{-- Kept outside the edit form: a form cannot be nested inside another. --}}
    <form method="POST" action="{{ route('products.destroy', $product) }}" id="hide-product" class="hidden"
          x-data x-on:submit="if (! confirm('{{ __('Hide this item from the till? Nothing is deleted.') }}')) $event.preventDefault()">
        @csrf
        @method('DELETE')
    </form>
</x-app-layout>
