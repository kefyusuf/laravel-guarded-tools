<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarded_tool_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tool');
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->string('source');
            $table->json('arguments');
            $table->json('result');
            $table->json('audit');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarded_tool_evidence');
    }
};
