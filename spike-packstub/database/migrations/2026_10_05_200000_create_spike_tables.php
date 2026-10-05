<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable();
            $table->json('permissions')->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('status');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('placed_at');
        });

        Schema::create('spike_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tool');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status');
            $table->json('result');
            $table->json('audit');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_evidence');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('teams');
    }
};
