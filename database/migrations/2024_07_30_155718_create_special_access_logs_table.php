<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   /**
    * Run the migrations.
    */
   public function up()
   {
      Schema::create('special_access_logs', function (Blueprint $table) {
         $table->id();
         $table->unsignedBigInteger('usuario_id');
         $table->unsignedBigInteger('modified_by');
         $table->boolean('special_access');
         $table->text('motivo');
         $table->timestamps();

         $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('cascade');
         $table->foreign('modified_by')->references('id')->on('usuarios')->onDelete('cascade');
      });
   }
   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('special_access_logs');
   }
};
