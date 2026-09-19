<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adviser_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->enum('status', ['active', 'completed', 'cancelled']);
            $table->timestamps();
            $table->index(['faculty_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adviser_assignments');
    }
};
