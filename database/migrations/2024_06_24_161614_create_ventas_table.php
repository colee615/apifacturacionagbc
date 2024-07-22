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
      Schema::create('ventas', function (Blueprint $table) {
         $table->id();
         $table->foreignId('cliente_id')->constrained('clientes');
         $table->foreignId('cajero_id')->constrained('cajeros');
         $table->integer('codigoSucursal')->nullable();
         $table->integer('puntoVenta')->nullable();
         $table->integer('documentoSector')->nullable();
         $table->string('municipio')->nullable();
         $table->string('departamento')->nullable();
         $table->string('telefono')->nullable();
         $table->integer('metodoPago')->nullable();
         $table->string('formatoFactura')->nullable();
         $table->decimal('monto_descuento_adicional', 10, 2)->default(0);
         $table->string('motivo')->nullable();
         $table->decimal('total', 8, 2)->default(0);
         $table->integer('estado')->default(1);
         $table->string('codigoSeguimiento');
         $table->timestamps();
      });
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('ventas');
   }
};
