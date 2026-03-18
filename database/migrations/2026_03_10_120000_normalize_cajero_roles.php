<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
   /**
    * Run the migrations.
    */
   public function up(): void
   {
      DB::table('cajeros')
         ->where('role', 'administrador')
         ->update(['role' => 'admin']);

      DB::table('cajeros')
         ->whereNull('role')
         ->update(['role' => 'cajero']);

      DB::table('cajeros')
         ->whereNotIn('role', ['admin', 'cajero'])
         ->update(['role' => 'cajero']);
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      // No reversible data migration.
   }
};
