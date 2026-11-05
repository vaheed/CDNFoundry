@php($columns = \App\Filament\Domain\Resources\Domains\DomainDetailSections::overview($getRecord()))
<div class="cdn-overview" data-testid="domain-overview">
    @foreach ($columns as $sections)
        <div class="cdn-overview-column">
            @foreach ($sections as $section)
                <x-ui.detail-section :title="$section['title']" :rows="$section['rows']" />
            @endforeach
        </div>
    @endforeach
</div>
