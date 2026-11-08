<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">{{ __('Profile Photo') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('JPG, PNG or WebP, up to 2 MB. Without a photo, your initials are shown.') }}</p>
    </header>

    @if (session('status') === 'profile-photo-updated')
        <p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">{{ __('Profile photo updated.') }}</p>
    @elseif (session('status') === 'profile-photo-removed')
        <p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">{{ __('Profile photo removed.') }}</p>
    @endif

    <div class="profile-photo-row">
        <x-user-avatar />
        <div class="profile-photo-actions">
            <form method="POST" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="photo" :value="__('Choose a photo')" />
                    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required class="mt-1 block w-full" aria-describedby="photo_error" @if ($errors->has('photo')) aria-invalid="true" @endif>
                    <x-input-error id="photo_error" :messages="$errors->get('photo')" class="mt-2" />
                </div>
                <div class="flex flex-wrap items-center">
                    <x-primary-button>{{ __('Upload photo') }}</x-primary-button>
                </div>
            </form>
            @if ($user->profile_photo_path)
                <form method="POST" action="{{ route('profile.photo.destroy') }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-secondary">{{ __('Remove photo') }}</button>
                </form>
            @endif
        </div>
    </div>
</section>