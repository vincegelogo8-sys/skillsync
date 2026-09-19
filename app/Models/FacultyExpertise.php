<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyExpertise extends Model
{
    protected $table = 'faculty_expertise';

    protected $fillable = ['expertise_area', 'proficiency_score'];

    protected function casts(): array
    {
        return ['proficiency_score' => 'integer'];
    }

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }
}
