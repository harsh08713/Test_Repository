<?php

return [
    'code' => 'retail', 'name' => 'Retail & Wholesale', 'icon' => 'tag',
    'description' => 'Shrinkage, expiry, gross margin and markdown loss.',
    'kpis' => [
        ['shrinkage', 'Shrinkage', 'shrinkage_pct', '%', 'lower_better', 0.5, 1.5, 3, 'shrinkage value', 'net revenue'],
        ['expiry_exposure', 'Expiry exposure', 'expiry_exposure', '₹', 'lower_better', 25000, 100000, 250000, 'stock value expiring soon', null],
        ['gross_margin', 'Gross margin', 'gross_margin_pct', '%', 'higher_better', 25, 18, 10, 'gross profit', 'net revenue'],
        ['markdown_loss', 'Markdown loss', 'markdown', '₹', 'lower_better', 20000, 80000, 150000, '(original − markdown price) × qty', null],
    ],
    'categories' => [
        ['shrinkage', 'Shrinkage', 'shrinkage', 'medium'],
        ['expired_stock', 'Expired stock', 'expiry', 'medium'],
        ['markdown', 'Markdown', 'markdown', 'low'],
        ['margin_erosion', 'Margin erosion', 'manual', 'medium'],
    ],
    'fields' => [
        ['store', 'Store', 'text', null, null, false, 'Location'],
        ['book_qty', 'Book quantity', 'decimal', null, null, false, 'Impact'],
        ['physical_qty', 'Physical quantity', 'decimal', null, null, false, 'Impact'],
        ['cause', 'Suspected cause', 'select', null, ['Theft', 'Damage', 'Admin error', 'Supplier short', 'Unknown'], false, 'Impact'],
    ],
    'reports' => [['shrinkage', 'Shrinkage by store'], ['markdown', 'Markdown by category']],
];
