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
         $table->string('motivo')->nullable();
         $table->decimal('total', 8, 2)->default(0);
         $table->decimal('pago', 8, 2)->default(0);
         $table->decimal('cambio', 8, 2)->default(0);
         $table->integer('tipo')->default(1);
         $table->integer('estado')->default(1);
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
