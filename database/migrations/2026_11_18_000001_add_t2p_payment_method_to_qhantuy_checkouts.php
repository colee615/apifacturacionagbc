<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qhantuy_qr_payments')
            && ! Schema::hasColumn('qhantuy_qr_payments', 'payment_method')) {
            Schema::table('qhantuy_qr_payments', function (Blueprint $table): void {
                $table->string('payment_method', 20)->default('QRSIMPLE')->after('qhantuy_credential_id');
            });
        }

        if (Schema::hasTable('facturacion_carts')
            && ! Schema::hasColumn('facturacion_carts', 'qhantuy_payment_method')) {
            Schema::table('facturacion_carts', function (Blueprint $table): void {
                $table->string('qhantuy_payment_method', 20)->default('QRSIMPLE')->after('metodo_pago');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('facturacion_carts')
            && Schema::hasColumn('facturacion_carts', 'qhantuy_payment_method')) {
            Schema::table('facturacion_carts', function (Blueprint $table): void {
                $table->dropColumn('qhantuy_payment_method');
            });
        }

        if (Schema::hasTable('qhantuy_qr_payments')
            && Schema::hasColumn('qhantuy_qr_payments', 'payment_method')) {
            Schema::table('qhantuy_qr_payments', function (Blueprint $table): void {
                $table->dropColumn('payment_method');
            });
        }
    }
};
