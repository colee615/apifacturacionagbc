<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   /**
    * Run the migrations.
    */
   public function up(): void
   {
      Schema::create('sucursales', function (Blueprint $table) {
         $table->id();
         $table->string('nombre');
         $table->string('municipio');
         $table->string('departamento');
         $table->integer('codigosucursal');
         $table->string('direcccion');
         $table->string('telefono');
         $table->integer('estado')->default(1);
         $table->timestamps();
      });
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('sucursales');
   }
};
