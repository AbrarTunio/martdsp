@php
    use App\Enums\AiProvider;
    use App\Support\Money;

    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    /*
     | Which questions the form asks changes with the company picked: only a
     | custom provider needs an address, and each one has its own place to
     | get a key and a sensible model to start on.
     */
    $meta = collect(AiProvider::cases())->mapWithKeys(fn (AiProvider $case): array => [$case->value => [
        'label' => $case->label(),
        'hint' => __($case->keyHint()),
        'baseUrl' => $case->baseUrl(),
        'needsBaseUrl' => $case->needsBaseUrl(),
        'suggested' => $case->suggestedModel(),
    ]])->all();

    $chosen = old('provider', $provider?->value ?? '');
    $savedModel = old('model', $values['ai.model']);

    // The cap is kept in rupees; nothing is spent until a key is saved.
    $capRupees = (int) $values['ai.monthly_cap'];
    $left = max(0, $cap - $spent);
    $share = $cap > 0 ? min(100, (int) round($spent / $cap * 100)) : 0;
@endphp

<x-app-layout :title="__('AI insights')">
    <x-flash />

    <x-page-header :title="__('AI insights')"
                   :description="__('The Insights button reads the figures on a page and explains them in plain words. Use your own account with an AI company; the shop pays that company directly.')">
        <a href="{{ route('settings.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to settings') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('settings.ai.update') }}" class="space-y-5"
                  x-data="{
                      meta: @js($meta),
                      provider: @js($chosen),
                      model: @js($savedModel),
                      models: [],
                      busy: '',
                      result: null,
                      get current() { return this.meta[this.provider] ?? null; },

                      /** Ask the company which models this key may use. */
                      async fetchModels() {
                          this.busy = 'models';
                          this.result = null;

                          const data = await this.send('{{ route('settings.ai.models') }}');

                          if (data.ok) {
                              this.models = data.models;

                              const suggested = this.current?.suggested;

                              if (! this.model && suggested && data.models.some((one) => one.id === suggested)) {
                                  this.model = suggested;
                              }

                              this.result = { ok: true, message: '{{ __('Found :count models.') }}'.replace(':count', data.models.length) };
                          } else {
                              this.result = data;
                          }

                          this.busy = '';
                      },

                      /** Put the smallest possible question to the model. */
                      async test() {
                          this.busy = 'test';
                          this.result = null;
                          this.result = await this.send('{{ route('settings.ai.test') }}');
                          this.busy = '';
                      },

                      async send(url) {
                          try {
                              const response = await fetch(url, {
                                  method: 'POST',
                                  headers: {
                                      'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                      'X-Requested-With': 'XMLHttpRequest',
                                      Accept: 'application/json',
                                      'Content-Type': 'application/json',
                                  },
                                  body: JSON.stringify({
                                      provider: this.provider,
                                      api_key: this.$refs.key.value,
                                      model: this.model,
                                      base_url: this.$refs.baseUrl?.value ?? '',
                                  }),
                              });

                              return await response.json();
                          } catch (error) {
                              return { ok: false, message: '{{ __('The shop could not reach that company. Check the internet connection.') }}' };
                          }
                      },
                  }">
                @csrf
                @method('PUT')

                <x-card :title="__('The AI you are using')"
                        :description="__('Pick the company, paste the key from your account there, then fetch the models and test one.')">
                    <div class="space-y-4">
                        <x-field name="provider" :label="__('Company')">
                            <select name="provider" id="provider" x-model="provider" class="{{ $inputClass }}">
                                <option value="">{{ __('Not set up — use the shop\'s own checks') }}</option>
                                @foreach ($providers as $value => $label)
                                    <option value="{{ $value }}" @selected($chosen === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <p x-show="current" x-text="current?.hint" x-cloak
                           class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-800/60 dark:text-gray-400"></p>

                        <x-field name="api_key" :label="__('API key')"
                                 :hint="$maskedKey
                                     ? __('A key is saved (:masked). Leave this blank to keep it.', ['masked' => $maskedKey])
                                     : __('The key is stored locked away and never shown again.')">
                            <input type="password" name="api_key" id="api_key" x-ref="key" autocomplete="off"
                                   maxlength="300" spellcheck="false"
                                   placeholder="{{ $maskedKey ? __('Leave blank to keep the saved key') : __('Paste the key here') }}"
                                   class="{{ $inputClass }} font-mono">
                        </x-field>

                        <div x-show="current?.needsBaseUrl" x-cloak>
                            <x-field name="base_url" :label="__('Address of the service')"
                                     :hint="__('The part before /chat/completions, such as http://192.168.1.5:11434/v1')">
                                <input type="url" name="base_url" id="base_url" x-ref="baseUrl"
                                       value="{{ old('base_url', $values['ai.base_url']) }}" maxlength="255"
                                       placeholder="https://" class="{{ $inputClass }}">
                            </x-field>
                        </div>

                        <x-field name="model" :label="__('Model')"
                                 :hint="__('A small, quick model is plenty for this. Fetch the list, or type the name yourself.')">
                            <div class="flex flex-wrap gap-2">
                                <div class="min-w-0 flex-1">
                                    <input type="text" name="model" id="model" x-model="model" list="ai-models"
                                           maxlength="120" autocomplete="off" spellcheck="false"
                                           x-bind:placeholder="current?.suggested ?? '{{ __('Model name') }}'"
                                           class="{{ $inputClass }} font-mono">

                                    <datalist id="ai-models">
                                        <template x-for="one in models" x-bind:key="one.id">
                                            <option x-bind:value="one.id" x-text="one.name"></option>
                                        </template>
                                    </datalist>
                                </div>

                                <button type="button" x-on:click="fetchModels()" x-bind:disabled="busy || ! provider"
                                        class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:hover:bg-gray-800">
                                    <x-icon name="refresh" class="h-4 w-4" x-bind:class="busy === 'models' && 'animate-spin'" />
                                    {{ __('Fetch models') }}
                                </button>

                                <button type="button" x-on:click="test()" x-bind:disabled="busy || ! provider"
                                        class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:hover:bg-gray-800">
                                    <x-icon name="check" class="h-4 w-4" />
                                    {{ __('Test') }}
                                </button>
                            </div>
                        </x-field>

                        <p x-show="result" x-cloak x-text="result?.message" class="rounded-lg p-3 text-sm"
                           x-bind:class="result?.ok
                               ? 'bg-brand-50 text-brand-900 dark:bg-brand-500/10 dark:text-brand-200'
                               : 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200'"></p>
                    </div>
                </x-card>

                <x-card :title="__('Spending and language')"
                        :description="__('The AI company charges by how much it reads and writes. A note about one page costs a fraction of a rupee, but a limit keeps a surprise off your card.')">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-field name="monthly_cap" :label="__('Most to spend a month (Rs.)')" required
                                 :hint="__('0 switches the AI off — the Insights button then uses the shop\'s own checks.')">
                            <input type="number" name="monthly_cap" id="monthly_cap" required min="0" max="1000000"
                                   step="1" inputmode="numeric" value="{{ old('monthly_cap', $capRupees) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="language" :label="__('Write the notes in')" required>
                            <select name="language" id="language" class="{{ $inputClass }}">
                                <option value="en" @selected(old('language', $values['ai.language']) === 'en')>{{ __('English') }}</option>
                                <option value="ur" @selected(old('language', $values['ai.language']) === 'ur')>{{ __('English with an Urdu line') }}</option>
                            </select>
                        </x-field>

                        <x-field name="usd_rate" :label="__('Rupees to a dollar')" required
                                 :hint="__('Only used to show what a note cost.')">
                            <input type="number" name="usd_rate" id="usd_rate" required min="1" max="100000"
                                   step="1" inputmode="numeric" value="{{ old('usd_rate', $values['ai.usd_rate']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>
                    </div>
                </x-card>

                <x-card :title="__('What counts as a problem')"
                        :description="__('The shop\'s own checks use these numbers, and the AI is told them too. The defaults suit a general grocery; change them to suit yours.')">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-field name="insights.dead_stock_days" :label="__('Not sold for (days) is dead stock')" required>
                            <input type="number" name="insights[dead_stock_days]" id="insights.dead_stock_days" required
                                   min="7" max="365" step="1" inputmode="numeric"
                                   value="{{ old('insights.dead_stock_days', $values['insights.dead_stock_days']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.dead_stock_value" :label="__('Worth mentioning above (Rs.)')" required>
                            <input type="number" name="insights[dead_stock_value]" id="insights.dead_stock_value" required
                                   min="0" max="10000000" step="1" inputmode="numeric"
                                   value="{{ old('insights.dead_stock_value', $values['insights.dead_stock_value']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.overstock_days" :label="__('More than (days) of stock is too much')" required>
                            <input type="number" name="insights[overstock_days]" id="insights.overstock_days" required
                                   min="7" max="365" step="1" inputmode="numeric"
                                   value="{{ old('insights.overstock_days', $values['insights.overstock_days']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.payment_gap_days" :label="__('No khata payment for (days)')" required>
                            <input type="number" name="insights[payment_gap_days]" id="insights.payment_gap_days" required
                                   min="1" max="365" step="1" inputmode="numeric"
                                   value="{{ old('insights.payment_gap_days', $values['insights.payment_gap_days']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.debt_growth_ratio" :label="__('Khata given against collected')" required
                                 :hint="__('1.3 means a third more went out than came in.')">
                            <input type="number" name="insights[debt_growth_ratio]" id="insights.debt_growth_ratio" required
                                   min="1" max="10" step="0.1" inputmode="decimal"
                                   value="{{ old('insights.debt_growth_ratio', $values['insights.debt_growth_ratio']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.receivables_share" :label="__('Khata as a share of a month of sales (%)')" required>
                            <input type="number" name="insights[receivables_share]" id="insights.receivables_share" required
                                   min="1" max="100" step="0.5" inputmode="decimal"
                                   value="{{ old('insights.receivables_share', $values['insights.receivables_share']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.variance_alert" :label="__('A drawer out by more than (Rs.)')" required>
                            <input type="number" name="insights[variance_alert]" id="insights.variance_alert" required
                                   min="0" max="1000000" step="1" inputmode="numeric"
                                   value="{{ old('insights.variance_alert', $values['insights.variance_alert']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.void_rate_multiple" :label="__('Cancelled bills above the shop average by')" required
                                 :hint="__('2 means twice as often as everyone else.')">
                            <input type="number" name="insights[void_rate_multiple]" id="insights.void_rate_multiple" required
                                   min="1" max="20" step="0.1" inputmode="decimal"
                                   value="{{ old('insights.void_rate_multiple', $values['insights.void_rate_multiple']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>

                        <x-field name="insights.price_rise_percent" :label="__('A cost rise worth noticing (%)')" required>
                            <input type="number" name="insights[price_rise_percent]" id="insights.price_rise_percent" required
                                   min="1" max="100" step="0.5" inputmode="decimal"
                                   value="{{ old('insights.price_rise_percent', $values['insights.price_rise_percent']) }}"
                                   class="{{ $inputClass }}">
                        </x-field>
                    </div>
                </x-card>

                <div class="flex justify-end">
                    <button type="submit"
                            class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                        {{ __('Save AI settings') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-5">
            <x-card :title="__('This month')">
                @if ($cap === 0)
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The AI is switched off. Insights still work — they come from the shop\'s own checks.') }}
                    </p>
                @else
                    <p class="text-2xl font-semibold tabular-nums">{{ Money::withSymbol($spent) }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('of :cap · :left left', ['cap' => Money::rounded($cap), 'left' => Money::withSymbol($left)]) }}
                    </p>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                        <div class="h-full rounded-full {{ $share >= 90 ? 'bg-red-500' : 'bg-brand-500' }}"
                             style="width: {{ max(2, $share) }}%"></div>
                    </div>

                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                        {{ trans_choice('{0}No notes written yet this month.|{1}One note written this month.|[2,*]:count notes written this month.', $asked, ['count' => number_format($asked)]) }}
                    </p>
                @endif

                @if ($maskedKey)
                    <form method="POST" action="{{ route('settings.ai.forget') }}" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800"
                          onsubmit="return confirm('{{ __('Remove the saved key? Insights will fall back to the shop\'s own checks.') }}')">
                        @csrf
                        @method('DELETE')

                        <button type="submit" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">
                            {{ __('Forget the saved key') }}
                        </button>
                    </form>
                @endif
            </x-card>

            <x-card :title="__('Recent notes')" :description="__('The last ten times a page was explained.')">
                @forelse ($runs as $run)
                    <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ ucfirst(str_replace('-', ' ', $run->page)) }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $run->model ?: __('Shop\'s own checks') }} · {{ $run->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($run->status === \App\Models\AiInsightRun::FAILED)
                                <x-badge tone="danger">{{ __('Did not work') }}</x-badge>
                            @else
                                <span class="tabular-nums">{{ Money::withSymbol($run->cost_paisa) }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Nothing yet. Open any page and press Insights.') }}
                    </p>
                @endforelse
            </x-card>

            <x-card :title="__('What is sent')">
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <li class="flex gap-2">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                        <span>{{ __('Totals and rankings for the page you are on — sales, stock, khata balances and names.') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <x-icon name="close" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                        <span>{{ __('Never phone numbers, addresses, staff passwords or anything about your own login.') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                        <span>{{ __('The key is sent from this computer to the AI company only, never to a browser.') }}</span>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</x-app-layout>
