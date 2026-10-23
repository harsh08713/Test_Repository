<?php

return [
    'code' => 'metals_mining_cement', 'name' => 'Metals, Mining & Cement', 'icon' => 'cube',
    'description' => 'Recovery rate, fuel per tonne, kiln availability and material variance.',
    'kpis' => [
        ['recovery_rate', 'Recovery rate', 'recovery_rate', '%', 'higher_better', 92, 85, 78, 'metal recovered', 'metal in feed'],
        ['fuel_per_tonne', 'Fuel per tonne', 'energy_per_tonne', 'kg/t', 'lower_better', 100, 120, 140, 'fuel used', 'tonnes produced'],
        ['kiln_availability', 'Kiln availability', 'availability', '%', 'higher_better', 90, 80, 70, 'run time', 'planned time'],
        ['material_variance', 'Material variance', 'cost_variance', '%', 'lower_better', 1, 3, 5, 'book − physical', 'book'],
    ],
    'categories' => [
        ['material_recovery', 'Material recovery', 'material_cost', 'medium'],
        ['furnace_loss', 'Furnace loss', 'energy_excess', 'medium'],
        ['kiln_energy', 'Kiln fuel and energy', 'energy_excess', 'medium'],
    ],
    'fields' => [
        ['plant_area', 'Plant area', 'select', null, ['Mine', 'Crusher', 'Furnace', 'Kiln', 'Mill', 'Dispatch'], true, 'Process'],
        ['feed_tonnes', 'Feed', 'decimal', 't', null, false, 'Impact'],
        ['recovered_tonnes', 'Recovered', 'decimal', 't', null, false, 'Impact'],
        ['fuel_excess', 'Fuel above standard', 'decimal', null, null, false, 'Impact'],
    ],
    'reports' => [['recovery', 'Recovery by area'], ['fuel', 'Fuel and energy variance']],
];
