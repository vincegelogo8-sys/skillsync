<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Edit Account') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <p class="text-sm text-gray-600">{{ __('Update this account name, email, or set a new password. Leave the password fields blank to keep the current password.') }}</p>
                    @php
                        $name = old('name');
                        $email = old('email');
                    @endphp

                    <form method="POST" action="{{ route('admin.accounts.update', $account) }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PATCH')

                        <div>
                            <x-input-label for="name" :value="__('Full Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required maxlength="255"
                                :value="is_string($name) ? $name : $account->name" autocomplete="name" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" required maxlength="255"
                                :value="is_string($email) ? $email : $account->email" autocomplete="email" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="account_type" :value="__('Account Type')" />
                            <p id="account_type" class="mt-1 text-sm text-gray-700">{{ __(ucfirst($account->role)) }}</p>
                        </div>

                        <div>
                            <x-input-label for="password" :value="__('New Password (optional)')" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" minlength="8" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password_confirmation" :value="__('Confirm New Password')" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" minlength="8" autocomplete="new-password" />
                        </div>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save Changes') }}</x-primary-button>
                            <a href="{{ route('admin.accounts.index') }}" class="button button-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
