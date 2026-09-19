<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_proposals', function (Blueprint $table) {
            $table->string('extraction_error', 500)->nullable();
        });
        Schema::create('proposal_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('extracted_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_analyses');
        Schema::table('research_proposals', function (Blueprint $table) {
            $table->dropColumn('extraction_error');
        });
    }
};
