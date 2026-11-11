<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The default avatar, drawn here as an inline SVG. Filament's stock provider sends each user's initials to a
 * third-party service on every page, which the Content-Security-Policy refuses and which leaks who is signed in.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim((string) Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $word): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#111113"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Inter,system-ui,sans-serif" font-size="26" font-weight="600" fill="#fff">'
            .htmlspecialchars(mb_strtoupper($initials), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
