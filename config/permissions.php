<?php

return [
   'roles' => [
      'admin' => [
         'dashboard.view',
         'sucursales.manage',
         'usuarios.manage',
         'ventas.read',
         'ventas.write',
         'ventas.void',
         'reportes.view',
         'logs.view',
      ],
      'usuario' => [
         'dashboard.view',
         'ventas.read',
         'ventas.write',
      ],
   ],
];

