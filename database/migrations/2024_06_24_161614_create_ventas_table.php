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
         $table->string('origen_sistema')->nullable();
         $table->string('origen_usuario_id')->nullable();
         $table->string('origen_usuario_nombre')->nullable();
         $table->string('origen_usuario_email')->nullable();
         $table->string('origen_sucursal_id')->nullable();
         $table->string('origen_sucursal_codigo')->nullable();
         $table->string('origen_sucursal_nombre')->nullable();
         $table->integer('codigoSucursal')->nullable();
         $table->integer('puntoVenta')->nullable();
         $table->integer('documentoSector')->nullable();
         $table->string('municipio')->nullable();
         $table->string('departamento')->nullable();
         $table->string('telefono')->nullable();
         $table->string('codigoCliente')->nullable();
         $table->string('razonSocial', 500)->nullable();
         $table->string('documentoIdentidad', 20)->nullable();
         $table->integer('tipoDocumentoIdentidad')->nullable();
         $table->string('complemento', 20)->nullable();
         $table->string('correo', 120)->nullable();
         $table->integer('metodoPago')->nullable();
         $table->string('formatoFactura')->nullable();
         $table->decimal('monto_descuento_adicional', 10, 2)->default(0);
         $table->string('motivo')->nullable();
         $table->decimal('total', 8, 2)->default(0);
         $table->integer('estado')->default(1);
         $table->string('codigoSeguimiento');
         $table->string('codigoOrden')->nullable();
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
