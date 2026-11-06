<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Create Account') }}</h1>
    </x-slot>


    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <p class="text-sm text-gray-600">
                        {{ __('Give the account holder their email and initial password after creating the account.') }}
                    </p>
                    @php
                        $name = old('name');
                        $email = old('email');
                        $selectedRole = old('role');
                    @endphp
                    <form method="POST" action="{{ route('admin.accounts.store') }}" class="mt-6 space-y-6">
                        @csrf
                        <div>
                            <x-input-label for="name" :value="__('Full Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required maxlength="255"
                                :value="is_string($name) ? $name : ''" autocomplete="off" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" required maxlength="255"
                                :value="is_string($email) ? $email : ''" autocomplete="off" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="role" :value="__('Account Type')" />
                            <select id="role" name="role" required data-account-role-select class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">{{ __('Select account type') }}</option>
                                @foreach ([\App\Models\User::ROLE_STUDENT, \App\Models\User::ROLE_FACULTY] as $role)
                                    <option value="{{ $role }}" @selected($selectedRole === $role)>{{ __(ucfirst($role)) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('role')" class="mt-2" />
                        </div>
                        <div data-account-role-group="student" @if ($selectedRole !== 'student') hidden @endif class="space-y-6">
                            <div>
                                <x-input-label for="student_number" :value="__('Student Number')" />
                                {{-- FIX: bound :required instead of @if inside the component tag --}}
                                <x-text-input id="student_number" name="student_number" type="text" class="mt-1 block w-full" maxlength="50"
                                    :value="old('student_number')" :required="$selectedRole === 'student'" data-role-required />
                                <x-input-error :messages="$errors->get('student_number')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="course" :value="__('Course')" />
                                <select id="course" name="course" @if ($selectedRole === 'student') required @endif data-role-required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">{{ __('Select course') }}</option>
                                    @foreach (config('student_options.courses') as $course)
                                        <option value="{{ $course }}" @selected(old('course') === $course)>{{ $course }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('course')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="year_level" :value="__('Year Level')" />
                                <select id="year_level" name="year_level" @if ($selectedRole === 'student') required @endif data-role-required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">{{ __('Select year level') }}</option>
                                    @foreach (config('student_options.year_levels') as $year => $label)
                                        <option value="{{ $year }}" @selected((string) old('year_level') === (string) $year)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('year_level')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="section" :value="__('Section')" />
                                <select id="section" name="section" @if ($selectedRole === 'student') required @endif data-role-required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">{{ __('Select section') }}</option>
                                    @foreach (config('student_options.sections') as $option)
                                        <option value="{{ $option }}" @selected(old('section') === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('section')" class="mt-2" />
                            </div>
                        </div>
                        <div data-account-role-group="faculty" @if ($selectedRole !== 'faculty') hidden @endif>
                            <x-input-label for="department" :value="__('Department')" />
                            {{-- FIX: bound :required instead of @if inside the component tag --}}
                            <x-text-input id="department" name="department" type="text" class="mt-1 block w-full" maxlength="150"
                                :value="old('department')" :required="$selectedRole === 'faculty'" data-role-required />
                            <x-input-error :messages="$errors->get('department')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password" :value="__('Initial Password')" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required minlength="8" autocomplete="new-password" />
                            <p class="mt-1 text-sm text-gray-600">{{ __('Use at least 8 characters. Give this password to the account holder.') }}</p>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password_confirmation" :value="__('Confirm Initial Password')" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required minlength="8" autocomplete="new-password" />
                        </div>
                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Create Account') }}</x-primary-button>
                            <a href="{{ route('admin.accounts.index') }}" class=" button button-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>