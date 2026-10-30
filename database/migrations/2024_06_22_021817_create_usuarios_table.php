<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
   {
      Schema::create('usuarios', function (Blueprint $table) {
         $table->id();
         $table->string('name');
         $table->string('email')->unique();
         $table->string('password');
         $table->integer('codigo_confirmacion')->nullable();
         $table->string('confirmation_token')->nullable();
         $table->foreignId('sucursale_id')->nullable()->constrained('sucursales');
         $table->integer('estado')->default(1);
         $table->timestamps();
      });
   }

   public function down(): void
   {
      Schema::dropIfExists('usuarios');
   }
};
