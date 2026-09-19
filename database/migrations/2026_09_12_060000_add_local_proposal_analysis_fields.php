<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->text('abstract')->nullable();
            $table->json('keywords')->nullable();
            $table->string('project_type')->nullable();
            $table->json('technologies')->nullable();
            $table->json('identified_expertise_areas')->nullable();
            $table->timestamp('analyzed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_analyses', function (Blueprint $table) {
            $table->dropColumn(['abstract', 'keywords', 'project_type', 'technologies', 'identified_expertise_areas', 'analyzed_at']);
        });
    }
};
