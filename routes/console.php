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
