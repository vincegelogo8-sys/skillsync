<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            foreach ([
                'cosine_similarity_score',
                'multi_expertise_score',
                'topic_alignment_score',
                'advising_competency_score',
                'preference_compatibility_score',
                'skills_assessment_score',
                'final_score',
            ] as $field) {
                $table->decimal($field, 12, 8)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Keep nullable score columns: matching-only recommendations have no scores.
    }
};
