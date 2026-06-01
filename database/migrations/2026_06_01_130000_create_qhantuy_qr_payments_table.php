<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qhantuy_qr_payments')) {
            return;
        }

        Schema::create('qhantuy_qr_payments', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 120)->unique();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->decimal('checkout_amount', 12, 2)->default(0);
            $table->string('checkout_currency', 10)->default('BOB');
            $table->string('customer_email', 120)->nullable();
            $table->string('customer_first_name', 120)->nullable();
            $table->string('customer_last_name', 120)->nullable();
            $table->string('detail', 500)->nullable();
            $table->text('image_data')->nullable();
            $table->string('payment_status', 40)->default('holding')->index();
            $table->json('raw_checkout_response')->nullable();
            $table->json('raw_check_response')->nullable();
            $table->json('raw_callback_params')->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qhantuy_qr_payments');
    }
};
