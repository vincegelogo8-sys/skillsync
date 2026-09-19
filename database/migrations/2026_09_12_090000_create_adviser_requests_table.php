<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adviser_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('research_proposal_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled']);
            // Pending/approved = 1; terminal requests = null, preserving request history.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['research_proposal_id', 'faculty_profile_id', 'active_slot'], 'adviser_requests_active_unique');
            $table->index(['student_profile_id', 'status']);
            $table->index(['faculty_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adviser_requests');
    }
};
