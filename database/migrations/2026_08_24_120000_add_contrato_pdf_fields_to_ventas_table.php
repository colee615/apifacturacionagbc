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
            if (!Schema::hasColumn('ventas', 'contrato_pdf_path')) {
                $table->string('contrato_pdf_path', 255)->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_nombre')) {
                $table->string('contrato_pdf_nombre', 255)->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_mime')) {
                $table->string('contrato_pdf_mime', 120)->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_size')) {
                $table->unsignedBigInteger('contrato_pdf_size')->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_subido_at')) {
                $table->timestamp('contrato_pdf_subido_at')->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_subido_por_user_id')) {
                $table->unsignedBigInteger('contrato_pdf_subido_por_user_id')->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_subido_por_nombre')) {
                $table->string('contrato_pdf_subido_por_nombre', 255)->nullable();
            }
            if (!Schema::hasColumn('ventas', 'contrato_pdf_subido_por_email')) {
                $table->string('contrato_pdf_subido_por_email', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            foreach ([
                'contrato_pdf_subido_por_email',
                'contrato_pdf_subido_por_nombre',
                'contrato_pdf_subido_por_user_id',
                'contrato_pdf_subido_at',
                'contrato_pdf_size',
                'contrato_pdf_mime',
                'contrato_pdf_nombre',
                'contrato_pdf_path',
            ] as $column) {
                if (Schema::hasColumn('ventas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
