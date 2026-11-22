<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('voucher_number', 40);
            $table->unsignedInteger('sequence');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->string('payee', 120)->nullable();
            $table->string('note', 255)->nullable();
            // The day that was open when it was paid (cash drawer reconciliation).
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Expenses are never deleted: a void posts the money back and keeps the record.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'spent_on']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('voucher_number', 40);
            $table->unsignedInteger('sequence');
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
        });

        // Existing shops get the default expense categories.
        $now = now();
        foreach (DB::table('shops')->pluck('id') as $shopId) {
            foreach (ExpenseCategory::DEFAULTS as $i => $name) {
                DB::table('expense_categories')->insert([
                    'tenant_id' => $shopId,
                    'name' => $name,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
