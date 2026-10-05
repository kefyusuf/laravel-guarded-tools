<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('role')->default('viewer');
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('city');
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('status');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('placed_at');
            $table->timestamps();
            $table->unique(['team_id', 'number']);
            $table->index(['team_id', 'placed_at']);
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'number']);
            $table->index(['team_id', 'paid_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_team_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('teams');
    }
};
