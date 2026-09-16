<x-app-layout :title="__('Reports')">
    <x-flash />

    <x-page-header :title="__('Reports')"
                   :description="__('How the shop is doing and where the money is. Every report prints on A4 and downloads for Excel.')">
        <x-ai-insight-button />
    </x-page-header>

    <div class="mt-5 space-y-6">
        @foreach ($sections as $section => $reports)
            <section>
                <h2 class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $section }}</h2>

                <div class="mt-2 grid gap-2 sm:grid-cols-2 sm:gap-3" x-data>
                    @foreach ($reports as $key => $report)
                        <a href="{{ route('reports.show', $key) }}" x-rise="{{ $loop->index * 0.04 }}"
                           class="group flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-brand-500/50">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-icon :name="$report->icon()" class="h-5 w-5" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ $report->title() }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $report->description() }}</span>
                            </span>

                            <x-icon name="chevron-right" class="mt-2.5 h-4 w-4 shrink-0 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-brand-600" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-app-layout>
