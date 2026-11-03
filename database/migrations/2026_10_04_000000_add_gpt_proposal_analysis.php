<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->string('primary_research_area', 120)->nullable();
            $table->text('research_representation')->nullable();
            $table->string('analysis_version', 40)->nullable();
        });
        Schema::table('recommendations', function (Blueprint $table) {
            $table->decimal('multi_expertise_score', 12, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->dropColumn(['primary_research_area', 'research_representation', 'analysis_version']);
        });
    }
};
