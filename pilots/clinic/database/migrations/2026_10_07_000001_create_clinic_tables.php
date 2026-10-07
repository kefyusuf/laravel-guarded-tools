<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable();
            $table->string('role')->default('receptionist');
        });
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->index();
            $table->string('name');
            $table->unsignedSmallInteger('birth_year');
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->index();
            $table->foreignId('patient_id');
            $table->timestamp('starts_at');
            $table->string('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('clinics');
    }
};
