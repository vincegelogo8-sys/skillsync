<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('system_analysis');
            $table->unsignedTinyInteger('research_methodology');
            $table->unsignedTinyInteger('research_documentation');
            $table->unsignedTinyInteger('data_analysis');
            $table->unsignedTinyInteger('system_evaluation');
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_competencies');
    }
};
