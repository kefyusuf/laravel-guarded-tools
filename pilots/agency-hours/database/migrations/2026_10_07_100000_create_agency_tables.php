<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable();
            $table->string('role')->default('member');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index();
            $table->string('name');
            $table->decimal('budget_hours', 8, 2);
        });
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->index();
            $table->foreignId('project_id');
            $table->foreignId('user_id');
            $table->decimal('hours', 6, 2);
            $table->date('spent_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('organizations');
    }
};
