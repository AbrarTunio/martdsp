{{--
    Flash messages and the validation error summary.
    Placed once per page, near the top of the content area.
--}}
@if (session('status'))
    <div x-data="{ shown: true }" x-show="shown" x-rise
         class="mb-4 flex items-start gap-2.5 rounded-xl border border-brand-200 bg-brand-50 px-3.5 py-3 text-sm text-brand-900 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200"
         role="status">
        <x-icon name="check" class="mt-px h-4.5 w-4.5 shrink-0" />
        <p class="flex-1">{{ session('status') }}</p>
        <button type="button" x-on:click="shown = false" class="shrink-0 opacity-60 hover:opacity-100"
                aria-label="{{ __('Dismiss') }}">
            <x-icon name="close" class="h-4 w-4" />
        </button>
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
         role="alert">
        <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0" />
        <div class="flex-1">
            <p class="font-medium">
                {{ trans_choice('Please fix the following problem:|Please fix the following problems:', $errors->count()) }}
            </p>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
