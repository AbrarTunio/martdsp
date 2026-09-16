{{--
    After the sale: the change to hand back, big, and the receipt one tap
    away. "Next customer" puts the cursor back in the scan box.
--}}
<x-pos.sheet name="done" :title="__('Sale complete')">
    <template x-if="done">
        <div class="text-center">
            <x-success-tick x-bind:class="done.queued
                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
                                : 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300'" />

            <template x-if="! done.queued">
                <p class="mt-2 font-mono text-sm font-semibold" x-text="done.invoice"></p>
            </template>

            <template x-if="done.queued">
                <p class="mt-2 text-sm font-semibold text-amber-700 dark:text-amber-300">{{ __('Saved on this till') }}</p>
            </template>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                <span x-text="format(done.total_paisa)"></span>
                <template x-if="done.customer">
                    <span>· <span x-text="done.customer"></span></span>
                </template>
            </p>

            <template x-if="done.change_paisa > 0">
                <div class="mt-4 rounded-xl bg-brand-50 px-4 py-4 dark:bg-brand-500/10">
                    <p class="text-sm font-medium text-brand-900 dark:text-brand-200">{{ __('Give back') }}</p>
                    <p class="text-4xl font-bold tabular-nums text-brand-800 dark:text-brand-200" x-text="format(done.change_paisa)"></p>
                </div>
            </template>

            <template x-if="done.due_paisa > 0">
                <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="text-sm">
                        <span class="font-semibold" x-text="format(done.due_paisa)"></span> {{ __('went on the khata.') }}
                    </p>
                    <template x-if="done.customer_balance">
                        <p class="text-sm">
                            {{ __('They now owe') }} <span class="font-semibold" x-text="done.customer_balance"></span>.
                        </p>
                    </template>
                </div>
            </template>

            <template x-if="done.queued">
                <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-left text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">{{ __('The shop computer could not be reached.') }}</p>
                    <p class="mt-1">
                        {{ __('The bill is held on this till and will be sent by itself when the connection comes back. Take the money and serve the next customer. The slip can be printed once it has gone through.') }}
                    </p>
                    <p class="mt-1">{{ __('Do not close this tab until the counter shows nothing waiting.') }}</p>
                </div>
            </template>

            <template x-if="done.printed">
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('The slip is coming out of the counter printer. Print again only if it did not.') }}
                </p>
            </template>

            <div class="mt-4 grid grid-cols-2 gap-2" x-show="! done.queued">
                <button type="button" x-on:click="printReceipt()"
                        class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                    <x-icon name="printer" class="h-4.5 w-4.5" />
                    {{ __('Print receipt') }}
                </button>

                <button type="button" x-on:click="printReceipt('a4')"
                        class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('A4 invoice') }}
                </button>

                <a x-bind:href="done.receipt_url" target="_blank" rel="noopener"
                   class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
                    <x-icon name="receipt" class="h-4.5 w-4.5" />
                    {{ __('Receipt on screen') }}
                </a>

                <a x-bind:href="config.urls.sale.replace('__ID__', done.id)"
                   class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('View the bill') }}
                </a>
            </div>
        </div>
    </template>

    <x-slot:footer>
        <button type="button" x-on:click="nextCustomer()"
                class="inline-flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 text-lg font-bold text-white shadow-sm hover:bg-brand-700">
            {{ __('Next customer') }}
            <x-icon name="chevron-right" class="h-5 w-5" />
        </button>
    </x-slot:footer>
</x-pos.sheet>
