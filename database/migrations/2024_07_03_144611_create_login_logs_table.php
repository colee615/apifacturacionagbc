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
      Schema::create('login_logs', function (Blueprint $table) {
         $table->id();
         $table->unsignedBigInteger('cajero_id');
         $table->string('ip_address');
         $table->string('user_agent');
         $table->timestamp('login_time');
         $table->timestamps();

         $table->foreign('cajero_id')->references('id')->on('cajeros')->onDelete('cascade');
      });
   }


   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('login_logs');
   }
};
