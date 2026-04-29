<?php

return [
    'template_path' => resource_path('templates/radiografia_template.xlsx'),

    'sheets' => [
        'dashboard' => 'Dashbord',
        'global' => 'GLOBAL',
    ],

    'maps' => [
        'GLOBAL' => [
            'periodo' => 'B2',
            'total_empleados' => 'H9',
            'gasto_total' => 'H10',
            'neto_total' => 'H11',
            'pagos_total' => 'H12',
            'bonos_total' => 'H13',
            'descuentos_total' => 'H14',
        ],
        'DASHBOARD' => [
            'periodo' => 'B2',
            'total_empleados' => 'C6',
            'gasto_total' => 'C7',
            'neto_total' => 'C8',
        ],
    ],
];
