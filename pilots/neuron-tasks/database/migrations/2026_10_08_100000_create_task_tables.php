<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable();
            $table->string('role')->default('member');
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('title');
            $table->string('status')->default('open');
            $table->foreignId('assignee_id')->nullable();
            $table->date('due_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('companies');
    }
};
