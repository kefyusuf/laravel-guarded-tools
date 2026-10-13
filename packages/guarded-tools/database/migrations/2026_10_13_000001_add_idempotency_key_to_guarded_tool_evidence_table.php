<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarded_tool_evidence', function (Blueprint $table): void {
            // Only successful approved writes set this key. Multiple NULLs keep failed calls retryable.
            $table->string('idempotency_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('guarded_tool_evidence', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
