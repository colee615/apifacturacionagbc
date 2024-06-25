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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('razonSocial');
            $table->string('documentoIdentidad');
            $table->string('complemento')->nullable(); // Permite que el campo sea nulo
            $table->unsignedTinyInteger('tipoDocumentoIdentidad');
            $table->string('correo')->unique();
            $table->string('codigoCliente')->unique();
            $table->integer('estado')->default(1);
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
