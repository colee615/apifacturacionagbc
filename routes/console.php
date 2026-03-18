<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\Sucursale;
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

Artisan::command('app:reset-db-admin 
    {--admin-name=Administrador}
    {--admin-email=admin@agbc.local}
    {--admin-password=admin123}
    {--sucursal-nombre=Central}
    {--sucursal-municipio=Cochabamba}
    {--sucursal-departamento=Cochabamba}
    {--sucursal-codigo=0}
    {--sucursal-direccion=Principal}
    {--sucursal-telefono=00000000}', function () {
   $this->warn('Este comando eliminara todos los datos y recreara la base desde cero.');

   if (!$this->confirm('Deseas continuar?', true)) {
      $this->info('Operacion cancelada.');
      return self::SUCCESS;
   }

   $this->call('migrate:fresh', ['--force' => true]);

   $sucursal = new Sucursale();
   $sucursal->nombre = (string) $this->option('sucursal-nombre');
   $sucursal->municipio = (string) $this->option('sucursal-municipio');
   $sucursal->departamento = (string) $this->option('sucursal-departamento');
   $sucursal->codigosucursal = (int) $this->option('sucursal-codigo');
   $sucursal->direcccion = (string) $this->option('sucursal-direccion');
   $sucursal->telefono = (string) $this->option('sucursal-telefono');
   $sucursal->estado = 1;
   $sucursal->save();

   $usuario = Usuario::create([
      'name' => (string) $this->option('admin-name'),
      'email' => (string) $this->option('admin-email'),
      'password' => Hash::make((string) $this->option('admin-password')),
      'sucursale_id' => $sucursal->id,
      'estado' => 1,
   ]);

   $adminRole = Role::where('slug', 'admin')->first();

   if ($adminRole) {
      $usuario->roles()->sync([$adminRole->id]);
   }

   $this->newLine();
   $this->info('Base reiniciada correctamente.');
   $this->line('Sucursal creada: ' . $sucursal->nombre . ' (ID ' . $sucursal->id . ')');
   $this->line('Admin creado: ' . $usuario->email . ' (ID ' . $usuario->id . ')');
   $this->line('Password: ' . (string) $this->option('admin-password'));

   return self::SUCCESS;
})->purpose('Recrea la base de datos y deja un unico administrador inicial');
