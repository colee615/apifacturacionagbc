<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        DB::table('facturacion_carts')
            ->where('canal_emision', 'qr')
            ->update(['canal_emision' => 'factura_electronica']);
    }

    public function down(): void
    {
        // No se restaura el canal QR: facturacion queda solo como factura electronica.
    }
};
