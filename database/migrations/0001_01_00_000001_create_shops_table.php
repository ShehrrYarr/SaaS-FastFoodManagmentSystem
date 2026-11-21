<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('prefix', 20)->unique();
            $table->string('order_prefix', 10);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('currency', 10)->default('PKR');
            $table->string('currency_symbol', 10)->default('Rs');
            $table->string('timezone', 64)->default('Asia/Karachi');

            $table->boolean('is_active')->default(true);
            $table->string('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->date('subscription_ends_at')->nullable();

            $table->boolean('inventory_tracking_enabled')->default(false);
            $table->boolean('allow_negative_stock')->default(true);
            $table->string('service_charge_type', 10)->default('percent');
            $table->decimal('service_charge_value', 12, 2)->default(0);
            $table->decimal('default_delivery_charge', 12, 2)->default(0);
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();

            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'subscription_ends_at']);
        });

        Schema::create('shop_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('scope', 40)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_sequences');
        Schema::dropIfExists('shops');
    }
};
