<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Student Profile') }}</h1>
    </x-slot>
    <x-slot name="description">{{ __('Keep your academic details current for your research proposal and adviser requests.') }}</x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Academic information') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Enter your current student details. All fields are required.') }}
                    </p>
                    <p class="mt-4 text-sm text-gray-700 break-words">{{ $user->name }} &middot; {{ $user->email }}</p>
                    <a href="{{ route('profile.edit') }}" class="text-sm text-indigo-600 underline">
                        {{ __('Edit your name, email, or password in Account Settings') }}
                    </a>

                    @if (session('status') === 'student-profile-updated')
                        <p role="status" class="ui-alert mt-4 text-sm font-medium text-green-700">
                            {{ __('Student profile saved successfully.') }}
                        </p>
                    @endif

                    <form method="POST" action="{{ route('student.profile.update') }}" class="mt-6 space-y-6">
                        @if (session('status') === 'complete-profile-for-proposal')
                            <p role="status" class="ui-alert text-sm text-amber-800">{{ __('Save your student profile before uploading a research proposal.') }}</p>
                        @endif
                        @csrf
                        @method('PATCH')

                        <div>
                            <x-input-label for="student_number" :value="__('Student Number')" />
                            <x-text-input id="student_number" name="student_number" type="text" class="mt-1 block w-full"
                                :value="old('student_number', $profile?->student_number)" maxlength="50" required
                                aria-describedby="student_number_error" :aria-invalid="$errors->has('student_number') ? 'true' : 'false'" />
                            <x-input-error id="student_number_error" :messages="$errors->get('student_number')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="course" :value="__('Course')" />
                            <x-text-input id="course" name="course" type="text" class="mt-1 block w-full"
                                :value="old('course', $profile?->course)" maxlength="150" required
                                aria-describedby="course_error" :aria-invalid="$errors->has('course') ? 'true' : 'false'" />
                            <x-input-error id="course_error" :messages="$errors->get('course')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="year_level" :value="__('Year Level')" />
                            <select id="year_level" name="year_level" required aria-describedby="year_level_error"
                                aria-invalid="{{ $errors->has('year_level') ? 'true' : 'false' }}"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">{{ __('Select year level') }}</option>
                                @foreach (range(1, 6) as $year)
                                    <option value="{{ $year }}" @selected((string) old('year_level', $profile?->year_level) === (string) $year)>
                                        {{ __('Year') }} {{ $year }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error id="year_level_error" :messages="$errors->get('year_level')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="section" :value="__('Section')" />
                            <x-text-input id="section" name="section" type="text" class="mt-1 block w-full"
                                :value="old('section', $profile?->section)" maxlength="50" required
                                aria-describedby="section_error" :aria-invalid="$errors->has('section') ? 'true' : 'false'" />
                            <x-input-error id="section_error" :messages="$errors->get('section')" class="mt-2" />
                        </div>

                        <x-primary-button>{{ __('Save Student Profile') }}</x-primary-button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
