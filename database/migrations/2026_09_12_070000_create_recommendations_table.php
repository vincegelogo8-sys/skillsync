<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            foreach (['cosine_similarity_score', 'multi_expertise_score', 'topic_alignment_score', 'advising_competency_score', 'preference_compatibility_score', 'skills_assessment_score', 'final_score'] as $field) {
                $table->decimal($field, 12, 8);
            }
            $table->unsignedInteger('rank');
            $table->json('details');
            $table->timestamps();
            $table->unique(['research_proposal_id', 'faculty_profile_id']);
            $table->index(['research_proposal_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
