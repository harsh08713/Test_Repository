<?php

return [
    'code' => 'it_services', 'name' => 'IT & Professional Services', 'icon' => 'cog',
    'description' => 'Billable utilisation, project margin, rework and SLA compliance.',
    'kpis' => [
        ['billable_utilisation', 'Billable utilisation', 'billable_utilisation', '%', 'higher_better', 75, 65, 55, 'billable hours', 'available hours'],
        ['project_margin', 'Project margin', 'gross_margin_pct', '%', 'higher_better', 30, 20, 10, 'project profit', 'project revenue'],
        ['rework_hours', 'Rework hours', 'rework', 'h', 'lower_better', 40, 100, 200, 'hours spent redoing work', null],
        ['sla_compliance', 'SLA compliance', 'sla_compliance', '%', 'higher_better', 95, 90, 80, 'tickets within SLA', 'total tickets'],
    ],
    'categories' => [
        ['project_overrun', 'Project overrun', 'manual', 'medium'],
        ['unbilled_hours', 'Unbilled hours', 'billing_leakage', 'medium'],
        ['rework', 'Rework', 'rework', 'medium'],
        ['sla_breach', 'SLA breach', 'sla_penalty', 'high'],
    ],
    'fields' => [
        ['project', 'Project', 'text', null, null, true, 'Project'],
        ['client', 'Client', 'text', null, null, false, 'Project'],
        ['hours_lost', 'Hours lost', 'decimal', 'h', null, false, 'Impact'],
        ['sla_breaches', 'SLA breaches', 'number', null, null, false, 'Impact'],
    ],
    'reports' => [['utilisation', 'Utilisation by team'], ['sla', 'SLA breaches and penalties']],
];
