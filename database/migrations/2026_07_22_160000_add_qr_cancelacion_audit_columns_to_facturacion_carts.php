<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelado_at')) {
                $table->timestamp('qr_cancelado_at')->nullable()->after('incidencia_revision_nota');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_user_id')) {
                $table->unsignedBigInteger('qr_cancelado_por_user_id')->nullable()->after('qr_cancelado_at');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_nombre')) {
                $table->string('qr_cancelado_por_nombre', 120)->nullable()->after('qr_cancelado_por_user_id');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_email')) {
                $table->string('qr_cancelado_por_email', 120)->nullable()->after('qr_cancelado_por_nombre');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelacion_motivo')) {
                $table->string('qr_cancelacion_motivo', 500)->nullable()->after('qr_cancelado_por_email');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelacion_origen')) {
                $table->string('qr_cancelacion_origen', 80)->nullable()->after('qr_cancelacion_motivo');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelacion_transaction_id')) {
                $table->string('qr_cancelacion_transaction_id', 120)->nullable()->after('qr_cancelacion_origen');
            }
            if (!Schema::hasColumn('facturacion_carts', 'qr_cancelacion_mensaje')) {
                $table->string('qr_cancelacion_mensaje', 500)->nullable()->after('qr_cancelacion_transaction_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            foreach ([
                'qr_cancelacion_mensaje',
                'qr_cancelacion_transaction_id',
                'qr_cancelacion_origen',
                'qr_cancelacion_motivo',
                'qr_cancelado_por_email',
                'qr_cancelado_por_nombre',
                'qr_cancelado_por_user_id',
                'qr_cancelado_at',
            ] as $column) {
                if (Schema::hasColumn('facturacion_carts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
