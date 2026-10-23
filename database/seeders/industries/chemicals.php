<?php

return [
    'code' => 'chemicals', 'name' => 'Chemicals & Petrochemicals', 'icon' => 'scale',
    'description' => 'Process yield, off-spec batches, leak exposure and energy per tonne.',
    'kpis' => [
        ['process_yield', 'Process yield', 'process_yield', '%', 'higher_better', 95, 90, 85, 'good output qty', 'input qty'],
        ['offspec_batches', 'Off-spec batches', 'defect_rate', '%', 'lower_better', 1, 3, 6, 'off-spec batches', 'batches made'],
        ['leak_exposure', 'Leak exposure', 'open_count', '', 'lower_better', 0, 2, 5, 'open leak incidents', null],
        ['energy_per_tonne', 'Energy per tonne', 'energy_per_tonne', 'kWh/t', 'lower_better', 400, 500, 650, 'kWh', 'tonnes produced'],
    ],
    'categories' => [
        ['yield_loss', 'Yield loss', 'material_cost', 'medium'],
        ['leak', 'Leak', 'material_cost', 'high'],
        ['offspec_batch', 'Off-spec batch', 'scrap_net', 'medium'],
        ['shutdown', 'Shutdown', 'downtime', 'high'],
    ],
    'fields' => [
        ['unit_process', 'Unit / process', 'text', null, null, true, 'Process'],
        ['quantity_lost_kg', 'Quantity lost', 'decimal', 'kg', null, false, 'Impact'],
        ['hazard_released', 'Hazardous release', 'boolean', null, null, false, 'Safety'],
        ['shutdown_hours', 'Shutdown', 'decimal', 'h', null, false, 'Impact'],
    ],
    'reports' => [['yield_loss', 'Yield loss by unit'], ['leaks', 'Leak register']],
];
