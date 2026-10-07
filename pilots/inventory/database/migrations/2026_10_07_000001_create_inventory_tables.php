<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index();
            $table->string('sku');
            $table->string('name');
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index();
            $table->string('name');
        });
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id');
            $table->integer('quantity');
            $table->integer('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('stores');
    }
};
