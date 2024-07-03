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
      Schema::table('cajeros', function (Blueprint $table) {
         $table->integer('codigo_confirmacion')->nullable()->after('email');
      });
   }

   /**
    * Reverse the migrations.
    *
    * @return void
    */
   public function down()
   {
      Schema::table('cajeros', function (Blueprint $table) {
         $table->dropColumn('codigo_confirmacion');
      });
   }
};
