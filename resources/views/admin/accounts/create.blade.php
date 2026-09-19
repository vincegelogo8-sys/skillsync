<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Create Account') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <p class="text-sm text-gray-600">
                        {{ __('Create a Student or Faculty account, then give the account holder their email and initial password. They can change their password in Account Settings.') }}
                    </p>
                    @php
                        $name = old('name');
                        $email = old('email');
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
                            <select id="role" name="role" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">{{ __('Select account type') }}</option>
                                @foreach ([\App\Models\User::ROLE_STUDENT, \App\Models\User::ROLE_FACULTY] as $role)
                                    <option value="{{ $role }}" @selected(old('role') === $role)>{{ __(ucfirst($role)) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('role')" class="mt-2" />
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
                            <a href="{{ route('admin.accounts.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
