<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_STUDENT = 'student';

    public const ROLE_FACULTY = 'faculty';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_STUDENT, self::ROLE_FACULTY, self::ROLE_ADMIN];

    // Only account provisioning assigns roles; ordinary profile updates cannot change them.
    protected $attributes = [
        'role' => self::ROLE_STUDENT,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
        ];
    }

    /** Public URL of the profile photo, or null so the avatar falls back to initials. */
    public function profilePhotoUrl(): ?string
    {
        if (! $this->profile_photo_path) {
            return null;
        }

        try {
            $disk = config('filesystems.profile_photo_disk');

            // The local public disk is served from /storage on whatever address the site is opened on,
            // so it keeps working even when APP_URL does not match (for example XAMPP or a new domain).
            return $disk === 'public'
                ? asset('storage/'.ltrim($this->profile_photo_path, '/'))
                : \Illuminate\Support\Facades\Storage::disk($disk)->url($this->profile_photo_path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function scopeDeactivated(Builder $query): Builder
    {
        return $query->whereNotNull('deactivated_at');
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function facultyProfile(): HasOne
    {
        return $this->hasOne(FacultyProfile::class);
    }
}