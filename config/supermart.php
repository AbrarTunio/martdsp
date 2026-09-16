<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings defaults
    |--------------------------------------------------------------------------
    |
    | Every configurable value the shop owner can change lives in the settings
    | table, keyed by the dotted keys below. This file supplies the default
    | used until they save something, and declares the cast so the value comes
    | back out of the database as the right PHP type.
    |
    | Business thresholds (dead-stock windows, GMROI floors and so on) are
    | deliberately settings rather than constants, because a neighbourhood
    | kiryana and a large mart behave differently. See docs/AI-INSIGHTS.md.
    |
    */

    'settings' => [
        // Printed on every receipt, invoice and khata statement.
        'shop.name' => ['default' => 'Super Mart', 'cast' => 'string', 'group' => 'shop'],
        'shop.address' => ['default' => '', 'cast' => 'string', 'group' => 'shop'],
        'shop.phone' => ['default' => '', 'cast' => 'string', 'group' => 'shop'],

        // FBR registration numbers. Required on a GST invoice in Pakistan.
        'shop.ntn' => ['default' => '', 'cast' => 'string', 'group' => 'shop'],
        'shop.strn' => ['default' => '', 'cast' => 'string', 'group' => 'shop'],

        // Standard Pakistani GST rate. Per-product overrides come in Phase 1.
        'tax.gst_rate' => ['default' => 18.0, 'cast' => 'float', 'group' => 'tax'],
        'tax.prices_include_tax' => ['default' => true, 'cast' => 'boolean', 'group' => 'tax'],

        'receipt.paper_width' => ['default' => '80', 'cast' => 'string', 'group' => 'receipt'],
        'receipt.footer_note' => ['default' => 'Thank you for shopping with us!', 'cast' => 'string', 'group' => 'receipt'],

        // Send the slip to the counter printer the moment a bill is paid, so
        // nobody has to reach for a button with a queue waiting.
        'receipt.auto_print' => ['default' => false, 'cast' => 'boolean', 'group' => 'receipt'],

        // Paisa coins do not circulate, so most shops settle to the rupee.
        'sales.round_to_rupee' => ['default' => true, 'cast' => 'boolean', 'group' => 'sales'],
        'sales.allow_negative_stock' => ['default' => false, 'cast' => 'boolean', 'group' => 'sales'],

        // The most a cashier may take off a bill, as a percentage of it.
        // Owners and managers are not capped.
        'sales.cashier_discount_limit' => ['default' => 10.0, 'cast' => 'float', 'group' => 'sales'],

        // How long a khata sale has before it counts as overdue.
        'khata.credit_days' => ['default' => 30, 'cast' => 'integer', 'group' => 'khata'],

        // How far, in rupees, a drawer count may be out before a manager has
        // to sign it off. Small gaps from change-making are normal.
        'drawer.variance_tolerance' => ['default' => 100, 'cast' => 'integer', 'group' => 'drawer'],

        // A cashier counts the drawer without being shown what it should
        // hold, so the count is honest rather than made to match.
        'drawer.blind_count' => ['default' => true, 'cast' => 'boolean', 'group' => 'drawer'],

        // Pop the cash drawer by itself on a cash sale. Shops that keep the
        // drawer unlocked, or take mostly card and khata, switch this off.
        'drawer.pulse_on_cash' => ['default' => true, 'cast' => 'boolean', 'group' => 'drawer'],

        // Which AI writes the "Explain this page" notes. The API key is not
        // listed here on purpose: it is stored encrypted on its own and never
        // enters the settings cache — see Setting::secret().
        'ai.provider' => ['default' => '', 'cast' => 'string', 'group' => 'ai'],
        'ai.model' => ['default' => '', 'cast' => 'string', 'group' => 'ai'],
        'ai.base_url' => ['default' => '', 'cast' => 'string', 'group' => 'ai'],

        // The most the shop will spend on AI in a calendar month, in rupees.
        // Past it, the panel falls back to the shop's own checks.
        'ai.monthly_cap' => ['default' => 2000, 'cast' => 'integer', 'group' => 'ai'],

        // "en" or "ur".
        'ai.language' => ['default' => 'en', 'cast' => 'string', 'group' => 'ai'],

        // Providers bill in dollars; this turns their price into rupees.
        'ai.usd_rate' => ['default' => 280, 'cast' => 'integer', 'group' => 'ai'],

        // What counts as a problem. General grocery norms to start from — a
        // kiryana and a large mart will want to tune them. Money in rupees.
        'insights.dead_stock_days' => ['default' => 90, 'cast' => 'integer', 'group' => 'insights'],
        'insights.dead_stock_value' => ['default' => 5000, 'cast' => 'integer', 'group' => 'insights'],
        'insights.overstock_days' => ['default' => 90, 'cast' => 'integer', 'group' => 'insights'],
        'insights.payment_gap_days' => ['default' => 45, 'cast' => 'integer', 'group' => 'insights'],
        'insights.debt_growth_ratio' => ['default' => 1.3, 'cast' => 'float', 'group' => 'insights'],
        'insights.receivables_share' => ['default' => 25.0, 'cast' => 'float', 'group' => 'insights'],
        'insights.variance_alert' => ['default' => 500, 'cast' => 'integer', 'group' => 'insights'],
        'insights.void_rate_multiple' => ['default' => 2.0, 'cast' => 'float', 'group' => 'insights'],
        'insights.price_rise_percent' => ['default' => 5.0, 'cast' => 'float', 'group' => 'insights'],

        // A copy of the whole database, written to a folder on this PC every
        // night. The folder may be anywhere the computer can write to — an
        // external drive letter is the point of it.
        'backup.enabled' => ['default' => true, 'cast' => 'boolean', 'group' => 'backup'],
        'backup.folder' => ['default' => '', 'cast' => 'string', 'group' => 'backup'],
        'backup.keep_days' => ['default' => 14, 'cast' => 'integer', 'group' => 'backup'],
        'backup.hour' => ['default' => 23, 'cast' => 'integer', 'group' => 'backup'],

        // When the ready-made product list was put in, or blank if it never
        // has been. Only App\Services\StarterCatalogueService writes it.
        'catalogue.seeded_at' => ['default' => '', 'cast' => 'string', 'group' => 'catalogue'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | Calls are made from the server only and give up after `timeout` seconds,
    | trying once more on a dropped connection or a busy provider. A note is
    | reused for `cache_hours` unless someone presses Refresh.
    |
    | `prices` are list prices in US dollars per million tokens, used only to
    | estimate spend against the monthly cap. The longest matching model-name
    | prefix wins; `fallback` covers a model not listed, per provider.
    |
    */

    'ai' => [
        'timeout' => 20,
        'cache_hours' => 6,
        'max_output_tokens' => 1500,

        'prices' => [
            'claude-fable' => [10, 50],
            'claude-mythos' => [10, 50],
            'claude-opus-4-0' => [15, 75],
            'claude-opus-4-1' => [15, 75],
            'claude-opus-4-2' => [15, 75],
            'claude-opus' => [5, 25],
            'claude-sonnet' => [3, 15],
            'claude-haiku-4' => [1, 5],
            'claude-3-5-haiku' => [0.8, 4],
            'claude-3-haiku' => [0.25, 1.25],
            'gpt-5-nano' => [0.05, 0.4],
            'gpt-5-mini' => [0.25, 2],
            'gpt-5' => [1.25, 10],
            'gpt-4.1-nano' => [0.1, 0.4],
            'gpt-4.1-mini' => [0.4, 1.6],
            'gpt-4.1' => [2, 8],
            'gpt-4o-mini' => [0.15, 0.6],
            'gpt-4o' => [2.5, 10],
            'o4-mini' => [1.1, 4.4],
            'gemini-2.5-flash-lite' => [0.1, 0.4],
            'gemini-2.5-flash' => [0.3, 2.5],
            'gemini-2.5-pro' => [1.25, 10],
            'gemini-2.0-flash' => [0.1, 0.4],
            'llama-3.1-8b' => [0.05, 0.08],
            'llama-3.3-70b' => [0.59, 0.79],
            'glm-4.5-flash' => [0, 0],
            'glm-4.5-air' => [0.2, 1.1],
            'glm' => [0.6, 2.2],
        ],

        'fallback' => [
            'anthropic' => [5, 25],
            'openai' => [1.25, 10],
            'gemini' => [0.3, 2.5],
            'groq' => [0.59, 0.79],
            'zai' => [0.6, 2.2],
            'ollama' => [0, 0],
            'custom' => [1, 3],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Where the nightly copy of the database is written when the owner has not
    | named a folder, how long a dump may take, and where to look for the
    | mysqldump program on a Windows PC that does not have it on the PATH.
    |
    */

    'backup' => [
        'folder' => storage_path('app/backups'),
        'prefix' => 'supermart-',
        'timeout' => 600,

        'mysqldump' => [
            'mysqldump',
            'C:/laragon/bin/mysql/*/bin/mysqldump.exe',
            'C:/xampp/mysql/bin/mysqldump.exe',
            'C:/Program Files/MySQL/*/bin/mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash
    |--------------------------------------------------------------------------
    |
    | The Pakistani notes and coins a drawer is counted in, in rupees, largest
    | first. The Rs. 10 note and coin are counted together.
    |
    */

    'cash' => [
        'denominations' => [5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Receipt paper widths
    |--------------------------------------------------------------------------
    */

    'paper_widths' => [
        '58' => '58 mm thermal (small)',
        '80' => '80 mm thermal (standard)',
    ],

];
