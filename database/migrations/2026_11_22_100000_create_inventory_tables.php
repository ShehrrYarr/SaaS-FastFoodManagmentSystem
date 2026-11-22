<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory (M4). Stock quantities are decimal(14,4) in each ingredient's own
 * unit; recipes and purchases may be entered in any unit of the same family
 * (g for a kg ingredient) and are converted when saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('unit', 10); // kg g L ml pcs pair dozen
            $table->decimal('current_stock', 14, 4)->default(0);
            $table->decimal('low_stock_threshold', 14, 4)->nullable();
            $table->decimal('avg_cost', 14, 4)->default(0); // per unit, weighted average of purchases
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // What one unit of a menu item (a product variant) or an add-on option uses.
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->morphs('recipeable');
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4); // as entered
            $table->string('unit', 10);         // as entered
            $table->decimal('base_quantity', 14, 4); // in the ingredient's unit
            $table->timestamps();

            $table->unique(['recipeable_type', 'recipeable_id', 'ingredient_id'], 'recipe_items_unique_ingredient');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->string('voucher_number', 30);
            $table->unsignedBigInteger('sequence');
            $table->string('supplier_name', 120)->nullable();
            $table->date('purchased_on');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // null = unpaid
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->decimal('total', 14, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'purchased_on']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients');
            $table->decimal('quantity', 14, 4);
            $table->string('unit', 10);
            $table->decimal('base_quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4); // per entered unit
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        // The stock ledger: every change to an ingredient's stock, signed.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('type', 15); // opening purchase sale void_return adjustment wastage
            $table->decimal('quantity', 14, 4);
            $table->decimal('balance_after', 14, 4);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('business_day_id')->nullable()->constrained('business_days')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ingredient_id', 'id']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });

        // What one unit of a sent line used, so a void returns exactly that even if the recipe changed since.
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('stock_snapshot')->nullable()->after('combo_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('stock_snapshot');
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('ingredients');
    }
};
