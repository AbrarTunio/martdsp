{{--
    The price-check gun.

    A USB scanner is a keyboard that types fast and presses Enter, so this is
    just a text field that submits on Enter and reports what the code means:
    which item, which packaging size, and that size's price. Scanning the
    carton and the sachet of the same item gives two different answers, which
    is the whole point of the packaging model.
--}}
<div class="mt-4" x-data="{
    code: '',
    busy: false,
    result: null,
    async check() {
        const code = this.code.trim();

        if (code === '') {
            return;
        }

        this.busy = true;
        this.result = null;

        try {
            const response = await fetch(`{{ route('products.lookup') }}?code=${encodeURIComponent(code)}`, {
                headers: { Accept: 'application/json' },
            });
            this.result = await response.json();
            this.result.scanned = code;
        } catch (error) {
            this.result = { found: false, scanned: code, offline: true };
        } finally {
            this.busy = false;
            this.code = '';
            this.$refs.field.focus();
        }
    },
}">
    <x-card :title="__('Price check')"
            :description="__('Scan or type a barcode to see what it rings up as.')">
        <form x-on:submit.prevent="check()" class="flex gap-2">
            <input x-model="code" x-ref="field" type="text" inputmode="text" autocomplete="off"
                   enterkeyhint="search" placeholder="{{ __('Scan here') }}"
                   class="tap-target block w-full rounded-md border-gray-300 font-mono text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <button type="submit" x-bind:disabled="busy"
                    class="tap-target inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600">
                <x-icon name="scan" class="h-4.5 w-4.5" />
                <span class="hidden sm:inline">{{ __('Check') }}</span>
            </button>
        </form>

        <template x-if="result && result.found">
            <div class="mt-3 rounded-lg border border-brand-200 bg-brand-50 p-3 dark:border-brand-500/30 dark:bg-brand-500/10">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" x-text="result.product.name"></p>
                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                            <span x-text="result.unit.label"></span>
                            · <span class="font-mono" x-text="result.scanned"></span>
                        </p>
                    </div>

                    <p class="shrink-0 text-base font-semibold tabular-nums" x-text="result.unit.price"></p>
                </div>

                <template x-if="result.product.url">
                    <a x-bind:href="result.product.url"
                       class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                        {{ __('Open this product') }}
                        <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                    </a>
                </template>
            </div>
        </template>

        <template x-if="result && ! result.found">
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="font-medium">
                    <span x-text="result.offline ? '{{ __('Could not reach the shop computer.') }}' : '{{ __('Nothing uses this barcode yet.') }}'"></span>
                </p>
                <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                    <span class="font-mono" x-text="result.scanned"></span>
                    @can('supervise')
                        — {{ __('add it to a product to make it scannable.') }}
                    @endcan
                </p>
            </div>
        </template>
    </x-card>
</div>
