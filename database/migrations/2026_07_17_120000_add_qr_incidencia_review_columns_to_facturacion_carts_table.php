<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('facturacion_carts', 'incidencia_revisada_at')) {
                $table->timestamp('incidencia_revisada_at')->nullable()->after('mensaje_emision');
            }
            if (!Schema::hasColumn('facturacion_carts', 'incidencia_revisada_por')) {
                $table->string('incidencia_revisada_por', 120)->nullable()->after('incidencia_revisada_at');
            }
            if (!Schema::hasColumn('facturacion_carts', 'incidencia_revision_nota')) {
                $table->string('incidencia_revision_nota', 500)->nullable()->after('incidencia_revisada_por');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facturacion_carts', function (Blueprint $table) {
            foreach ([
                'incidencia_revision_nota',
                'incidencia_revisada_por',
                'incidencia_revisada_at',
            ] as $column) {
                if (Schema::hasColumn('facturacion_carts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
