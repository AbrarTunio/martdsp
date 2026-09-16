{{-- One line of the basket. Rendered inside the till's x-for, so `line` and `index` are in scope. --}}
<li class="rounded-xl border bg-white p-3 transition-colors dark:bg-gray-900"
    x-bind:class="lineProblem(line)
        ? 'border-red-300 dark:border-red-500/50'
        : (line.uid === lastUid ? 'border-brand-400 bg-brand-50/60 dark:border-brand-500/60 dark:bg-brand-500/5' : 'border-gray-200 dark:border-gray-800')">
    <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold" x-text="line.item.name"></p>
            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                <span x-text="format(priceFor(line))"></span>
                <span x-text="'/ ' + unitName(line).toLowerCase()"></span>
                <template x-if="line.item.name_ur">
                    <span class="font-urdu" dir="rtl" x-text="'· ' + line.item.name_ur"></span>
                </template>
            </p>
        </div>

        <div class="shrink-0 text-right">
            <p class="text-base font-semibold tabular-nums" x-text="format(lineTotal(index))"></p>
            <template x-if="lineDiscount(index) > 0">
                <p class="text-xs text-money-in tabular-nums" x-text="'−' + format(lineDiscount(index))"></p>
            </template>
        </div>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <div class="flex shrink-0 items-center rounded-lg border border-gray-300 dark:border-gray-700">
            <button type="button" x-on:click="decrease(line)"
                    class="grid h-11 w-11 place-items-center text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    x-bind:aria-label="`{{ __('One less') }} ${line.item.name}`">
                <x-icon name="minus" class="h-4.5 w-4.5" />
            </button>

            <input type="text" inputmode="decimal" autocomplete="off" x-model="line.qty"
                   x-on:focus="$el.select()"
                   class="h-11 w-16 border-0 bg-transparent px-1 text-center text-base font-semibold tabular-nums focus:ring-2 focus:ring-brand-500"
                   aria-label="{{ __('How many') }}">

            <button type="button" x-on:click="increase(line)"
                    class="grid h-11 w-11 place-items-center text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    x-bind:aria-label="`{{ __('One more') }} ${line.item.name}`">
                <x-icon name="plus" class="h-4.5 w-4.5" />
            </button>
        </div>

        <template x-if="line.item.units.length > 1">
            <select x-model="line.product_unit_id"
                    class="h-11 min-w-0 flex-1 rounded-lg border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 sm:max-w-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    aria-label="{{ __('Size') }}">
                <template x-for="unit in line.item.units" x-bind:key="unit.id">
                    <option x-bind:value="String(unit.id)" x-text="`${unit.label} — ${format(unit.price_paisa)}`"
                            x-bind:selected="String(unit.id) === line.product_unit_id"></option>
                </template>
            </select>
        </template>

        <template x-if="line.item.units.length <= 1">
            <span class="min-w-0 flex-1 truncate text-sm text-gray-600 dark:text-gray-400" x-text="unitFor(line)?.label"></span>
        </template>

        <div class="ml-auto flex shrink-0 items-center">
            <button type="button" x-on:click="toggleDiscount(line)"
                    class="tap-target rounded-lg px-2 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                    x-bind:class="line.discount && 'text-brand-700 dark:text-brand-400'">
                {{ __('Discount') }}
            </button>

            <button type="button" x-on:click="remove(line)"
                    class="tap-target grid place-items-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                    x-bind:aria-label="`{{ __('Remove') }} ${line.item.name}`">
                <x-icon name="close" class="h-4.5 w-4.5" />
            </button>
        </div>
    </div>

    <div x-show="openDiscountUid === line.uid || line.discount" x-cloak class="mt-2 flex items-center gap-2">
        <label class="shrink-0 text-xs font-medium text-gray-600 dark:text-gray-400" x-bind:for="`discount-${line.uid}`">
            {{ __('Off this line') }}
        </label>
        <input type="text" inputmode="decimal" autocomplete="off" maxlength="20" x-model="line.discount"
               x-bind:id="`discount-${line.uid}`" placeholder="{{ __('50 or 10%') }}"
               class="h-10 w-32 rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
    </div>

    <template x-if="lineProblem(line)">
        <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-red-700 dark:text-red-400">
            <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0" />
            <span x-text="lineProblem(line)"></span>
        </p>
    </template>

    <template x-if="! lineProblem(line) && stockWarning(line)">
        <p class="mt-2 flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
            <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0" />
            <span x-text="stockWarning(line)"></span>
        </p>
    </template>
</li>
