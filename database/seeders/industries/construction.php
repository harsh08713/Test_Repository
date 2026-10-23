<?php

return [
    'code' => 'construction', 'name' => 'Construction & Infrastructure', 'icon' => 'building',
    'description' => 'Cost and schedule variance, material waste and rework exposure.',
    'kpis' => [
        ['cost_variance', 'Cost variance', 'cost_variance', '%', 'lower_better', 2, 5, 10, 'actual cost − budget', 'budget'],
        ['schedule_variance', 'Schedule variance', 'cost_variance', '%', 'lower_better', 2, 5, 10, 'actual days − planned days', 'planned days'],
        ['material_waste', 'Material waste', 'waste_rate', '%', 'lower_better', 3, 6, 10, 'material wasted', 'material issued'],
        ['rework_exposure', 'Rework exposure', 'rework_cost', '₹', 'lower_better', 100000, 500000, 1000000, 'estimated rework', null],
    ],
    'categories' => [
        ['material_waste', 'Material waste', 'material_cost', 'medium'],
        ['rework', 'Rework', 'rework', 'medium'],
        ['delay', 'Delay', 'manual', 'medium'],
        ['contractor_overrun', 'Contractor overrun', 'manual', 'medium'],
    ],
    'fields' => [
        ['project', 'Project', 'text', null, null, true, 'Project'],
        ['work_package', 'Work package', 'text', null, null, false, 'Project'],
        ['contractor', 'Contractor', 'text', null, null, false, 'Project'],
        ['delay_days', 'Delay', 'number', 'days', null, false, 'Impact'],
    ],
    'reports' => [['variance', 'Cost and schedule variance'], ['rework', 'Rework by contractor']],
];
