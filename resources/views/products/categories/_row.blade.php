{{-- One editable category line. Sub-categories are indented rather than nested in their own card. --}}
@php($rowInput = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<div @class([
    'flex flex-wrap items-center gap-2 px-3 py-2.5',
    'border-t border-gray-100 dark:border-gray-800' => $depth > 0,
    'pl-8' => $depth > 0,
])>
    <form method="POST" action="{{ route('products.categories.update', $category) }}"
          class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
        @csrf
        @method('PATCH')
        <input type="hidden" name="parent_id" value="{{ $category->parent_id }}">

        <input name="name" value="{{ $category->name }}" required autocomplete="off"
               class="{{ $rowInput }} min-w-0 flex-1 sm:max-w-xs">

        <input name="name_ur" value="{{ $category->name_ur }}" dir="rtl" autocomplete="off"
               placeholder="{{ __('Urdu') }}"
               class="{{ $rowInput }} min-w-0 flex-1 font-urdu sm:max-w-[10rem]">

        <label class="flex shrink-0 items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked($category->is_active)
                   class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
            {{ __('In use') }}
        </label>

        <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">
            {{ trans_choice('{0}no products|{1}1 product|[2,*]:count products', $category->products_count ?? 0, ['count' => $category->products_count ?? 0]) }}
        </span>

        <button type="submit"
                class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Save') }}
        </button>
    </form>

    <form method="POST" action="{{ route('products.categories.destroy', $category) }}" class="shrink-0"
          x-data x-on:submit="if (! confirm('{{ __('Remove this category?') }}')) $event.preventDefault()">
        @csrf
        @method('DELETE')
        <button type="submit"
                class="tap-target grid place-items-center rounded-lg px-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                aria-label="{{ __('Remove') }}">
            <x-icon name="close" class="h-4 w-4" />
        </button>
    </form>
</div>
