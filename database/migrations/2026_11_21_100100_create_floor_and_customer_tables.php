<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('area', 60)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // Waiters and delivery riders are name tags; they do not sign in.
        Schema::create('waiters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('phone', 30)->nullable();
            $table->string('type', 10)->default('waiter'); // waiter | rider
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->text('address')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('waiters');
        Schema::dropIfExists('dining_tables');
    }
};
