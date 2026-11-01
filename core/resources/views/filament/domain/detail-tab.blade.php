@php($sections = \App\Filament\Domain\Resources\Domains\DomainDetailSections::for($getRecord(), $tab))
<div class="cdn-detail-grid" data-testid="domain-tab-{{ $tab }}">
    @foreach ($sections as $section)
        <x-ui.detail-section :title="$section['title']" :rows="$section['rows']" />
    @endforeach
</div>
