<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarded_tool_evidence', function (Blueprint $table): void {
            // The provider's tool call id: a write runs at most once per call (idempotency).
            $table->string('tool_call_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('guarded_tool_evidence', function (Blueprint $table): void {
            $table->dropIndex(['tool_call_id']);
            $table->dropColumn('tool_call_id');
        });
    }
};
