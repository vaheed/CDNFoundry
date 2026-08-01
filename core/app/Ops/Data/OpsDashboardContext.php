<?php

namespace App\Ops\Data;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class OpsDashboardContext
{
    /** @var array<string, int> */
    public const RANGES = [
        '1h' => 3600,
        '6h' => 21600,
        '24h' => 86400,
        '7d' => 604800,
    ];

    /** @param array<int, string> $errors */
    public function __construct(
        public string $range,
        public bool $compare,
        public ?int $domainId,
        public ?string $edgeId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $errors = [],
    ) {}

    /** @param array<string, mixed>|null $filters */
    public static function fromFilters(?array $filters, ?User $user = null): self
    {
        $filters ??= [];
        $errors = [];
        $range = is_string($filters['range'] ?? null) && isset(self::RANGES[$filters['range']])
            ? $filters['range']
            : '24h';
        if (isset($filters['range']) && $filters['range'] !== $range) {
            $errors[] = 'The selected time range is invalid.';
        }

        $compare = filter_var($filters['compare'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $domainId = null;
        $requestedDomain = $filters['domain_id'] ?? null;
        if (filled($requestedDomain)) {
            if (! ctype_digit((string) $requestedDomain) || (int) $requestedDomain < 1 || ! Domain::query()->whereKey((int) $requestedDomain)->exists()) {
                $errors[] = 'The selected domain is unavailable.';
            } else {
                $domainId = (int) $requestedDomain;
            }
        }

        $edgeId = null;
        $requestedEdge = $filters['edge_id'] ?? null;
        if (filled($requestedEdge)) {
            if (! is_string($requestedEdge) || ! Str::isUuid($requestedEdge) || ! Edge::query()->whereKey($requestedEdge)->exists()) {
                $errors[] = 'The selected edge is unavailable.';
            } else {
                $edgeId = $requestedEdge;
            }
        }

        if ($user?->isAdmin() !== true) {
            $errors[] = 'Administrator access is required for global operations data.';
        }

        // The overview intentionally uses hourly aggregate tables instead of
        // scanning raw events. Compare only complete, equivalent buckets.
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $seconds = max(self::RANGES[$range], 3600);

        return new self(
            range: $range,
            compare: $compare,
            domainId: $domainId,
            edgeId: $edgeId,
            from: $to->subSeconds($seconds),
            to: $to,
            errors: array_values(array_unique($errors)),
        );
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, raw: false} */
    public function rangeValue(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'raw' => false];
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, raw: false} */
    public function comparisonRange(): array
    {
        $seconds = $this->from->diffInSeconds($this->to);

        return ['from' => $this->from->subSeconds($seconds), 'to' => $this->from, 'raw' => false];
    }

    public function cacheKey(): string
    {
        return implode(':', [
            'admin', $this->range, $this->compare ? 'compare' : 'current',
            'domain-'.($this->domainId ?? 'all'), 'edge-'.($this->edgeId ?? 'all'),
        ]);
    }
}
