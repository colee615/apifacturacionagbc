<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
