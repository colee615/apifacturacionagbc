<?php

return [
   'roles' => [
      'admin' => [
         'dashboard.view',
         'empresa.manage',
         'sucursales.manage',
         'usuarios.manage',
         'clientes.read',
         'clientes.write',
         'servicios.manage',
         'ventas.read',
         'ventas.write',
         'ventas.void',
         'reportes.view',
         'logs.view',
      ],
      'usuario' => [
         'dashboard.view',
         'clientes.read',
         'clientes.write',
         'ventas.read',
         'ventas.write',
      ],
   ],
];

