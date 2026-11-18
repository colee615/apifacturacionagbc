<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturacion_entregas_manuales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_paquete', 35)->unique('facturacion_entregas_manuales_codigo_unique');
            $table->foreignId('venta_id')->nullable()->unique()
                ->constrained('ventas')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_entregas_manuales');
    }
};
