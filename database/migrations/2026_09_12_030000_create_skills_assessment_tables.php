<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills_assessment_questions', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            foreach (['a', 'b', 'c', 'd'] as $option) {
                $table->string('option_'.$option, 500);
            }
            $table->enum('correct_answer', ['a', 'b', 'c', 'd']);
            $table->string('category', 100);
            $table->timestamps();
        });
        Schema::create('skills_assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('total_items');
            $table->decimal('percentage', 5, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('skills_assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('skills_assessment_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('skills_assessment_questions')->nullOnDelete();
            $table->unsignedTinyInteger('position');
            // Preserve exactly what was asked, even after question-bank edits.
            $table->text('question');
            foreach (['a', 'b', 'c', 'd'] as $option) {
                $table->string('option_'.$option, 500);
            }
            $table->enum('correct_answer', ['a', 'b', 'c', 'd']);
            $table->enum('selected_answer', ['a', 'b', 'c', 'd'])->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills_assessment_answers');
        Schema::dropIfExists('skills_assessment_attempts');
        Schema::dropIfExists('skills_assessment_questions');
    }
};
