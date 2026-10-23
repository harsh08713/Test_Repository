<?php

return [
    'code' => 'electronics', 'name' => 'Electronics & Electrical', 'icon' => 'cog',
    'description' => 'First-pass yield, component shortages, test failures and rework cost.',
    'kpis' => [
        ['first_pass_yield', 'First-pass yield', 'first_pass_yield', '%', 'higher_better', 97, 92, 85, 'units passed first time', 'units started'],
        ['shortage_lines', 'Component shortages', 'open_count', '', 'lower_better', 2, 8, 15, 'lines short', null],
        ['test_failure_rate', 'Test failure rate', 'defect_rate', '%', 'lower_better', 2, 5, 8, 'units failing test', 'units tested'],
        ['rework_cost', 'Rework cost', 'rework_cost', '₹', 'lower_better', 50000, 150000, 300000, 'rework hours × rate + material', null],
    ],
    'categories' => [
        ['component_shortage', 'Component shortage', 'manual', 'medium'],
        ['assembly_defect', 'Assembly defect', 'rework', 'medium'],
        ['test_failure', 'Test failure', 'rework', 'medium'],
    ],
    'fields' => [
        ['work_order', 'Work order', 'text', null, null, false, 'Traceability'],
        ['station', 'Station', 'text', null, null, false, 'Process'],
        ['rework_hours', 'Rework hours', 'decimal', 'h', null, false, 'Impact'],
        ['failed_test', 'Failed test', 'select', null, ['ICT', 'Functional', 'Burn-in', 'Final QC'], false, 'Quality'],
    ],
    'reports' => [['yield', 'First-pass yield by line'], ['rework', 'Rework cost']],
];
