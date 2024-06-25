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
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 50);
            $table->string('idEntidad', 50);
            $table->string('codigoSistema', 50);
            $table->string('razonSocial', 255);
            $table->text('token');
            $table->text('certificado');
            $table->string('password', 255);
            $table->string('urlNotificacion', 255);
            $table->string('urlLogo', 255)->nullable();
            $table->string('email')->unique();
            $table->string('password2');
            $table->string('confirmation_token')->nullable(); // Campo para el token de confirmación
            $table->string('two_factor_secret')->nullable(); // Campo para el secret de 2FA
            $table->integer('estado')->default(1); // Por defecto 1 (Activo)
            $table->timestamps();
         });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
