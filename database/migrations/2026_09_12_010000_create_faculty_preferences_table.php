<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('preference_type', ['project_type', 'technology']);
            $table->string('preference_value', 100);
            $table->timestamps();
            $table->unique(['faculty_profile_id', 'preference_type', 'preference_value'], 'faculty_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_preferences');
    }
};
