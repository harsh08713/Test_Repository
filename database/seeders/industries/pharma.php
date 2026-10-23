<?php

// Fields and an audit trail do not make a company compliant with GMP, 21 CFR Part 11 or any other rule; a qualified review is still required.
return [
    'code' => 'pharma', 'name' => 'Pharmaceuticals & Medical Devices', 'icon' => 'shield',
    'description' => 'Batch release, deviations, expiry exposure and overdue CAPA. Carries fields and history only: it does not make a plant compliant.',
    'kpis' => [
        ['batch_release', 'Batches released first time', 'first_pass_yield', '%', 'higher_better', 95, 85, 75, 'batches released first time', 'batches produced'],
        ['open_deviations', 'Open deviations', 'open_count', '', 'lower_better', 5, 15, 25, 'deviations not closed', null],
        ['expiry_exposure', 'Expiry exposure', 'expiry_exposure', '₹', 'lower_better', 50000, 250000, 500000, 'stock value expiring soon', null],
        ['capa_overdue', 'CAPA overdue', 'capa_overdue', '', 'lower_better', 0, 3, 8, 'open CAPA past due date', null],
    ],
    'categories' => [
        ['batch_deviation', 'Batch deviation', 'scrap_net', 'high'],
        ['expiry', 'Expiry', 'expiry', 'medium'],
        ['contamination', 'Contamination', 'scrap_net', 'critical'],
        ['validation_issue', 'Validation issue', 'manual', 'high'],
    ],
    'fields' => [
        ['batch_no', 'Batch number', 'text', null, null, true, 'Traceability'],
        ['deviation_class', 'Deviation class', 'select', null, ['Minor', 'Major', 'Critical'], true, 'Quality'],
        ['product_affected', 'Product affected', 'boolean', null, null, false, 'Quality'],
        ['patient_safety_risk', 'Patient safety risk', 'boolean', null, null, false, 'Quality'],
    ],
    'reports' => [['deviations', 'Deviation register'], ['expiry', 'Expiry exposure']],
];
