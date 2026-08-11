<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\Usuario;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
   $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:purge-sales-data {--dry-run : Muestra el alcance sin borrar datos} {--force : Ejecuta sin confirmacion interactiva}', function () {
   $tables = [
      'detalle_ventas',
      'ventas',
      'notificaciones',
      'facturacion_cart_items',
      'facturacion_carts',
      'caja_arqueo_ventas',
      'caja_arqueos',
      'cajas_diarias',
      'ficha_postal_movimientos',
      'ficha_postal_saldos',
      'ficha_postal_sucursal_movimientos',
      'ficha_postal_sucursal_saldos',
   ];

   $existingTables = [];
   $counts = [];
   foreach ($tables as $table) {
      if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
         continue;
      }

      $existingTables[] = $table;
      $counts[$table] = DB::table($table)->count();
   }

   if ($existingTables === []) {
      $this->warn('No se encontraron tablas de ventas para limpiar.');
      return self::SUCCESS;
   }

   $totalRows = array_sum($counts);

   $this->info('Tablas transaccionales de venta detectadas:');
   foreach ($existingTables as $table) {
      $this->line(sprintf('- %s: %d registros', $table, $counts[$table]));
   }
   $this->newLine();
   $this->line(sprintf('Total estimado a limpiar: %d registros', $totalRows));

   if ($this->option('dry-run')) {
      $this->comment('Simulacion completada. No se elimino ningun dato.');
      return self::SUCCESS;
   }

   if (!$this->option('force') && !$this->confirm('Esto eliminara permanentemente todos los datos transaccionales de venta. Deseas continuar?', false)) {
      $this->warn('Operacion cancelada.');
      return self::SUCCESS;
   }

   DB::transaction(function () use ($existingTables) {
      $driver = DB::getDriverName();
      $wrappedTables = array_map(function ($table) use ($driver) {
         return match ($driver) {
            'pgsql', 'sqlite' => '"' . $table . '"',
            default => '`' . $table . '`',
         };
      }, $existingTables);

      if ($driver === 'pgsql') {
         DB::statement('TRUNCATE TABLE ' . implode(', ', $wrappedTables) . ' RESTART IDENTITY CASCADE');
         return;
      }

      if ($driver === 'mysql') {
         DB::statement('SET FOREIGN_KEY_CHECKS=0');
         DB::statement('TRUNCATE TABLE ' . implode(', ', $wrappedTables));
         DB::statement('SET FOREIGN_KEY_CHECKS=1');
         return;
      }

      foreach (array_reverse($existingTables) as $table) {
         DB::table($table)->delete();
      }
   });

   $this->info('Limpieza de datos de venta completada correctamente.');
})->purpose('Elimina todos los datos transaccionales relacionados con ventas');

Artisan::command('app:reset-db-admin 
    {--admin-name=Administrador}
    {--admin-email=admin@agbc.local}
    {--admin-password=}', function () {
   $this->warn('Este comando eliminara todos los datos y recreara la base desde cero.');

   if (!$this->confirm('Deseas continuar?', true)) {
      $this->info('Operacion cancelada.');
      return self::SUCCESS;
   }

   $this->call('migrate:fresh', ['--force' => true]);

   $adminPassword = (string) $this->option('admin-password');

   if ($adminPassword === '') {
      $adminPassword = \Illuminate\Support\Str::password(18, true, true, true, false);
      $this->warn('No se proporciono --admin-password. Se genero una contraseña aleatoria segura.');
   }

   $usuario = Usuario::create([
      'name' => (string) $this->option('admin-name'),
      'email' => (string) $this->option('admin-email'),
      'password' => Hash::make($adminPassword),
      'estado' => 1,
   ]);

   $adminRole = Role::where('slug', 'admin')->first();

   if ($adminRole) {
      $usuario->roles()->sync([$adminRole->id]);
   }

   $this->newLine();
   $this->info('Base reiniciada correctamente.');
   $this->line('Admin creado: ' . $usuario->email . ' (ID ' . $usuario->id . ')');
   $this->line('Password temporal: ' . $adminPassword);
   $this->warn('Cambia esta contraseña inmediatamente despues del primer ingreso.');

   return self::SUCCESS;
})->purpose('Recrea la base de datos y deja un unico administrador inicial');

Artisan::command('app:recover-paid-qr-cart
    {--cart_id= : ID del facturacion_cart a recuperar}
    {--internal_code= : Codigo de orden / internal_code del QR}
    {--transaction_id= : transaction_id reportado por Qhantuy}
    {--dry-run : Solo muestra lo que se actualizaria}', function () {
   $cartId = (int) $this->option('cart_id');
   $internalCode = trim((string) $this->option('internal_code'));
   $transactionId = (int) $this->option('transaction_id');
   $dryRun = (bool) $this->option('dry-run');

   if ($cartId <= 0 && $internalCode === '' && $transactionId <= 0) {
      $this->error('Debes enviar al menos uno de estos filtros: --cart_id, --internal_code o --transaction_id.');
      return self::FAILURE;
   }

   $paymentRow = null;
   if ($internalCode !== '') {
      $paymentRow = DB::table('qhantuy_qr_payments')->where('internal_code', $internalCode)->first();
   }
   if (!$paymentRow && $transactionId > 0) {
      $paymentRow = DB::table('qhantuy_qr_payments')->where('transaction_id', $transactionId)->first();
   }

   $cartQuery = DB::table('facturacion_carts');
   if ($cartId > 0) {
      $cartQuery->where('id', $cartId);
   } elseif ($internalCode !== '') {
      $cartQuery->where('codigo_orden', $internalCode);
   } elseif ($paymentRow) {
      $cartQuery->where('codigo_orden', (string) ($paymentRow->internal_code ?? ''));
   }
   $cart = $cartQuery->orderByDesc('id')->first();

   if (!$cart && $paymentRow) {
      $cart = DB::table('facturacion_carts')
         ->where('qr_transaction_id', (string) ($paymentRow->transaction_id ?? ''))
         ->orderByDesc('id')
         ->first();
   }
   if (!$cart && $transactionId > 0) {
      $cart = DB::table('facturacion_carts')
         ->where('qr_transaction_id', (string) $transactionId)
         ->orderByDesc('id')
         ->first();
   }

   if (!$cart) {
      $this->error('No se encontro facturacion_cart para los criterios enviados.');
      if ($paymentRow) {
         $this->line('Existe registro QR, pero no cart vinculado:');
         $this->line(json_encode([
            'internal_code' => $paymentRow->internal_code ?? null,
            'transaction_id' => $paymentRow->transaction_id ?? null,
            'payment_status' => $paymentRow->payment_status ?? null,
            'checkout_amount' => $paymentRow->checkout_amount ?? null,
            'updated_at' => $paymentRow->updated_at ?? null,
         ], JSON_UNESCAPED_UNICODE));
      }
      return self::FAILURE;
   }

   if (!$paymentRow) {
      $paymentRow = DB::table('qhantuy_qr_payments')
         ->where('internal_code', (string) ($cart->codigo_orden ?? ''))
         ->orWhere('transaction_id', (int) ($cart->qr_transaction_id ?? 0))
         ->orderByDesc('id')
         ->first();
   }

   $rawPaymentStatus = strtolower(trim((string) ($paymentRow->payment_status ?? '')));
   $isPaid = in_array($rawPaymentStatus, ['paid', 'completed', 'approved', 'success', 'pagado'], true);
   if (!$isPaid) {
      $this->error('El QR localizado no figura como pagado. Estado actual: ' . ($rawPaymentStatus !== '' ? $rawPaymentStatus : 'sin estado'));
      return self::FAILURE;
   }

   $linkedVenta = DB::table('ventas')
      ->whereRaw('cast(origen_venta_id as varchar) = cast(? as varchar)', [(string) $cart->id])
      ->whereIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
      ->orderByDesc('id')
      ->first();

   $estadoEmisionActual = strtoupper(trim((string) ($cart->estado_emision ?? '')));
   $nuevoEstadoEmision = in_array($estadoEmisionActual, ['', 'NO_APLICA'], true)
      ? 'PENDIENTE'
      : $estadoEmisionActual;
   $mensajeBase = trim((string) ($cart->mensaje_emision ?? ''));
   $mensajeRecuperacion = 'Pago QR recuperado manualmente. Venta reabierta para reintento de emision fiscal.';
   $nuevoMensaje = $mensajeBase !== '' && str_contains($mensajeBase, $mensajeRecuperacion)
      ? $mensajeBase
      : trim($mensajeBase . ' ' . $mensajeRecuperacion);

   $cartUpdates = [
      'codigo_orden' => trim((string) ($cart->codigo_orden ?? ($paymentRow->internal_code ?? ''))) ?: null,
      'qr_transaction_id' => (string) ($paymentRow->transaction_id ?? $cart->qr_transaction_id ?? ''),
      'metodo_pago' => 'qr',
      'estado_pago' => 'pagado',
      'estado' => 'emitido',
      'estado_emision' => $nuevoEstadoEmision,
      'mensaje_emision' => $nuevoMensaje,
      'emitido_en' => $cart->emitido_en ?? now(),
      'cerrado_en' => $cart->cerrado_en ?? now(),
      'updated_at' => now(),
   ];

   $this->info('Cart encontrado:');
   $this->line(json_encode([
      'cart_id' => $cart->id,
      'codigo_orden' => $cart->codigo_orden ?? null,
      'qr_transaction_id' => $cart->qr_transaction_id ?? null,
      'estado' => $cart->estado ?? null,
      'estado_pago' => $cart->estado_pago ?? null,
      'estado_emision' => $cart->estado_emision ?? null,
      'venta_id' => $linkedVenta->id ?? null,
   ], JSON_UNESCAPED_UNICODE));

   if ($dryRun) {
      $this->warn('Dry run: no se aplicaron cambios.');
      $this->line(json_encode($cartUpdates, JSON_UNESCAPED_UNICODE));
      return self::SUCCESS;
   }

   DB::table('facturacion_carts')->where('id', (int) $cart->id)->update($cartUpdates);

   if ($linkedVenta) {
      DB::table('ventas')
         ->where('id', (int) $linkedVenta->id)
         ->update([
            'codigoOrden' => $cartUpdates['codigo_orden'],
            'updated_at' => now(),
         ]);
   }

   $this->info('Venta QR recuperada. Ya queda visible como pagada y pendiente de factura.');
   $this->line('Siguiente paso sugerido: reintentar la emision fiscal con los mismos datos del cart.');

   return self::SUCCESS;
})->purpose('Recupera una venta QR pagada para dejarla pendiente de factura sin perder el pago');
