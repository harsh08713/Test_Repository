<?php

return [
    'code' => 'energy_utilities', 'name' => 'Energy & Utilities', 'icon' => 'cog',
    'description' => 'Generation variance, asset availability and distribution loss.',
    'kpis' => [
        ['generation_variance', 'Generation variance', 'cost_variance', '%', 'lower_better', 2, 5, 8, 'actual − planned generation', 'planned generation'],
        ['asset_availability', 'Asset availability', 'availability', '%', 'higher_better', 95, 90, 80, 'available hours', 'planned hours'],
        ['distribution_loss', 'Distribution loss', 'distribution_loss', '%', 'lower_better', 8, 12, 18, 'energy input − energy billed', 'energy input'],
    ],
    'categories' => [
        ['generation_variance', 'Generation variance', 'energy_excess', 'medium'],
        ['asset_degradation', 'Asset degradation', 'manual', 'medium'],
        ['distribution_loss', 'Distribution loss', 'energy_excess', 'medium'],
    ],
    'fields' => [
        ['asset_ref', 'Asset', 'text', null, null, true, 'Asset'],
        ['energy_input_kwh', 'Energy input', 'decimal', 'kWh', null, false, 'Impact'],
        ['energy_billed_kwh', 'Energy billed', 'decimal', 'kWh', null, false, 'Impact'],
        ['feeder', 'Feeder / network section', 'text', null, null, false, 'Process'],
    ],
    'reports' => [['distribution', 'Distribution loss by feeder'], ['availability', 'Asset availability']],
];
