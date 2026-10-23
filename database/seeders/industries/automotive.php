<?php

// Each industry file returns the same shape; IndustryTemplateSeeder reads it. Thresholds are STARTING values, not standards:
// every company sets its own in Industries → the template. Row formats are documented in IndustryTemplateSeeder.
return [
    'code' => 'automotive', 'name' => 'Automotive', 'icon' => 'cog',
    'description' => 'Vehicle and component makers: line availability, downtime, defects and warranty exposure.',
    'kpis' => [
        ['oee', 'Overall equipment effectiveness', 'oee', '%', 'higher_better', 85, 65, 50, 'availability × performance × quality', null],
        ['line_availability', 'Line availability', 'availability', '%', 'higher_better', 90, 80, 70, 'run time', 'planned production time'],
        ['downtime_hours', 'Downtime', 'downtime_hours', 'h', 'lower_better', 20, 40, 60, 'stoppage hours', null],
        ['defect_rate', 'Defect rate', 'defect_rate', '%', 'lower_better', 1, 3, 5, 'defective units', 'units produced'],
        ['warranty_exposure', 'Warranty exposure', 'warranty_exposure', '₹', 'lower_better', 100000, 500000, 1000000, 'open claims × average claim cost', null],
    ],
    'categories' => [
        ['line_stoppage', 'Line stoppage', 'downtime', 'high'],
        ['component_defect', 'Component defect', 'rework', 'medium'],
        ['warranty_claim', 'Warranty claim', 'warranty', 'high'],
    ],
    'fields' => [
        ['line', 'Production line', 'text', null, null, true, 'Process'],
        ['shift', 'Shift', 'select', null, ['A', 'B', 'C'], false, 'Process'],
        ['downtime_hours', 'Downtime', 'decimal', 'h', null, false, 'Impact'],
        ['defect_count', 'Defective units', 'number', 'units', null, false, 'Impact'],
        ['vin_or_lot', 'VIN / lot number', 'text', null, null, false, 'Traceability'],
    ],
    'reports' => [['line_downtime', 'Line downtime by cause'], ['warranty_claims', 'Warranty claims and exposure']],
];
