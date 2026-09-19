@props(['description' => null])
<header class="workspace-page-header">
    <p class="page-eyebrow">{{ __('Research Adviser Recommendation System') }}</p>
    <div id="pageTitle" class="page-title">{{ $slot }}</div>
    @if ($description)<p class="page-description">{{ $description }}</p>@endif
</header>
