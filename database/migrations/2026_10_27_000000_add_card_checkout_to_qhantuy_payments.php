<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qhantuy_qr_payments', function (Blueprint $table) {
            // Preserve the existing table and QR integrations while adding hosted card checkout.
            $table->string('payment_method', 30)->default('QRSIMPLE');
            $table->text('payment_url')->nullable();
            $table->string('request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('qhantuy_qr_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_url', 'request_hash']);
        });
    }
};
