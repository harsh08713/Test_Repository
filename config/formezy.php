<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display formats (Indian context)
    |--------------------------------------------------------------------------
    */
    'display' => [
        'date' => 'd-m-Y',
        'datetime' => 'd-m-Y H:i',
        'currency' => 'INR',
    ],

    /*
    |--------------------------------------------------------------------------
    | GST rates an item may carry (percent)
    |--------------------------------------------------------------------------
    | Edit to match the slabs currently in force; items are validated against this list, so a typo such as 81 is
    | refused rather than stored. Rates already saved on approved items are never rewritten.
    */
    'gst_rates' => [0, 0.1, 0.25, 1, 1.5, 3, 5, 6, 7.5, 12, 18, 28, 40],

    /*
    |--------------------------------------------------------------------------
    | Spreadsheet imports
    |--------------------------------------------------------------------------
    | Limits for the controlled import framework (Tent Studio geography now; leads and route plans later).
    | Files are kept on the private disk. CSV is much faster than xlsx for very large files.
    */
    'imports' => [
        'disk' => 'local',
        'max_file_kb' => 10240,        // 10 MB upload
        'max_unpacked_mb' => 150,      // zip-bomb guard for xlsx
        'max_rows' => 200000,
        'chunk_rows' => 2000,
        'preview_rows' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile / integrations API (/api/v1)
    |--------------------------------------------------------------------------
    | Device tokens are Sanctum tokens: revocable, expiring, listed per user. Limits are per minute.
    */
    'api' => [
        'token_ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 20160),   // 14 days; a device renews with /auth/refresh
        'max_devices' => (int) env('API_MAX_DEVICES', 5),                   // signing in beyond this signs the least recently used device out
        'per_page' => 20,
        'max_per_page' => 100,
        'idempotency_ttl_hours' => 48,
        'idempotency_stale_seconds' => 60,                                  // a started request older than this is presumed dead and may be retried
        'limits' => [
            'login_per_minute' => 5,        // per e-mail address and IP address
            'login_ip_per_minute' => 20,    // per IP address, whatever the e-mail
            'per_minute' => 120,            // per token
            'writes_per_minute' => 60,      // per token, for anything that changes data
            'ip_per_minute' => 600,         // per IP address: a whole depot can share one
            'health_per_minute' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-company settings
    |--------------------------------------------------------------------------
    | Edited on the Company Settings screen and read through SettingsService.
    | type: bool | int | string | select | secret (stored encrypted, never shown again)
    */
    // Backups of the database (php artisan formezy:backup / formezy:restore-check). Off by default; the programs are found by name on the PATH or by full path.
    'backup' => [
        'enabled' => (bool) env('BACKUP_ENABLED', false),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'dump_binary' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'mysql_binary' => env('BACKUP_MYSQL', 'mysql'),
    ],

    'settings' => [
        'approvals.prevent_self_approval' => [
            'group' => 'Approvals',
            'label' => 'Block approving documents you created or submitted',
            'help' => 'Maker–checker control: the person who submitted a document cannot approve it.',
            'type' => 'bool',
            'default' => true,
        ],
        'approvals.item_approver' => [
            'group' => 'Approvals',
            'label' => 'Item Master final approver',
            'help' => 'Department the item is forwarded to after Purchase and Inventory. Operations users remain comment-only.',
            'type' => 'select',
            'options' => ['accounts' => 'Accounts', 'operations' => 'Operations'],
            'default' => 'accounts',
        ],
        'geofence.radius_m' => [
            'group' => 'Field sales',
            'label' => 'Default geofence radius (metres)',
            'help' => 'Leads saved outside this distance from the planned route stop are rejected.',
            'type' => 'int',
            'default' => 500,
            'min' => 50,
            'max' => 5000,
        ],
        'geofence.max_accuracy_m' => [
            'group' => 'Field sales',
            'label' => 'Worst GPS accuracy accepted (metres)',
            'help' => 'A position that could be off by more than this is refused: it says nothing about a geofence. Attendance and mobile lead entry are both checked against it.',
            'type' => 'int',
            'default' => 150,
            'min' => 10,
            'max' => 2000,
        ],
        'geofence.manager_override' => [
            'group' => 'Field sales',
            'label' => 'Allow a manager to override a geofence violation',
            'help' => 'Off by default. When on, a manager who holds the geofence-override permission can accept a refused attendance for a person on their team, with a reason; the original attempt stays in the violation log.',
            'type' => 'bool',
            'default' => false,
        ],
        'followups.remind_minutes_before' => [
            'group' => 'CRM',
            'label' => 'Remind about a follow-up this many minutes before it is due',
            'help' => 'The person it is assigned to gets an in-app notice and an e-mail, once. 0 reminds at the time itself. Needs the scheduler to be running.',
            'type' => 'int',
            'default' => 30,
            'min' => 0,
            'max' => 1440,
        ],
        'followups.escalate_after_hours' => [
            'group' => 'CRM',
            'label' => 'Tell the manager when a follow-up is this many hours overdue',
            'help' => 'The manager of the person’s sales team gets an in-app notice and an e-mail, once per follow-up. 0 turns it off. Needs the scheduler to be running.',
            'type' => 'int',
            'default' => 24,
            'min' => 0,
            'max' => 720,
        ],
        'marketing.email_requires_optin' => [
            'group' => 'Marketing',
            'label' => 'Marketing e-mail needs the person’s opt-in',
            'help' => 'On (recommended): a marketing e-mail goes only to an address that has opted in. Off: it also goes to addresses with no answer on record — but never to one that has opted out. WhatsApp and SMS marketing always need an opt-in. Messages about something the person asked for (order confirmations, reminders) need no opt-in.',
            'type' => 'bool',
            'default' => true,
        ],
        'marketing.max_per_contact_per_week' => [
            'group' => 'Marketing',
            'label' => 'Most marketing messages one address gets in 7 days',
            'help' => 'Across all campaigns and workflows, per channel. A message over the limit is not sent and is recorded as held back. 0 turns the limit off.',
            'type' => 'int',
            'default' => 3,
            'min' => 0,
            'max' => 50,
        ],
        'marketing.sender_name' => [
            'group' => 'Marketing',
            'label' => 'Name marketing e-mail comes from',
            'help' => 'Shown as the sender’s name. The address is the installation’s mail address (MAIL_FROM_ADDRESS). Leave empty to use the company name.',
            'type' => 'string',
            'default' => '',
        ],
        'sla.business_days' => [
            'group' => 'SLA and tasks',
            'label' => 'Working days (for SLA policies counted in business hours)',
            'help' => 'Only policies with “count business hours only” use these. Holidays are listed under SLA policies.',
            'type' => 'select',
            'options' => ['mon_fri' => 'Monday to Friday', 'mon_sat' => 'Monday to Saturday', 'all' => 'Every day'],
            'default' => 'mon_sat',
        ],
        'sla.business_start' => [
            'group' => 'SLA and tasks',
            'label' => 'Working day starts at',
            'help' => 'Company time. Minutes outside the working day are not counted.',
            'type' => 'select',
            'options' => ['08:00' => '8:00 am', '08:30' => '8:30 am', '09:00' => '9:00 am', '09:30' => '9:30 am', '10:00' => '10:00 am', '10:30' => '10:30 am'],
            'default' => '09:30',
        ],
        'sla.business_end' => [
            'group' => 'SLA and tasks',
            'label' => 'Working day ends at',
            'help' => 'Company time. A policy that runs past this carries over to the next working day.',
            'type' => 'select',
            'options' => ['17:00' => '5:00 pm', '17:30' => '5:30 pm', '18:00' => '6:00 pm', '18:30' => '6:30 pm', '19:00' => '7:00 pm', '20:00' => '8:00 pm'],
            'default' => '18:30',
        ],
        'quotation.validity_days' => [
            'group' => 'Sales',
            'label' => 'A quotation is valid for this many days',
            'help' => 'The valid-until date a new quotation starts with. The person making it can change it.',
            'type' => 'int',
            'default' => 30,
            'min' => 1,
            'max' => 365,
        ],
        'quotation.followup_days' => [
            'group' => 'Sales',
            'label' => 'Plan a follow-up this many days after a quotation is sent',
            'help' => 'A call is planned on the lead for the person who owns it. 0 plans nothing.',
            'type' => 'int',
            'default' => 3,
            'min' => 0,
            'max' => 60,
        ],
        'quotation.expiry_warning_days' => [
            'group' => 'Sales',
            'label' => 'Warn the owner this many days before a sent quotation expires',
            'help' => 'One in-app notice and e-mail per quotation. 0 turns the warning off. Needs the scheduler to be running.',
            'type' => 'int',
            'default' => 2,
            'min' => 0,
            'max' => 30,
        ],
        'quotation.default_terms' => [
            'group' => 'Sales',
            'label' => 'Standard terms printed on a quotation',
            'help' => 'What a new quotation starts with under “Terms and conditions”. The person making it can change it.',
            'type' => 'string',
            'default' => 'Prices are ex-works and exclude freight. Payment: 50% advance, balance before dispatch. Delivery: 2–3 weeks from the order.',
        ],
        'sales_order.quotation_pricing' => [
            'group' => 'Sales',
            'label' => 'When a sales order is made from a quotation, price it',
            'help' => 'Copy: each line keeps the price it was quoted at (the customer was promised it). Rate master: every line is looked up again at today’s rate.',
            'type' => 'select',
            'options' => ['copy' => 'As quoted', 'rates' => 'From the rate master again'],
            'default' => 'copy',
        ],
        'sales_order.delivery_days' => [
            'group' => 'Sales',
            'label' => 'A new sales order is for delivery in this many days',
            'help' => 'The delivery date a new order starts with. The person making it can change it.',
            'type' => 'int',
            'default' => 14,
            'min' => 0,
            'max' => 365,
        ],
        'sales_order.direct_min_orders' => [
            'group' => 'Sales',
            'label' => 'A customer can be sold to directly (no deal or quotation) after this many approved orders',
            'help' => 'A “regular customer” is one with at least this many approved orders, not cancelled. 0 lets any customer be ordered from directly; a higher number keeps direct orders for customers with a history.',
            'type' => 'int',
            'default' => 1,
            'min' => 0,
            'max' => 50,
        ],
        'costing.method' => [
            'group' => 'Sales',
            'label' => 'Cost of what is sold (the cost taken on an invoice line)',
            'help' => 'Moving average uses the average cost of the stock the branch holds (and the item’s standard cost where there is no stock to average). Standard cost always uses the cost set on the item. An item with no cost at all leaves the line’s cost blank, never zero.',
            'type' => 'select',
            'options' => ['moving_average' => 'Moving average', 'standard' => 'Standard cost'],
            'default' => 'moving_average',
        ],
        'hr.weekly_off' => [
            'group' => 'HR',
            'label' => 'Weekly off',
            'help' => 'The days the company is closed every week. Leave does not count them, and attendance marks them as weekly off.',
            'type' => 'select',
            'options' => ['sunday' => 'Sunday', 'saturday_sunday' => 'Saturday and Sunday', 'alternate_saturday' => 'Sunday and the 2nd and 4th Saturday', 'none' => 'No weekly off'],
            'default' => 'sunday',
        ],
        'system.demo_data' => [
            'group' => 'Sales',
            'label' => 'This company holds demo data',
            'help' => 'Shows a “Demo data” badge on the executive dashboard so nobody mistakes sample figures for real ones. Turn it off before real data goes in.',
            'type' => 'bool',
            'default' => false,
        ],
        'radar.enabled' => [
            'group' => 'Loss Intelligence',
            'label' => 'Profit Leakage Radar looks for losses in stock, receipts and scrap',
            'help' => 'The daily sweep proposes findings for people to review; it never creates a loss incident or changes a figure. Needs the scheduler to be running.',
            'type' => 'bool',
            'default' => true,
        ],
        'radar.min_amount' => [
            'group' => 'Loss Intelligence',
            'label' => 'Radar ignores anything estimated below this amount (₹)',
            'help' => 'Findings are graded low, medium or high at 5 and 20 times this amount.',
            'type' => 'int',
            'default' => 1000,
            'min' => 1,
            'max' => 100000000,
        ],
        'radar.price_variance_percent' => [
            'group' => 'Loss Intelligence',
            'label' => 'Radar flags goods bought more than this percent above the standard cost',
            'help' => 'Compared with the item’s current standard cost, per base unit. Items with no standard cost are left out.',
            'type' => 'int',
            'default' => 5,
            'min' => 0,
            'max' => 100,
        ],
        'radar.lookback_days' => [
            'group' => 'Loss Intelligence',
            'label' => 'Radar looks at scrap and rejected receipts from the last this many days',
            'help' => 'Expired stock that is still on hand is always included.',
            'type' => 'int',
            'default' => 30,
            'min' => 1,
            'max' => 365,
        ],
        'inventory.expiry_warning_days' => [
            'group' => 'Inventory',
            'label' => 'Warn the stock team this many days before a batch expires',
            'help' => 'One in-app notice and e-mail per batch, to everyone who may see stock. 0 turns the warning off. Needs the scheduler to be running.',
            'type' => 'int',
            'default' => 30,
            'min' => 0,
            'max' => 365,
        ],
        'credit_limit.behaviour' => [
            'group' => 'Sales',
            'label' => 'When a Sales Order exceeds the customer credit limit',
            'type' => 'select',
            'options' => ['warn' => 'Warn and allow', 'block' => 'Block', 'ignore' => 'Ignore'],
            'default' => 'warn',
        ],
        'channels.whatsapp_mode' => [
            'group' => 'Channels', 'label' => 'WhatsApp', 'type' => 'select', 'options' => ['off' => 'Off', 'sandbox' => 'Sandbox (nothing is sent)', 'live' => 'Live'], 'default' => 'off',
            'help' => 'Sandbox rehearses a campaign end to end without sending anything. Live needs the credentials below (Meta WhatsApp Business Cloud API) and a verified account.',
        ],
        'channels.whatsapp_phone_number_id' => ['group' => 'Channels', 'label' => 'WhatsApp phone number ID', 'type' => 'string', 'default' => ''],
        'channels.whatsapp_access_token' => ['group' => 'Channels', 'label' => 'WhatsApp access token', 'type' => 'secret', 'default' => null],
        'channels.whatsapp_app_secret' => ['group' => 'Channels', 'label' => 'WhatsApp app secret (checks the delivery reports)', 'type' => 'secret', 'default' => null],
        'channels.whatsapp_verify_token' => ['group' => 'Channels', 'label' => 'WhatsApp webhook verify token (you choose it; type the same in Meta)', 'type' => 'secret', 'default' => null],
        'channels.sms_mode' => [
            'group' => 'Channels', 'label' => 'SMS', 'type' => 'select', 'options' => ['off' => 'Off', 'sandbox' => 'Sandbox (nothing is sent)', 'live' => 'Live'], 'default' => 'off',
            'help' => 'Live uses Twilio. In India commercial SMS also needs DLT registration with the operator: arrange that first.',
        ],
        'channels.sms_account_sid' => ['group' => 'Channels', 'label' => 'SMS account SID', 'type' => 'string', 'default' => ''],
        'channels.sms_auth_token' => ['group' => 'Channels', 'label' => 'SMS auth token', 'type' => 'secret', 'default' => null],
        'channels.sms_from' => ['group' => 'Channels', 'label' => 'SMS sender (number or sender ID)', 'type' => 'string', 'default' => ''],
        'channels.country_code' => ['group' => 'Channels', 'label' => 'Country code put before ten-digit mobile numbers', 'type' => 'string', 'default' => '91'],
        'channels.webhook_token' => ['group' => 'Channels', 'label' => 'Webhook token (made for you on Administration → Channels)', 'type' => 'secret', 'default' => null],
        'production.over_issue_percent' => [
            'group' => 'Production', 'label' => 'Material that may be issued beyond what an order requires (percent)', 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 100,
            'help' => 'A component can be issued up to its required quantity plus this much, for spillage and trial runs. Beyond it the issue is refused.',
        ],
        'production.over_production_percent' => [
            'group' => 'Production', 'label' => 'Output that may be reported beyond the planned quantity (percent)', 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 100,
        ],
        'integrations.indiamart_api_key' => [
            'group' => 'Integrations',
            'label' => 'IndiaMART CRM API key',
            'type' => 'secret',
            'default' => null,
        ],
        'integrations.justdial_api_key' => [
            'group' => 'Integrations',
            'label' => 'Just Dial API key',
            'help' => 'Only if Just Dial gave your account a pull API; its leads normally arrive by push (Administration → Integrations).',
            'type' => 'secret',
            'default' => null,
        ],
        'integrations.indiamart_webhook_secret' => [
            'group' => 'Integrations',
            'label' => 'IndiaMART webhook signing secret',
            'help' => 'Optional. When set, a push must carry a valid X-FormEzy-Signature (see docs/integrations.md); IndiaMART itself cannot sign, so use it with a relay that does.',
            'type' => 'secret',
            'default' => null,
        ],
        'integrations.justdial_webhook_secret' => [
            'group' => 'Integrations',
            'label' => 'Just Dial webhook signing secret',
            'help' => 'Optional. When set, a push must carry a valid X-FormEzy-Signature (see docs/integrations.md); Just Dial itself cannot sign, so use it with a relay that does.',
            'type' => 'secret',
            'default' => null,
        ],
        'integrations.log_retention_days' => [
            'group' => 'Integrations',
            'label' => 'Keep the inbound-lead log for this many days',
            'help' => 'The log holds each marketplace lead as it arrived, including the buyer’s phone number and e-mail. Rows that failed are kept twice as long. The leads themselves are not touched.',
            'type' => 'int',
            'default' => 90,
            'min' => 7,
            'max' => 3650,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar navigation
    |--------------------------------------------------------------------------
    | Items whose route does not exist yet are skipped, so later phases only need to add routes.
    | `can` is a Gate ability / permission name (null = any signed-in user).
    */
    'navigation' => [
        ['section' => 'Overview', 'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'can' => null],
            ['label' => 'Approvals', 'route' => 'approvals.index', 'icon' => 'check', 'can' => null],
        ]],
        ['section' => 'Analytics', 'items' => [
            ['label' => 'Executive dashboard', 'route' => 'analytics.dashboard', 'icon' => 'chart-bar', 'can' => 'analytics.view'],
            ['label' => 'Revenue vs loss', 'route' => 'analytics.page', 'params' => ['page' => 'revenue-loss'], 'icon' => 'arrow-trending-up', 'can' => 'analytics.view'],
            ['label' => 'Revenue by industry', 'route' => 'analytics.page', 'params' => ['page' => 'industry-revenue'], 'icon' => 'building', 'can' => ['analytics.view', 'profitability.view']],
            ['label' => 'Losses by industry', 'route' => 'analytics.page', 'params' => ['page' => 'industry-losses'], 'icon' => 'exclamation-triangle', 'can' => ['analytics.view', 'loss_incident.view']],
            ['label' => 'Top products', 'route' => 'analytics.page', 'params' => ['page' => 'top-products'], 'icon' => 'cube', 'can' => ['analytics.view', 'profitability.view']],
            ['label' => 'Loss categories', 'route' => 'analytics.page', 'params' => ['page' => 'loss-categories'], 'icon' => 'layers', 'can' => ['analytics.view', 'loss_incident.view']],
            ['label' => 'Brand profitability', 'route' => 'analytics.page', 'params' => ['page' => 'brand-profitability'], 'icon' => 'tag', 'can' => ['analytics.view', 'profitability.view']],
        ]],
        ['section' => 'Masters', 'items' => [
            ['label' => 'Companies', 'route' => 'masters.companies.index', 'icon' => 'building', 'can' => 'company.view'],
            ['label' => 'Branches', 'route' => 'masters.locations.index', 'icon' => 'map', 'can' => 'location.view'],
            ['label' => 'Chart of accounts', 'route' => 'masters.account-groups', 'icon' => 'folder', 'can' => 'account_group.view'],
            ['label' => 'Ledgers', 'route' => 'masters.ledgers.index', 'icon' => 'book', 'can' => 'ledger.view', 'also' => 'masters.ledgers.*'],
            ['label' => 'Items', 'route' => 'masters.items.index', 'icon' => 'cube', 'can' => 'item.view', 'also' => 'masters.items.*'],
            ['label' => 'Rates', 'route' => 'masters.rates.index', 'icon' => 'tag', 'can' => 'rate.view', 'also' => 'masters.rates.*'],
            ['label' => 'Price lists', 'route' => 'masters.price-lists', 'icon' => 'tag', 'can' => 'price_list.view'],
            ['label' => 'Brands', 'route' => 'masters.brands', 'icon' => 'tag', 'can' => 'brand.view'],
            ['label' => 'Item groups', 'route' => 'masters.item-groups', 'icon' => 'layers', 'can' => 'item_group.view'],
            ['label' => 'Departments', 'route' => 'masters.departments', 'icon' => 'briefcase', 'can' => 'department.view'],
            ['label' => 'Designations', 'route' => 'masters.designations', 'icon' => 'users', 'can' => 'designation.view'],
            ['label' => 'Units of measure', 'route' => 'masters.units', 'icon' => 'scale', 'can' => 'unit.view'],
            ['label' => 'Payment methods', 'route' => 'masters.payment-methods', 'icon' => 'card', 'can' => 'payment_method.view'],
            ['label' => 'Geography', 'route' => 'masters.geography.states', 'icon' => 'map', 'can' => 'geography.view', 'also' => 'masters.geography.*'],
        ]],
        ['section' => 'Inventory', 'items' => [
            ['label' => 'Stock on hand', 'route' => 'inventory.stock', 'icon' => 'cube', 'can' => 'stock.view'],
            ['label' => 'Stock ledger', 'route' => 'inventory.movements', 'icon' => 'book', 'can' => 'stock.view'],
            ['label' => 'Batches & expiry', 'route' => 'inventory.batches', 'icon' => 'clock', 'can' => 'stock.view'],
            ['label' => 'Stock entry', 'route' => 'inventory.entry', 'icon' => 'scale', 'can' => 'stock.create'],
        ]],
        ['section' => 'Procurement', 'items' => [
            ['label' => 'Purchase orders', 'route' => 'procurement.orders.index', 'icon' => 'folder', 'can' => 'purchase_order.view', 'also' => 'procurement.orders.*'],
            ['label' => 'Goods receipts', 'route' => 'procurement.grns.index', 'icon' => 'check', 'can' => 'grn.view', 'also' => 'procurement.grns.*'],
            ['label' => 'Reorder suggestions', 'route' => 'procurement.reorder', 'icon' => 'funnel', 'can' => 'purchase_order.view'],
        ]],
        ['section' => 'Finance', 'items' => [
            ['label' => 'Cost centres', 'route' => 'finance.cost-centers', 'icon' => 'folder', 'can' => 'cost_center.view'],
            ['label' => 'Budgets', 'route' => 'finance.budgets.index', 'icon' => 'book', 'can' => 'budget.view', 'also' => 'finance.budgets.*'],
            ['label' => 'Budget against actual', 'route' => 'finance.variance', 'icon' => 'scale', 'can' => 'budget.view'],
            ['label' => 'Profitability', 'route' => 'finance.profitability', 'icon' => 'card', 'can' => 'profitability.view'],
        ]],
        ['section' => 'Loss Intelligence', 'items' => [
            ['label' => 'Loss incidents', 'route' => 'loss.incidents.index', 'icon' => 'shield', 'can' => 'loss_incident.view', 'also' => 'loss.incidents.*'],
            ['label' => 'Leakage radar', 'route' => 'loss.radar', 'icon' => 'shield', 'can' => 'radar_finding.view'],
            ['label' => 'Verified savings', 'route' => 'loss.savings', 'icon' => 'card', 'can' => 'savings_measurement.view', 'also' => 'loss.savings.*'],
            ['label' => 'Corrective actions', 'route' => 'loss.actions.index', 'icon' => 'check', 'can' => 'corrective_action.view'],
            ['label' => 'Industries', 'route' => 'industries.index', 'icon' => 'building', 'can' => 'industry_template.view', 'also' => 'industries.*'],
        ]],
        ['section' => 'CRM', 'items' => [
            ['label' => 'Leads', 'route' => 'crm.leads.index', 'icon' => 'funnel', 'can' => 'lead.view', 'also' => 'crm.leads.*'],
            ['label' => 'Deals', 'route' => 'crm.deals.index', 'icon' => 'briefcase', 'can' => 'deal.view', 'also' => 'crm.deals.*'],
            ['label' => 'Follow-ups', 'route' => 'crm.followups.index', 'icon' => 'clock', 'can' => 'followup.view'],
            ['label' => 'Calendar', 'route' => 'crm.followups.calendar', 'icon' => 'calendar', 'can' => 'followup.view'],
            ['label' => 'Lead masters', 'route' => 'crm.masters.sources', 'icon' => 'layers', 'can' => 'crm_master.view', 'also' => 'crm.masters.*'],
        ]],
        ['section' => 'Sales', 'items' => [
            ['label' => 'Quotations', 'route' => 'sales.quotations.index', 'icon' => 'book', 'can' => 'quotation.view', 'also' => 'sales.quotations.*'],
            ['label' => 'Sales orders', 'route' => 'sales.orders.index', 'icon' => 'folder', 'can' => 'sales_order.view', 'also' => 'sales.orders.*'],
            ['label' => 'Sales invoices', 'route' => 'sales.invoices.index', 'icon' => 'card', 'can' => 'sales_invoice.view', 'also' => 'sales.invoices.*'],
            ['label' => 'Returns & credit notes', 'route' => 'sales.returns.index', 'icon' => 'scale', 'can' => 'sales_return.view', 'also' => 'sales.returns.*'],
        ]],
        ['section' => 'My work', 'items' => [
            ['label' => 'Tasks', 'route' => 'workspace.tasks.index', 'icon' => 'check', 'can' => 'task.view', 'also' => 'workspace.tasks.*'],
            ['label' => 'SLA breaches', 'route' => 'workspace.sla.breaches', 'icon' => 'clock', 'can' => 'task.view'],
        ]],
        ['section' => 'Marketing', 'items' => [
            ['label' => 'Campaigns', 'route' => 'marketing.campaigns.index', 'icon' => 'funnel', 'can' => 'campaign.view', 'also' => 'marketing.campaigns.*'],
            ['label' => 'Message templates', 'route' => 'marketing.templates.index', 'icon' => 'book', 'can' => 'message_template.view', 'also' => 'marketing.templates.*'],
            ['label' => 'Workflows', 'route' => 'marketing.workflows.index', 'icon' => 'cog', 'can' => 'workflow.view', 'also' => 'marketing.workflows.*'],
            ['label' => 'Consent', 'route' => 'marketing.consent', 'icon' => 'shield', 'can' => 'consent.view'],
            ['label' => 'Channels', 'route' => 'marketing.channels', 'icon' => 'cog', 'can' => 'setting.view'],
            ['label' => 'Message log', 'route' => 'marketing.messages', 'icon' => 'clock', 'can' => 'communication.view'],
        ]],
        ['section' => 'Reports', 'items' => [
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'scale', 'can' => 'report.view', 'also' => 'reports.*'],
        ]],
        ['section' => 'Field sales', 'items' => [
            ['label' => 'My day', 'route' => 'field.today', 'icon' => 'map', 'can' => 'attendance.create'],
            ['label' => 'Route plans', 'route' => 'field.plans.index', 'icon' => 'map', 'can' => 'route_plan.view', 'also' => 'field.plans.*'],
            ['label' => 'Attendance', 'route' => 'field.attendance.index', 'icon' => 'check', 'can' => 'attendance.view'],
            ['label' => 'Geofence log', 'route' => 'field.violations.index', 'icon' => 'shield', 'can' => 'attendance.view'],
            ['label' => 'Sales teams', 'route' => 'field.teams.index', 'icon' => 'users', 'can' => 'sales_team.view', 'also' => 'field.teams.*'],
            ['label' => 'Targets', 'route' => 'field.targets.index', 'icon' => 'scale', 'can' => 'target.view', 'also' => 'field.targets.*'],
        ]],
        ['section' => 'HR & workforce', 'items' => [
            ['label' => 'HR dashboard', 'route' => 'hr.dashboard', 'icon' => 'chart-bar', 'can' => 'hr_dashboard.view'],
            ['label' => 'Employees', 'route' => 'hr.employees.index', 'icon' => 'users', 'can' => 'employee.view', 'also' => 'hr.employees.*'],
            ['label' => 'Org chart', 'route' => 'hr.org-chart', 'icon' => 'layers', 'can' => 'employee.view'],
            ['label' => 'My profile', 'route' => 'hr.me', 'icon' => 'home', 'can' => null],
            ['label' => 'My leave', 'route' => 'hr.my-leave', 'icon' => 'calendar', 'can' => null],
            ['label' => 'Leave requests', 'route' => 'hr.team-leave', 'icon' => 'check', 'can' => 'leave.view'],
            ['label' => 'Leave balances', 'route' => 'hr.leave-balances', 'icon' => 'scale', 'can' => 'leave.view'],
            ['label' => 'My attendance', 'route' => 'hr.my-attendance', 'icon' => 'clock', 'can' => null],
            ['label' => 'Team attendance', 'route' => 'hr.team-attendance', 'icon' => 'users', 'can' => 'staff_attendance.view'],
            ['label' => 'Attendance corrections', 'route' => 'hr.corrections', 'icon' => 'check', 'can' => 'staff_attendance.view'],
            ['label' => 'Leave types', 'route' => 'hr.leave-types', 'icon' => 'layers', 'can' => 'leave_type.view'],
            ['label' => 'Shifts', 'route' => 'hr.shifts', 'icon' => 'clock', 'can' => 'shift.view'],
            ['label' => 'Holidays', 'route' => 'hr.holidays', 'icon' => 'calendar', 'can' => 'holiday.view'],
            ['label' => 'Onboarding and exit', 'route' => 'hr.checklists', 'icon' => 'check', 'can' => null],
            ['label' => 'Onboarding and exit lists', 'route' => 'hr.checklist-items', 'icon' => 'layers', 'can' => 'checklist.view'],
            ['label' => 'Job openings', 'route' => 'hr.openings', 'icon' => 'briefcase', 'can' => 'recruitment.view', 'also' => 'hr.openings.*'],
            ['label' => 'Candidates', 'route' => 'hr.candidates', 'icon' => 'users', 'can' => 'recruitment.view', 'also' => 'hr.applications.*'],
            ['label' => 'My interviews', 'route' => 'hr.my-interviews', 'icon' => 'calendar', 'can' => null],
            ['label' => 'Review cycles', 'route' => 'hr.cycles', 'icon' => 'layers', 'can' => 'review_cycle.view'],
            ['label' => 'My performance', 'route' => 'hr.my-performance', 'icon' => 'chart-bar', 'can' => null],
            ['label' => 'Team performance', 'route' => 'hr.team-performance', 'icon' => 'scale', 'can' => 'performance.view'],
            ['label' => 'Training programs', 'route' => 'hr.programs', 'icon' => 'cube', 'can' => 'training.view', 'also' => 'hr.programs.*'],
            ['label' => 'My training', 'route' => 'hr.my-training', 'icon' => 'check', 'can' => null],
        ]],
        ['section' => 'Production', 'items' => [
            ['label' => 'Production orders', 'route' => 'production.orders.index', 'icon' => 'cog', 'can' => 'production_order.view', 'also' => 'production.orders.*'],
            ['label' => 'Bills of materials', 'route' => 'production.boms.index', 'icon' => 'layers', 'can' => 'bom.view', 'also' => 'production.boms.*'],
        ]],
        ['section' => 'Quality', 'items' => [
            ['label' => 'Inspections', 'route' => 'quality.inspections.index', 'icon' => 'shield', 'can' => 'inspection.view', 'also' => 'quality.inspections.*'],
            ['label' => 'Non-conformances', 'route' => 'quality.ncrs.index', 'icon' => 'scale', 'can' => 'non_conformance.view', 'also' => 'quality.ncrs.*'],
            ['label' => 'Inspection plans', 'route' => 'quality.plans.index', 'icon' => 'layers', 'can' => 'inspection_plan.view', 'also' => 'quality.plans.*'],
        ]],
        ['section' => 'Maintenance', 'items' => [
            ['label' => 'Work orders', 'route' => 'maintenance.orders.index', 'icon' => 'clock', 'can' => 'maintenance_order.view', 'also' => 'maintenance.orders.*'],
            ['label' => 'Assets', 'route' => 'maintenance.assets.index', 'icon' => 'cog', 'can' => 'asset.view', 'also' => 'maintenance.assets.*'],
            ['label' => 'Preventive schedules', 'route' => 'maintenance.schedules.index', 'icon' => 'calendar', 'can' => 'maintenance_plan.view', 'also' => 'maintenance.schedules.*'],
        ]],
        ['section' => 'Coming soon', 'items' => [
            ['label' => 'Projects', 'route' => null, 'icon' => 'briefcase', 'can' => 'analytics.view', 'soon' => true],
        ]],
        ['section' => 'Administration', 'items' => [
            ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'users', 'can' => 'user.view'],
            ['label' => 'Roles & permissions', 'route' => 'admin.roles', 'icon' => 'shield', 'can' => 'role.view'],
            ['label' => 'Company settings', 'route' => 'admin.settings', 'icon' => 'cog', 'can' => 'setting.view'],
            ['label' => 'Approval levels', 'route' => 'admin.approval-levels', 'icon' => 'layers', 'can' => 'setting.view'],
            ['label' => 'Number series', 'route' => 'admin.number-series', 'icon' => 'hash', 'can' => 'setting.view'],
            ['label' => 'SLA policies', 'route' => 'workspace.sla.policies', 'icon' => 'clock', 'can' => 'sla_policy.view'],
            ['label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'funnel', 'can' => 'integration.view', 'also' => 'admin.integrations.*'],
            ['label' => 'System health', 'route' => 'admin.health', 'icon' => 'shield', 'can' => 'setting.view'],
            ['label' => 'Audit log', 'route' => 'admin.audit-log', 'icon' => 'clock', 'can' => 'audit_log.view'],
        ]],
    ],
];
