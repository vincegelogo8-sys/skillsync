@props(['id', 'title', 'total' => 0, 'shown' => 0, 'fullUrl' => null, 'fullLabel' => null])
<dialog id="{{ $id }}" class="list-modal" aria-labelledby="{{ $id }}-title">
    <div class="list-modal-header">
        <h2 id="{{ $id }}-title">{{ $title }} <span class="list-modal-count">({{ $total }})</span></h2>
        <button type="button" class="button button-secondary" data-close-modal aria-label="{{ __('Close') }}">{{ __('Close') }}</button>
    </div>
    <div class="list-modal-body" tabindex="0">
        {{ $slot }}
    </div>
    @if ($total > $shown && $fullUrl)
        <div class="list-modal-footer">
            {{ __('Showing the latest :shown of :total.', ['shown' => $shown, 'total' => $total]) }}
            <a href="{{ $fullUrl }}" class="text-indigo-600 underline">{{ $fullLabel ?? __('Open the full page') }}</a>
        </div>
    @endif
</dialog>