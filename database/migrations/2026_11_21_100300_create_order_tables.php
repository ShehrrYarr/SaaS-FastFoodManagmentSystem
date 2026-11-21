<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One trading session: opened with a cash count, closed with a cash count.
        // A shop has at most one open day at a time (enforced under a lock in BusinessDays).
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 10)->default('open'); // open | closed

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash_expected', 14, 2)->default(0);
            $table->decimal('opening_cash_counted', 14, 2)->default(0);
            $table->string('opening_note')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_difference', 14, 2)->nullable();
            $table->string('closing_note')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'business_date']);
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->foreign('business_day_id')->references('id')->on('business_days')->nullOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('order_number', 30);
            $table->unsignedBigInteger('sequence');
            $table->unsignedSmallInteger('token_number');
            // The day the order was opened, and the day it was paid or cancelled (it counts there).
            $table->foreignId('business_day_id')->constrained('business_days');
            $table->foreignId('closed_business_day_id')->nullable()->constrained('business_days')->nullOnDelete();

            $table->string('type', 10); // dine_in | takeaway | delivery
            $table->string('status', 10)->default('open'); // open | completed | cancelled
            $table->string('payment_status', 10)->default('unpaid'); // unpaid | partial | paid
            $table->string('delivery_status', 10)->nullable(); // pending | out | delivered

            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('waiters')->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('waiters')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->text('customer_address')->nullable();
            $table->unsignedSmallInteger('guests')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('item_discount_total', 12, 2)->default(0);
            $table->string('discount_type', 10)->nullable(); // percent | fixed
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            // Copied from the shop when the order opens, so later setting changes don't alter open bills.
            $table->string('service_charge_type', 10)->nullable();
            $table->decimal('service_charge_value', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('change_total', 12, 2)->default(0);

            $table->string('notes', 500)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'status', 'type']);
            $table->index(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'opened_at']);
        });

        // Kitchen order tickets: every "send" prints the new lines; every void prints a VOID ticket.
        Schema::create('kots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('type', 10)->default('new'); // new | void
            $table->unsignedSmallInteger('printed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('kot_id')->nullable()->constrained('kots')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('name', 160);
            $table->string('variant_name', 60)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('modifiers_total', 12, 2)->default(0);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('voided_quantity')->default(0);
            $table->string('discount_type', 10)->nullable(); // percent | fixed
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->json('combo_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained('modifier_options')->nullOnDelete();
            $table->string('group_name', 80)->nullable();
            $table->string('name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_item_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('kot_id')->nullable()->constrained('kots')->nullOnDelete();
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'business_day_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->decimal('amount', 12, 2); // applied to the bill (what the account receives)
            $table->decimal('tendered', 12, 2)->nullable(); // cash handed over
            $table->decimal('change_given', 12, 2)->default(0);
            $table->string('reference', 80)->nullable(); // bank / wallet transaction id
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'business_day_id']);
            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_voids');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('kots');
        Schema::dropIfExists('orders');

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropForeign(['business_day_id']);
        });

        Schema::dropIfExists('business_days');
    }
};
