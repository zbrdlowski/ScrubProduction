<?php

return [

    // PAGE ACCESS RULES
    'pages' => [

        // DASHBOARDS
        'plastics_dashboard' => [
            'min_permission' => 100,
            'departments'    => [6], // plastics
        ],

        'stock_movements' => [
            'min_permission' => 100,
        ],

        'historical_movements' => [
            'min_permission' => 200,
        ],

        // INVENTORY
        'items' => [
            'min_permission' => 100,
        ],
        'add_item' => [
            'min_permission' => 200,
        ],
        'upload_items' => [
            'min_permission' => 300,
        ],

        // STOCK OPS
        'scan_form' => [
            'min_permission' => 100,
        ],
        'scan_form_out' => [
            'min_permission' => 100,
        ],
        'bulk_scan_in' => [
            'min_permission' => 200,
        ],

        // ORDERS
        'plastics_orders_active' => [
            'min_permission' => 100,
            'departments' => [6],
        ],

        // ADMIN
        'employee' => [
            'min_permission' => 500,
        ],
        'controlls' => [
            'min_permission' => 500,
        ],
    ],

    // MENU GROUPS
    'menus' => [

        'inventory' => [
            'min_permission' => 100,
            'items' => ['items','add_item','upload_items','shelves','display_stock'],
        ],

        'stock_ops' => [
            'min_permission' => 100,
            'items' => ['scan_form','scan_form_out','bulk_scan_in','reset_location','relocate_item'],
        ],

        'admin' => [
            'min_permission' => 500,
            'items' => ['employee','controlls'],
        ],
    ],
];
?>