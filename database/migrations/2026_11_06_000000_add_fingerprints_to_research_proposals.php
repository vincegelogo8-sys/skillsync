<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_proposals', function (Blueprint $table) {
            $table->char('file_hash', 64)->nullable();
            $table->string('title_key', 255)->nullable();
            $table->unique(['student_profile_id', 'file_hash']);
            $table->unique(['student_profile_id', 'title_key']);
        });
    }

    public function down(): void
    {
        Schema::table('research_proposals', function (Blueprint $table) {
            $table->dropUnique(['student_profile_id', 'file_hash']);
            $table->dropUnique(['student_profile_id', 'title_key']);
            $table->dropColumn(['file_hash', 'title_key']);
        });
    }
};
