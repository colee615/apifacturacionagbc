<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

// Continúa desde el último carrito revisado para atender también las órdenes nuevas.
$reviewPendingCarts = static function ($query, string $key, callable $review): array {
   $last = (int) Cache::get($key.':ultimo', 0);
   $carts = (clone $query)->where('id', '>', $last)->orderBy('id')->limit(10)->get(['id']);
   if ($carts->isEmpty() && $last > 0) {
      Cache::forget($key.':ultimo');
      $carts = $query->orderBy('id')->limit(10)->get(['id']);
   }
   $attempted = $resolved = 0;
   foreach ($carts as $cart) {
      Cache::put($key.':ultimo', (int) $cart->id, 3600);
      if (!Cache::add($key.':'.$cart->id, true, 300)) {
         continue;
      }
      $attempted++;
      $resolved += (int) $review((int) $cart->id);
   }

   return [$attempted, $resolved];
};

Artisan::command('facturas:recuperar-inciertas', function () use ($reviewPendingCarts) {
   $controller = app(\App\Http\Controllers\FacturacionCartIntegrationController::class);
   [$attempted, $recovered] = $reviewPendingCarts(
      $controller::recoverableFiscalCarts(), 'factura-recuperacion-programada', $controller->recoverUncertainFiscalEmission(...),
   );
   $this->info("Consultadas: {$attempted}. Recuperadas: {$recovered}.");
})->purpose('Consulta órdenes inciertas en SEFE y vincula las facturas confirmadas');

Artisan::command('facturas:consolidar-tarjetas', function () use ($reviewPendingCarts) {
   $controller = app(\App\Http\Controllers\FacturacionCartIntegrationController::class);
   [$attempted, $settled] = $reviewPendingCarts(
      $controller::unsettledCardCarts(), 'factura-tarjeta-programada', $controller->settleCardCart(...),
   );
   $this->info("Cobros revisados: {$attempted}. Cobros enviados a facturación: {$settled}.");
})->purpose('Verifica cobros de tarjeta y factura los pagos confirmados');

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

   return DB::transaction(function () use ($cartId, $internalCode, $transactionId, $dryRun) {
      $matches = DB::table('facturacion_carts as c')->join('qhantuy_qr_payments as p', 'p.internal_code', '=', 'c.codigo_orden')
         ->where('c.metodo_pago', 'qr')->where('p.transaction_id', '>', 0)
         ->where('p.checkout_currency', 'BOB')->whereColumn('p.checkout_amount', 'c.total')
         ->where(function ($owner) {
            $owner->whereColumn('p.qhantuy_cart_id', 'c.id')->orWhere(function ($legacy) {
               $legacy->whereNull('p.qhantuy_cart_id')->whereRaw('c.qr_transaction_id = cast(p.transaction_id as varchar)');
            });
         })
         ->when($cartId > 0, fn ($query) => $query->where('c.id', $cartId))
         ->when($internalCode !== '', fn ($query) => $query->where('p.internal_code', $internalCode))
         ->when($transactionId > 0, fn ($query) => $query->where('p.transaction_id', $transactionId))
         ->limit(2)->get(['c.id', 'p.id as payment_id']);
      if ($matches->count() !== 1) {
         $this->error('Los filtros deben identificar un único carrito y su mismo cobro.');
         return self::FAILURE;
      }

      $match = $matches->first();
      $cart = DB::table('facturacion_carts')->where('id', $match->id)->lockForUpdate()->first();
      $payment = DB::table('qhantuy_qr_payments')->where('id', $match->payment_id)->lockForUpdate()->first();
      if (!$cart || !$payment || $cart->codigo_orden !== $payment->internal_code
         || $cart->metodo_pago !== 'qr' || $payment->payment_status !== 'success'
         || ($payment->qhantuy_cart_id !== null && (int) $payment->qhantuy_cart_id !== (int) $cart->id)
         || ($payment->qhantuy_cart_id === null && blank($cart->qr_transaction_id))
         || (filled($cart->qr_transaction_id) && (int) $cart->qr_transaction_id !== (int) $payment->transaction_id)
         || $payment->checkout_currency !== 'BOB' || (float) $cart->total <= 0
         || round((float) $cart->total, 2) !== round((float) $payment->checkout_amount, 2)
         || in_array($cart->estado_emision, ['EMITIENDO', 'EMISION_INCIERTA', 'PENDIENTE', 'FACTURADA'], true)) {
         $this->error('El pago o el estado fiscal requiere verificación antes de modificar el carrito.');
         return self::FAILURE;
      }

      if (!$dryRun) {
         DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'qr_transaction_id' => (string) $payment->transaction_id,
            'estado_pago' => 'pagado',
            'estado' => 'emitido',
            'mensaje_emision' => 'Pago confirmado vinculado a la misma orden.',
            'emitido_en' => $cart->emitido_en ?? now(),
            'cerrado_en' => $cart->cerrado_en ?? now(),
            'updated_at' => now(),
         ]);
      }

      $this->info($dryRun ? 'Coincidencia verificada. Simulación.' : 'Pago vinculado a su orden original.');
      return self::SUCCESS;
   });
})->purpose('Recupera una venta QR pagada para dejarla pendiente de factura sin perder el pago');
