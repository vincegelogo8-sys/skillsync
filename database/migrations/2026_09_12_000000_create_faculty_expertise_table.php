<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_expertise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->string('expertise_area', 100);
            $table->unsignedTinyInteger('proficiency_score');
            $table->timestamps();
            $table->unique(['faculty_profile_id', 'expertise_area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_expertise');
    }
};
