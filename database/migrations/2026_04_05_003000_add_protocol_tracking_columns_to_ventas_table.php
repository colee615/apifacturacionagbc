<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'estado_sufe')) {
                $table->string('estado_sufe', 50)->nullable()->after('codigoOrden');
            }
            if (!Schema::hasColumn('ventas', 'tipo_emision_sufe')) {
                $table->string('tipo_emision_sufe', 50)->nullable()->after('estado_sufe');
            }
            if (!Schema::hasColumn('ventas', 'cuf')) {
                $table->string('cuf', 255)->nullable()->after('tipo_emision_sufe');
            }
            if (!Schema::hasColumn('ventas', 'url_pdf')) {
                $table->text('url_pdf')->nullable()->after('cuf');
            }
            if (!Schema::hasColumn('ventas', 'url_xml')) {
                $table->text('url_xml')->nullable()->after('url_pdf');
            }
            if (!Schema::hasColumn('ventas', 'observacion_sufe')) {
                $table->text('observacion_sufe')->nullable()->after('url_xml');
            }
            if (!Schema::hasColumn('ventas', 'fecha_notificacion_sufe')) {
                $table->string('fecha_notificacion_sufe', 40)->nullable()->after('observacion_sufe');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            $columns = [
                'estado_sufe',
                'tipo_emision_sufe',
                'cuf',
                'url_pdf',
                'url_xml',
                'observacion_sufe',
                'fecha_notificacion_sufe',
            ];

            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('ventas', $column)));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
