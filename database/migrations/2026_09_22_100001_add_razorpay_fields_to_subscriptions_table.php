<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly')->after('subscription_plan_id');
            $table->string('razorpay_order_id')->nullable()->index()->after('stripe_id');
            $table->string('razorpay_payment_id')->nullable()->index()->after('razorpay_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'razorpay_order_id', 'razorpay_payment_id']);
        });
    }
};
