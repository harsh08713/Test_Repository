<?php

return [
    'code' => 'agriculture', 'name' => 'Agriculture', 'icon' => 'layers',
    'description' => 'Yield per acre, input cost, equipment utilisation and storage loss.',
    'kpis' => [
        ['yield_per_acre', 'Yield per acre', 'yield_per_acre', 'qtl/acre', 'higher_better', 20, 15, 10, 'harvest quantity', 'acres'],
        ['input_cost_per_acre', 'Input cost per acre', 'cost_per_unit', '₹', 'lower_better', 12000, 16000, 20000, 'input cost', 'acres'],
        ['equipment_utilisation', 'Equipment utilisation', 'billable_utilisation', '%', 'higher_better', 70, 55, 40, 'hours used', 'hours available'],
        ['storage_loss', 'Storage loss', 'waste_rate', '%', 'lower_better', 2, 5, 8, 'quantity spoiled', 'quantity stored'],
    ],
    'categories' => [
        ['crop_wastage', 'Crop wastage', 'material_cost', 'medium'],
        ['input_overconsumption', 'Input over-consumption', 'material_cost', 'low'],
        ['equipment_idle', 'Equipment idle', 'downtime', 'low'],
        ['storage_spoilage', 'Storage spoilage', 'expiry', 'medium'],
    ],
    'fields' => [
        ['plot', 'Plot / field', 'text', null, null, true, 'Field'],
        ['crop', 'Crop', 'text', null, null, false, 'Field'],
        ['season', 'Season', 'select', null, ['Kharif', 'Rabi', 'Zaid'], false, 'Field'],
        ['acres_affected', 'Area affected', 'decimal', 'acres', null, false, 'Impact'],
    ],
    'reports' => [['yield', 'Yield by plot'], ['storage', 'Storage loss by store']],
];
