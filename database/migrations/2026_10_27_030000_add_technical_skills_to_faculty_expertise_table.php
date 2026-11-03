<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculty_expertise', function (Blueprint $table) {
            $table->text('technical_skills')->nullable()->after('expertise_area');
        });
    }

    public function down(): void
    {
        Schema::table('faculty_expertise', function (Blueprint $table) {
            $table->dropColumn('technical_skills');
        });
    }
};
