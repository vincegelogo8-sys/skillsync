<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Every signed-in person manages only their own profile photo.
 */
class ProfilePhotoController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
        ], [
            'photo.max' => __('The photo must be 2 MB or smaller.'),
            'photo.image' => __('Please choose a JPG, PNG or WebP image.'),
            'photo.mimes' => __('Please choose a JPG, PNG or WebP image.'),
        ]);

        $user = $request->user();
        $disk = config('filesystems.profile_photo_disk');
        $path = Storage::disk($disk)->putFile('profile-photos', $request->file('photo'), 'public');
        abort_if($path === false, 500, 'The photo could not be saved.');

        $old = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => $path])->save();
        if ($old) {
            Storage::disk($disk)->delete($old);
        }

        return redirect()->route('profile.edit')->with('status', 'profile-photo-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->profile_photo_path) {
            Storage::disk(config('filesystems.profile_photo_disk'))->delete($user->profile_photo_path);
            $user->forceFill(['profile_photo_path' => null])->save();
        }

        return redirect()->route('profile.edit')->with('status', 'profile-photo-removed');
    }
}