<?php

return [
    'code' => 'textile', 'name' => 'Textile & Apparel', 'icon' => 'layers',
    'description' => 'Fabric and garment makers: fabric utilisation, dye rejection, cutting waste and order adherence.',
    'kpis' => [
        ['fabric_utilisation', 'Fabric utilisation', 'fabric_utilisation', '%', 'higher_better', 85, 75, 65, 'fabric used in garments', 'fabric issued'],
        ['dye_rejection', 'Dye rejection', 'defect_rate', '%', 'lower_better', 2, 5, 8, 'rejected dye lots', 'dye lots processed'],
        ['cutting_waste', 'Cutting waste', 'waste_rate', '%', 'lower_better', 8, 12, 16, 'fabric cut away', 'fabric cut'],
        ['order_adherence', 'Order adherence', 'on_time_delivery', '%', 'higher_better', 95, 85, 75, 'orders shipped on time', 'orders due'],
    ],
    'categories' => [
        ['fabric_waste', 'Fabric waste', 'material_cost', 'medium'],
        ['dye_variation', 'Dye variation', 'scrap_net', 'medium'],
        ['cutting_loss', 'Cutting loss', 'material_cost', 'low'],
        ['order_delay', 'Order delay', 'manual', 'medium'],
    ],
    'fields' => [
        ['fabric_lot', 'Fabric lot', 'text', null, null, false, 'Material'],
        ['metres_lost', 'Fabric lost', 'decimal', 'm', null, false, 'Impact'],
        ['dye_lot', 'Dye lot', 'text', null, null, false, 'Process'],
        ['stage', 'Stage', 'select', null, ['Spinning', 'Weaving', 'Dyeing', 'Cutting', 'Stitching', 'Finishing'], true, 'Process'],
    ],
    'reports' => [['fabric_waste', 'Fabric waste by stage'], ['order_delays', 'Delayed orders']],
];
