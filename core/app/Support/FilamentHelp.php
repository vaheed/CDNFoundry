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

        // A sheet that opens by mouse focuses its first focusable element, often this label. Only a hover or a keyboard
        // focus (:focus-visible) opens the help, so it never pops up by itself when a form opens.
        return new HtmlString(
            '<span class="cursor-help" tabindex="0" x-tooltip="{ content: '
            .Js::from($help)->toHtml()
            .', theme: $store.theme, onTrigger(instance, event) { instance.cdnQuiet = event.type === \'focus\' && ! instance.reference.matches(\':focus-visible\') }, onShow(instance) { return ! instance.cdnQuiet } }">'
            .e($label)
            .'</span>'
        );
    }
}
