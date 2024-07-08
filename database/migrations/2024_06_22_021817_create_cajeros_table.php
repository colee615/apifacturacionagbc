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
      Schema::create('cajeros', function (Blueprint $table) {
         $table->id();
         $table->string('name');
         $table->string('email')->unique();
         $table->string('password');
         $table->integer('codigo_confirmacion')->nullable();
         $table->string('confirmation_token')->nullable(); // Campo para el token de confirmación
         $table->foreignId('sucursale_id')->nullable()->constrained('sucursales');
         $table->integer('estado')->default(1);
         $table->string('role');
         $table->timestamps();
      });
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('cajeros');
   }
};
