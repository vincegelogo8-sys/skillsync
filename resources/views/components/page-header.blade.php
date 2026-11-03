@props(['description' => null])
<header class="workspace-page-header">
    <div id="pageTitle" class="page-title">{{ $slot }}</div>
    @if ($description)<p class="page-description">{{ $description }}</p>@endif
</header>
