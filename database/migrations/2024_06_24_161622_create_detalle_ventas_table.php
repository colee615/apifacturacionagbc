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
      Schema::create('detalle_ventas', function (Blueprint $table) {
         $table->id();
         $table->foreignId('venta_id')->constrained('ventas')->onDelete('cascade');
         $table->string('actividadEconomica', 20)->nullable();
         $table->string('codigoSin', 20)->nullable();
         $table->string('codigo', 50)->nullable();
         $table->string('descripcion', 500)->nullable();
         $table->integer('unidadMedida')->nullable();
         $table->decimal('precio', 8, 2)->default(0);
         $table->decimal('cantidad', 8, 2)->default(0);
         $table->integer('estado')->default(1);
         $table->timestamps();
      });
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('detalle_ventas');
   }
};
