<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve historical values while allowing RTA-only writes to omit these fields.
        Schema::table('recommendations', function (Blueprint $table) {
            foreach (['advising_competency_score', 'preference_compatibility_score', 'skills_assessment_score'] as $field) {
                $table->decimal($field, 12, 8)->nullable()->change();
            }
            $table->unsignedInteger('rank')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally retain the relaxed constraints: restoring NOT NULL would
        // require fabricating historical scores/ranks for new RTA-only records.
    }
};
