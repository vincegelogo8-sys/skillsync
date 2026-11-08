<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public static function roles(): array
    {
        return [['student'], ['faculty'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_each_role_can_upload_and_see_their_own_photo(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])
            ->assertSessionHasNoErrors()->assertRedirect('/profile');

        $path = $user->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->actingAs($user)->get('/'.$role.'/dashboard')->assertOk()->assertSee('user-avatar-photo', false);
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Remove photo');
    }

    public function test_a_new_photo_replaces_and_deletes_the_old_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('a.png')]);
        $old = $user->fresh()->profile_photo_path;
        $this->actingAs($user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('b.webp')]);
        $new = $user->fresh()->profile_photo_path;

        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_photo_can_be_removed_and_initials_return(): void
    {
        $user = User::factory()->create(['role' => 'faculty']);
        $this->actingAs($user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('a.jpg')]);
        $path = $user->fresh()->profile_photo_path;

        $this->actingAs($user)->delete('/profile/photo')->assertRedirect('/profile');

        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($path);
        $this->actingAs($user)->get('/faculty/dashboard')->assertOk()->assertDontSee('user-avatar-photo', false)->assertSee('user-avatar-initials', false);
    }

    public function test_invalid_files_are_rejected(): void
    {
        $user = User::factory()->create();

        foreach ([
            UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('huge.jpg')->size(3000),
        ] as $file) {
            $this->actingAs($user)->post('/profile/photo', ['photo' => $file])->assertSessionHasErrors('photo');
        }
        $this->actingAs($user)->post('/profile/photo', [])->assertSessionHasErrors('photo');
        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_people_cannot_touch_each_others_photos_and_guests_are_redirected(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $this->actingAs($owner)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('a.jpg')]);
        $other = User::factory()->create(['role' => 'student']);

        $this->actingAs($other)->delete('/profile/photo');

        $this->assertNotNull($owner->fresh()->profile_photo_path);
        auth()->logout();
        $this->post('/profile/photo', ['photo' => UploadedFile::fake()->image('a.jpg')])->assertRedirect('/login');
    }
}