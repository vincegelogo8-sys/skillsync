<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Faculty Expertise') }}</h1>
    </x-slot>
    <x-slot name="description">{{ __('Review faculty profiles and manage their research expertise.') }}</x-slot>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <p class="text-sm text-gray-600">{{ __('Select a faculty member to manage research expertise and proficiency scores.') }}</p>
                <div class="mt-4 divide-y divide-gray-200">
                    @forelse ($faculty as $member)
                        <article class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <h3 class="font-medium text-gray-900 break-words">{{ $member->name }}</h3>
                                <p class="text-sm text-gray-600 break-words">{{ $member->email }}</p>
                                <p class="text-sm text-gray-600">{{ $member->facultyProfile?->department }}</p>
                            </div>
                            @if ($member->facultyProfile)
                                <a href="{{ route('admin.expertise.index', $member->facultyProfile) }}" class="text-sm text-indigo-600 underline"
                                    aria-label="{{ __('Manage expertise for :name', ['name' => $member->name]) }}">{{ __('Manage expertise') }}</a>
                            @else
                                <p class="text-sm text-amber-800">{{ __('Faculty must complete their basic profile first.') }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="py-4 text-sm text-gray-600">{{ __('No faculty accounts yet.') }} <a href="{{ route('admin.accounts.create') }}" class="text-indigo-600 underline">{{ __('Create an account') }}</a></p>
                    @endforelse
                </div>
                <div class="mt-4">{{ $faculty->links() }}</div>
            </section>
        </div>
    </div>
</x-app-layout>
