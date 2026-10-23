<?php

return [
    'code' => 'logistics', 'name' => 'Logistics & Warehousing', 'icon' => 'map',
    'description' => 'On-time delivery, damage rate, storage utilisation and cost per shipment.',
    'kpis' => [
        ['on_time_delivery', 'On-time delivery', 'on_time_delivery', '%', 'higher_better', 95, 88, 80, 'on-time deliveries', 'total deliveries'],
        ['damage_rate', 'Damage rate', 'damage_rate', '%', 'lower_better', 0.5, 1.5, 3, 'damaged units', 'units shipped'],
        ['storage_utilisation', 'Storage utilisation', 'occupancy', '%', 'higher_better', 80, 65, 50, 'occupied positions', 'available positions'],
        ['cost_per_shipment', 'Cost per shipment', 'cost_per_unit', '₹', 'lower_better', 400, 550, 700, 'logistics cost', 'shipments'],
    ],
    'categories' => [
        ['damaged_goods', 'Damaged goods', 'material_cost', 'medium'],
        ['route_inefficiency', 'Route inefficiency', 'manual', 'low'],
        ['delivery_loss', 'Delivery loss', 'material_cost', 'medium'],
        ['storage_loss', 'Storage loss', 'shrinkage', 'medium'],
    ],
    'fields' => [
        ['shipment_no', 'Shipment number', 'text', null, null, false, 'Traceability'],
        ['route', 'Route', 'text', null, null, false, 'Process'],
        ['vehicle', 'Vehicle', 'text', null, null, false, 'Process'],
        ['units_damaged', 'Units damaged', 'number', 'units', null, false, 'Impact'],
    ],
    'reports' => [['otd', 'On-time delivery by route'], ['damage', 'Damage by carrier']],
];
