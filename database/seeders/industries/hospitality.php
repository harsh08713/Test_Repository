<?php

return [
    'code' => 'hospitality', 'name' => 'Hospitality', 'icon' => 'home',
    'description' => 'Occupancy, food waste, energy per room and service recovery.',
    'kpis' => [
        ['occupancy', 'Occupancy', 'occupancy', '%', 'higher_better', 75, 60, 45, 'occupied room-nights', 'available room-nights'],
        ['food_waste', 'Food waste', 'waste_rate', '%', 'lower_better', 3, 6, 10, 'food wasted', 'food purchased'],
        ['energy_per_room', 'Energy per room', 'energy_per_tonne', 'kWh', 'lower_better', 20, 28, 35, 'kWh', 'occupied rooms'],
        ['service_recovery_cost', 'Service recovery cost', 'service_recovery', '₹', 'lower_better', 5000, 20000, 40000, 'compensation given', null],
    ],
    'categories' => [
        ['food_waste', 'Food waste', 'material_cost', 'low'],
        ['room_underuse', 'Room under-utilisation', 'manual', 'low'],
        ['energy_cost', 'Energy cost', 'energy_excess', 'low'],
        ['service_recovery', 'Service recovery', 'manual', 'medium'],
    ],
    'fields' => [
        ['outlet', 'Outlet / department', 'text', null, null, false, 'Process'],
        ['meal_period', 'Meal period', 'select', null, ['Breakfast', 'Lunch', 'Dinner', 'Banquet'], false, 'Process'],
        ['covers', 'Covers affected', 'number', null, null, false, 'Impact'],
        ['guest_compensation', 'Guest compensation', 'decimal', '₹', null, false, 'Impact'],
    ],
    'reports' => [['food_waste', 'Food waste by outlet'], ['recovery', 'Service recovery']],
];
