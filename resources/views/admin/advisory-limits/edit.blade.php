<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">{{ __('Edit Advisory Limit') }}</h1></x-slot>
    <x-slot name="description">{{ __('Set advisory capacity while keeping existing assignments in place.') }}</x-slot>
    <div class="py-12"><section class="mx-auto max-w-3xl bg-white p-6 shadow sm:rounded-lg">
        <a href="{{ route('admin.advisory-limits.index') }}" class=" button button-secondary">{{ __('Back to advisory limits') }}</a>
        <h3 class="mt-5 text-lg font-semibold text-gray-900 break-words">{{ $faculty->user->name }}</h3>
        <p class="mt-2 text-sm text-gray-700">{{ __('Advisory Load') }}: {{ $capacity['load'] }} / {{ $capacity['limit'] }} · <x-status-badge :status="$capacity['status']">{{ $capacity['status'] }}</x-status-badge></p>
        @if (session('status') === 'advisory-limit-updated')<p role="status" class="ui-alert mt-4 text-sm text-green-700">{{ __('Advisory limit updated successfully.') }}</p>@endif
        <form method="POST" action="{{ route('admin.advisory-limits.update', $faculty) }}" class="mt-6 space-y-4">
            @csrf @method('PATCH')
            <div>
                <x-input-label for="advisory_limit" :value="__('Advisory limit')" />
                <x-text-input id="advisory_limit" name="advisory_limit" type="number" min="0" max="65535" step="1" required :value="old('advisory_limit', $faculty->advisory_limit)" class="mt-1 block w-full" aria-describedby="limit-help" />
                <x-input-error :messages="$errors->get('advisory_limit')" class="mt-2" />
                <p id="limit-help" class="mt-2 text-sm text-gray-600">{{ __('Use a whole number from 0 to 65,535. Zero closes capacity. Lowering the limit below the active load keeps existing assignments and marks this faculty FULL.') }}</p>
            </div>
            <x-primary-button>{{ __('Save Advisory Limit') }}</x-primary-button>
        </form>
    </section></div>
</x-app-layout>
