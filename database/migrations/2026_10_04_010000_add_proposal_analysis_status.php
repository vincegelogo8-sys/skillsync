<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->string('analysis_status', 20)->default('pending');
            $table->uuid('analysis_attempt_id')->nullable();
        });
        DB::table('proposal_analyses')->whereNotNull('analyzed_at')
            ->where('analysis_version', 'gpt-concepts-v1')
            ->whereNotNull('research_representation')->where('research_representation', '<>', '')
            ->update(['analysis_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->dropColumn(['analysis_status', 'analysis_attempt_id']);
        });
    }
};
