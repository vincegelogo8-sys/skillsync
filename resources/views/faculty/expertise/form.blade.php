@php
    $selectedArea = old('expertise_area', $expertise?->expertise_area);
    $score = old('proficiency_score', $expertise?->proficiency_score);
@endphp

<div>
    <x-input-label for="expertise_area" :value="__('Expertise Area')" />
    <select id="expertise_area" name="expertise_area" required
        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        aria-describedby="expertise_area_error" aria-invalid="{{ $errors->has('expertise_area') ? 'true' : 'false' }}">
        <option value="">{{ __('Select an expertise area') }}</option>
        @foreach ($areas as $area)
            <option value="{{ $area }}" @selected($selectedArea === $area)>{{ $area }}</option>
        @endforeach
    </select>
    <x-input-error id="expertise_area_error" :messages="$errors->get('expertise_area')" class="mt-2" />
</div>
<div>
    <x-input-label for="proficiency_score" :value="__('Proficiency Score (0–100)')" />
    <x-text-input id="proficiency_score" name="proficiency_score" type="number" min="0" max="100" step="1" required
        class="mt-1 block w-full" :value="is_scalar($score) ? $score : ''"
        aria-describedby="score_help proficiency_score_error" :aria-invalid="$errors->has('proficiency_score') ? 'true' : 'false'" />
    <p id="score_help" class="mt-2 text-sm text-gray-600">{{ __('Enter a whole number from 0 to 100 for proficiency in this area.') }}</p>
    <x-input-error id="proficiency_score_error" :messages="$errors->get('proficiency_score')" class="mt-2" />
</div>
