
@php
    $selectedArea = old('expertise_area', $expertise?->expertise_area);
@endphp

<div>
    <x-input-label for="expertise_area" :value="__('Faculty Skill Category')" />

    <select
        id="expertise_area"
        name="expertise_area"
        required
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        aria-describedby="expertise_area_error"
        aria-invalid="{{ $errors->has('expertise_area') ? 'true' : 'false' }}"
    >
        <option value="">{{ __('Select a faculty skill category') }}</option>

        @foreach ($areas as $area)
            <option value="{{ $area }}" @selected($selectedArea === $area)>
                {{ $area }}
            </option>
        @endforeach
    </select>

    <x-input-error
        id="expertise_area_error"
        :messages="$errors->get('expertise_area')"
        class="mt-2"
    />
</div>
