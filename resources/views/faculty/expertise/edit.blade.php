<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Edit Research Expertise') }}</h1>
    </x-slot>
    <x-slot name="description">{{ __('Update the expertise area and its recorded proficiency.') }}</x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-900">{{ $profile->user->name }}</h3>
                    <form method="POST" action="{{ route($routePrefix.'update', $routeParameters + ['expertise' => $expertise]) }}" class="mt-6 space-y-6">
                        @csrf
                        @method('PATCH')
                        @include('faculty.expertise.form')
                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save Expertise') }}</x-primary-button>
                            <a href="{{ route($routePrefix.'index', $routeParameters) }}" class=" button button-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
