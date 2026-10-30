<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notificaciones_demo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_seguimiento')->index();
            $table->string('estado');
            $table->string('fuente');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_demo');
    }
};
