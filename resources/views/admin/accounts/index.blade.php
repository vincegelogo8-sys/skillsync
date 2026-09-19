<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Student and Faculty Accounts') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
                <a href="{{ route('admin.accounts.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md text-sm font-semibold text-white hover:bg-gray-700">
                    {{ __('Create Account') }}
                </a>

                @if (session('status'))
                    <p role="status" class="text-sm font-medium text-green-700">{{ session('status') }}</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('Student and Faculty accounts') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th scope="col" class="px-4 py-2">{{ __('Name') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('Email') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('Account Type') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('Created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($accounts as $account)
                                <tr class="border-b border-gray-100">
                                    <td class="px-4 py-2">{{ $account->name }}</td>
                                    <td class="px-4 py-2">{{ $account->email }}</td>
                                    <td class="px-4 py-2">{{ __(ucfirst($account->role)) }}</td>
                                    <td class="px-4 py-2">{{ $account->created_at?->format('M j, Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-2 text-gray-600">{{ __('No Student or Faculty accounts yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $accounts->links() }}
            </section>
        </div>
    </div>
</x-app-layout>
