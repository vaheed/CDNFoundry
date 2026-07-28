<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

class FilamentHelp
{
    public static function label(string $label, ?string $help): string|HtmlString
    {
        if (blank($help)) {
            return $label;
        }

        return new HtmlString(
            '<span class="cursor-help" tabindex="0" x-tooltip="{ content: '
            .Js::from($help)->toHtml()
            .', theme: $store.theme }">'
            .e($label)
            .'</span>'
        );
    }
}
