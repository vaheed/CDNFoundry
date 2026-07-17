<?php

namespace App\Filament\Shared\Pages;

use App\Models\AuditLog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokens extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'API tokens';

    protected static ?string $slug = 'tokens';

    protected string $view = 'filament.shared.pages.api-tokens';

    public string $name = '';

    public ?string $plainTextToken = null;

    public function getTokensProperty(): Collection
    {
        return auth()->user()->tokens()->latest('id')->limit(50)->get();
    }

    public function createToken(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100']]);
        $created = auth()->user()->createToken($this->name);
        $created->accessToken->forceFill(['token_last_six' => substr($created->plainTextToken, -6)])->save();
        AuditLog::record(auth()->user(), 'token.created', auth()->user(), ['token_id' => $created->accessToken->id], request()->ip());
        $this->plainTextToken = $created->plainTextToken;
        $this->name = '';
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
