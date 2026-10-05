<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spike_events', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->index();
            $table->string('event');
            $table->json('payload');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_events');
    }
};
