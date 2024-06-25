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
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->boolean('finalizado')->default(true); // Flag que indica la finalización del flujo de emisión
            $table->string('estado'); // Estado de la petición: EXITO, OBSERVADO
            $table->string('fuente'); // Origen de la notificación: SUFE, o PPE
            $table->string('codigo_seguimiento'); // Código único de seguimiento
            $table->string('fecha'); // Fecha de la notificación en formato "DD/MM/AAAA hh:mm:ss AM/PM"
            $table->string('mensaje'); // Mensaje de éxito o error de la emisión
            $table->json('detalle')->nullable(); // Objeto con detalles de la notificación
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
