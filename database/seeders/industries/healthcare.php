<?php

// Fields and an audit trail do not make a hospital compliant with NABH or any other standard; a qualified review is still required.
return [
    'code' => 'healthcare', 'name' => 'Healthcare & Hospitals', 'icon' => 'shield',
    'description' => 'Bed and OT utilisation, expiry, billing leakage and turnaround time. Carries fields and history only: it does not make a hospital compliant.',
    'kpis' => [
        ['bed_utilisation', 'Bed utilisation', 'occupancy', '%', 'higher_better', 80, 65, 50, 'occupied bed-days', 'available bed-days'],
        ['ot_utilisation', 'OT utilisation', 'billable_utilisation', '%', 'higher_better', 75, 60, 45, 'OT hours used', 'OT hours available'],
        ['expiry_exposure', 'Expiry exposure', 'expiry_exposure', '₹', 'lower_better', 25000, 100000, 250000, 'stock value expiring soon', null],
        ['billing_leakage', 'Billing leakage', 'billing_leakage', '₹', 'lower_better', 10000, 50000, 100000, 'billable − billed', null],
        ['turnaround_minutes', 'Turnaround time', 'turnaround', 'min', 'lower_better', 30, 60, 90, 'minutes from request to result', null],
    ],
    'categories' => [
        ['resource_underuse', 'Resource under-utilisation', 'manual', 'low'],
        ['inventory_expiry', 'Inventory expiry', 'expiry', 'medium'],
        ['billing_leakage', 'Billing leakage', 'billing_leakage', 'medium'],
        ['operational_delay', 'Operational delay', 'manual', 'medium'],
    ],
    'fields' => [
        ['department', 'Department / ward', 'text', null, null, true, 'Process'],
        ['patient_impact', 'Patient impact', 'boolean', null, null, false, 'Quality'],
        ['billable_value', 'Billable value', 'decimal', '₹', null, false, 'Impact'],
        ['billed_value', 'Billed value', 'decimal', '₹', null, false, 'Impact'],
    ],
    'reports' => [['billing', 'Billing leakage by department'], ['utilisation', 'Bed and OT utilisation']],
];
