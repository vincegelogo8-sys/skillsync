<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Faculty Profile') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Basic information') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Keep your full name and department up to date. Both fields are required.') }}
                    </p>
                    <p class="mt-4 text-sm text-gray-700 break-words">{{ $user->email }}</p>
                    <a href="{{ route('profile.edit') }}" class="text-sm text-indigo-600 underline">
                        {{ __('Manage your email and password in Account Settings') }}
                    </a>

                    @if (session('status') === 'faculty-profile-updated')
                        <p role="status" class="mt-4 text-sm font-medium text-green-700">
                            {{ __('Faculty profile saved successfully.') }}
                        </p>
                    @endif

                    @if (session('status') === 'complete-profile-for-expertise')
                        <p role="status" class="mt-4 text-sm font-medium text-amber-800">
                            {{ __('Save your full name and department before adding research expertise.') }}
                        </p>
                    @endif

                    @if ($profile)
                        <p class="mt-4"><a href="{{ route('faculty.expertise.index') }}" class="text-sm text-indigo-600 underline">{{ __('Manage Research Expertise') }}</a></p>
                        <p class="mt-4"><a href="{{ route('faculty.preferences.index') }}" class="text-sm text-indigo-600 underline">{{ __('Manage Faculty Preferences') }}</a></p>
                    @endif

                    @if (session('status') === 'complete-profile-for-preferences')
                        <p role="status" class="mt-4 text-sm font-medium text-amber-800">
                            {{ __('Save your full name and department before selecting preferences.') }}
                        </p>
                    @endif

                    @php
                        $name = old('name', $user->name);
                        $department = old('department', $profile?->department);
                    @endphp

                    @if (session('status') === 'complete-profile-for-assessment')
                        <p role="status" class="mt-4 text-sm text-amber-800">{{ __('Save your full name and department before taking the skills assessment.') }}</p>
                    @endif

                    <form method="POST" action="{{ route('faculty.profile.update') }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PATCH')

                        <div>
                            <x-input-label for="name" :value="__('Full Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                :value="is_string($name) ? $name : ''" maxlength="255" autocomplete="name" required
                                aria-describedby="name_error" :aria-invalid="$errors->has('name') ? 'true' : 'false'" />
                            <x-input-error id="name_error" :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="department" :value="__('Department')" />
                            <x-text-input id="department" name="department" type="text" class="mt-1 block w-full"
                                :value="is_string($department) ? $department : ''" maxlength="150" required
                                aria-describedby="department_error" :aria-invalid="$errors->has('department') ? 'true' : 'false'" />
                            <x-input-error id="department_error" :messages="$errors->get('department')" class="mt-2" />
                        </div>

                        <x-primary-button>{{ __('Save Faculty Profile') }}</x-primary-button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
