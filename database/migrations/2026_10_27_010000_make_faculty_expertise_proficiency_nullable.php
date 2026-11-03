<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculty_expertise', function (Blueprint $table) {
            $table->unsignedTinyInteger('proficiency_score')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('faculty_expertise')->whereNull('proficiency_score')->update(['proficiency_score' => 0]);

        Schema::table('faculty_expertise', function (Blueprint $table) {
            $table->unsignedTinyInteger('proficiency_score')->nullable(false)->change();
        });
    }
};
