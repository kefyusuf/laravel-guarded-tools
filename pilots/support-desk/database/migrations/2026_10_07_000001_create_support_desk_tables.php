<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::create('user_workspace', function (Blueprint $table) {
            $table->foreignId('workspace_id');
            $table->foreignId('user_id');
            $table->primary(['workspace_id', 'user_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_workspace_id')->nullable();
            $table->string('role')->default('agent');
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->index();
            $table->string('subject');
            $table->string('priority');
            $table->timestamp('opened_at');
            $table->timestamp('sla_due_at');
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('user_workspace');
        Schema::dropIfExists('workspaces');
    }
};
