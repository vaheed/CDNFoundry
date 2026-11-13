<?php

namespace App\Filament\Shared\Pages;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Support\Impersonation;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokens extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-key';

    protected static ?string $navigationLabel = 'API tokens';

    protected static ?string $title = 'API tokens';

    protected static ?string $slug = 'tokens';

    protected string $view = 'filament.shared.pages.api-tokens';

    public string $name = '';

    /** One of ApiToken::ABILITIES: `*` is full access. */
    public string $ability = '*';

    public int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS;

    /** @var list<int|string> */
    public array $domainIds = [];

    public ?string $plainTextToken = null;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'Governance' : 'Account';
    }

    public function getTokensProperty(): Collection
    {
        return auth()->user()->tokens()->latest('id')->limit(50)->get();
    }

    /** @return Collection<int, Domain> the domains this account may limit a token to */
    public function getDomainChoicesProperty(): Collection
    {
        return Domain::query()
            ->when(! auth()->user()->isAdmin(), fn ($query) => $query->whereHas('users', fn ($users) => $users->whereKey(auth()->id())))
            ->orderBy('name')->limit(200)->get(['id', 'name']);
    }

    public function createToken(): void
    {
        Impersonation::refuse('create an API token');
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'ability' => ['required', Rule::in(ApiToken::ABILITIES)],
            'lifetimeDays' => ['required', 'integer', 'min:1', 'max:'.ApiToken::MAX_LIFETIME_DAYS],
            'domainIds' => ['array', 'max:'.ApiToken::MAX_DOMAINS],
            'domainIds.*' => ['integer', Rule::in($this->domainChoices->pluck('id')->all())],
        ]);
        $created = DB::transaction(function (): NewAccessToken {
            $created = auth()->user()->createTokenWithinLimit($this->name, 'name', [$this->ability], now()->addDays($this->lifetimeDays), array_map('intval', $this->domainIds));
            AuditLog::record(auth()->user(), 'token.created', auth()->user(), ['token_id' => $created->accessToken->id], request()->ip());

            return $created;
        });
        $this->plainTextToken = $created->plainTextToken;
        $this->name = '';
        $this->domainIds = [];
        Notification::make()->success()->title('Token created')->body('Copy it now; it will not be shown again.')->send();
    }

    public function revokeToken(int $tokenId): void
    {
        /** @var PersonalAccessToken $token */
        $token = auth()->user()->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();
        AuditLog::record(auth()->user(), 'token.revoked', auth()->user(), ['token_id' => $tokenId], request()->ip());
        Notification::make()->success()->title('Token revoked')->send();
    }
}
