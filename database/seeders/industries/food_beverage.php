<?php

return [
    'code' => 'food_beverage', 'name' => 'Food & Beverage', 'icon' => 'cube',
    'description' => 'Yield, expiry exposure, fill-weight variance and cold-chain alerts.',
    'kpis' => [
        ['yield', 'Yield', 'process_yield', '%', 'higher_better', 95, 90, 85, 'good output', 'input'],
        ['expiry_exposure', 'Expiry exposure', 'expiry_exposure', '₹', 'lower_better', 50000, 200000, 400000, 'stock value expiring soon', null],
        ['fill_weight_variance', 'Fill-weight variance', 'fill_weight_variance', '%', 'lower_better', 1, 2, 4, 'average actual − target', 'target'],
        ['cold_chain_alerts', 'Cold-chain alerts', 'open_count', '', 'lower_better', 0, 3, 6, 'temperature excursions', null],
    ],
    'categories' => [
        ['spoilage', 'Spoilage', 'material_cost', 'medium'],
        ['shelf_life_loss', 'Shelf-life loss', 'expiry', 'medium'],
        ['fill_weight_variation', 'Fill-weight variation', 'material_cost', 'low'],
        ['cold_chain_failure', 'Cold-chain failure', 'scrap_net', 'high'],
    ],
    'fields' => [
        ['batch_no', 'Batch number', 'text', null, null, false, 'Traceability'],
        ['storage_temp_c', 'Storage temperature', 'decimal', '°C', null, false, 'Cold chain'],
        ['excursion_hours', 'Hours out of range', 'decimal', 'h', null, false, 'Cold chain'],
        ['fill_target_g', 'Fill target', 'decimal', 'g', null, false, 'Process'],
    ],
    'reports' => [['spoilage', 'Spoilage by product'], ['cold_chain', 'Cold-chain excursions']],
];
