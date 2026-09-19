<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyPreference extends Model
{
    public const TYPE_PROJECT = 'project_type';

    public const TYPE_TECHNOLOGY = 'technology';

    protected $fillable = ['preference_type', 'preference_value'];

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }
}
