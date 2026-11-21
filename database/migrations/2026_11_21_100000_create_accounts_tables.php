<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 10); // cash | bank | wallet
            $table->string('account_title', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->boolean('is_default_cash')->default(false);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->nullableMorphs('source');
            // Linked to business_days once day open/close lands (M3).
            $table->unsignedBigInteger('business_day_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('transacted_at');
            $table->timestamps();

            $table->index(['tenant_id', 'account_id', 'transacted_at']);
        });

        // Every existing shop gets its default Cash Drawer.
        $now = now();
        foreach (DB::table('shops')->pluck('id') as $shopId) {
            DB::table('accounts')->insert([
                'tenant_id' => $shopId,
                'name' => 'Cash Drawer',
                'type' => 'cash',
                'is_default_cash' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('accounts');
    }
};
