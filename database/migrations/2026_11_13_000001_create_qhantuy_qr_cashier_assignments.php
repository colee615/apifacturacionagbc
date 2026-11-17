<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qhantuy_qr_credentials', function (Blueprint $table) {
            $table->id();
            $table->char('appkey_fingerprint', 64)->unique();
            $table->text('encrypted_appkey');
            $table->timestamp('created_at');
        });

        Schema::create('qhantuy_qr_cashier_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('cashier_user_id', 60);
            $table->string('branch_code', 60);
            $table->string('point_sale_id', 60);
            $table->foreignId('credential_id')->constrained('qhantuy_qr_credentials')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['cashier_user_id', 'branch_code', 'point_sale_id'], 'qhantuy_qr_cashier_identity_unique');
        });

        Schema::table('qhantuy_qr_payments', function (Blueprint $table) {
            $table->foreignId('qhantuy_credential_id')->nullable()->index()->constrained('qhantuy_qr_credentials')->restrictOnDelete();
            $table->unsignedBigInteger('qhantuy_cart_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        $supportsDropForeign = Schema::getConnection()->getDriverName() !== 'sqlite';
        Schema::table('qhantuy_qr_payments', function (Blueprint $table) use ($supportsDropForeign) {
            if ($supportsDropForeign) {
                $table->dropForeign(['qhantuy_credential_id']);
            }

            $table->dropIndex(['qhantuy_credential_id']);
            $table->dropIndex(['qhantuy_cart_id']);
            $table->dropColumn(['qhantuy_credential_id', 'qhantuy_cart_id']);
        });

        Schema::dropIfExists('qhantuy_qr_cashier_assignments');
        Schema::dropIfExists('qhantuy_qr_credentials');
    }
};
