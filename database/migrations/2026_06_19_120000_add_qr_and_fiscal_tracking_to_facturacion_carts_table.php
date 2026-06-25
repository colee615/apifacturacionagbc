<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('facturacion_carts', 'qr_transaction_id')) {
                $table->string('qr_transaction_id', 80)->nullable()->after('codigo_orden');
                $table->index('qr_transaction_id');
            }

            if (!Schema::hasColumn('facturacion_carts', 'codigo_seguimiento_fiscal')) {
                $table->string('codigo_seguimiento_fiscal', 80)->nullable()->after('codigo_seguimiento');
                $table->index('codigo_seguimiento_fiscal');
            }
        });

        if (Schema::hasColumn('facturacion_carts', 'codigo_seguimiento')) {
            DB::table('facturacion_carts')
                ->where('canal_emision', 'qr')
                ->whereNull('qr_transaction_id')
                ->whereNotNull('codigo_seguimiento')
                ->update([
                    'qr_transaction_id' => DB::raw('codigo_seguimiento'),
                ]);

            DB::table('facturacion_carts')
                ->where(function ($query) {
                    $query->whereNull('canal_emision')
                        ->orWhere('canal_emision', '<>', 'qr');
                })
                ->whereNull('codigo_seguimiento_fiscal')
                ->whereNotNull('codigo_seguimiento')
                ->update([
                    'codigo_seguimiento_fiscal' => DB::raw('codigo_seguimiento'),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (Schema::hasColumn('facturacion_carts', 'codigo_seguimiento_fiscal')) {
                $table->dropIndex(['codigo_seguimiento_fiscal']);
                $table->dropColumn('codigo_seguimiento_fiscal');
            }

            if (Schema::hasColumn('facturacion_carts', 'qr_transaction_id')) {
                $table->dropIndex(['qr_transaction_id']);
                $table->dropColumn('qr_transaction_id');
            }
        });
    }
};
