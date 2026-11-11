<?php

namespace Tests\Feature;

use App\Filament\InitialsAvatarProvider;
use App\Models\User;
use Tests\TestCase;

class InitialsAvatarTest extends TestCase
{
    public function test_the_default_avatar_is_inline_and_names_no_external_service(): void
    {
        $avatar = (new InitialsAvatarProvider)->get(new User(['name' => '[SYSTEM] ada lovelace', 'email' => 'ada@example.test']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $svg = base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>SA<', $svg);
        $this->assertStringNotContainsString('http', str_replace('http://www.w3.org/2000/svg', '', $svg));
    }

    public function test_an_unusual_name_cannot_break_out_of_the_svg(): void
    {
        $svg = base64_decode(substr((new InitialsAvatarProvider)->get(new User(['name' => '<script>alert(1)</script> "x"'])), 26));

        $this->assertStringNotContainsString('<script', $svg);
    }
}
