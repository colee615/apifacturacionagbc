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
      Schema::create('servicios', function (Blueprint $table) {
         $table->id();
         $table->string('nombre', 250);
         $table->string('codigo', 50);
         $table->string('actividadEconomica', 50);
         $table->string('descripcion', 250);
         $table->decimal('precioUnitario', 10, 2);
         $table->integer('unidadMedida');
         $table->string('codigoSin', 15);
         $table->string('tipo', 50);
         $table->timestamps();
      });
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('servicios');
   }
};
