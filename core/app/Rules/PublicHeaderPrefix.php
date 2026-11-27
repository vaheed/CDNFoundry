<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The prefix of the public response headers (`cdnf-` gives `cdnf-ray`). It is one or more lowercase words joined by hyphens and
 * ends in a hyphen, so it can never be a whole header name, and it must not start a name the platform, the protocol or a browser
 * already owns. The internal control namespace is fixed and is never offered here.
 */
final class PublicHeaderPrefix implements ValidationRule
{
    public const MAXIMUM_LENGTH = 24;

    /** The first words of names that belong to HTTP, caches, security policy, authentication, cookies, hop-by-hop handling or the platform. */
    private const RESERVED_FIRST_WORDS = [
        'x', 'accept', 'access', 'age', 'allow', 'authorization', 'cache', 'cdn', 'cdnf', 'connection', 'content', 'cookie', 'cross', 'date',
        'etag', 'expect', 'expires', 'forwarded', 'host', 'if', 'keep', 'last', 'location', 'origin', 'pragma', 'proxy', 'range', 'referer',
        'referrer', 'refresh', 'retry', 'sec', 'server', 'set', 'strict', 'te', 'trailer', 'transfer', 'upgrade', 'user', 'vary', 'via',
        'warning', 'www', 'alt', 'link', 'public', 'report', 'timing', 'permissions', 'nel', 'priority', 'early', 'clear', 'digest',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > self::MAXIMUM_LENGTH || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*-$/', $value) !== 1) {
            $fail('The prefix must be lowercase words joined by hyphens and end in a hyphen, for example edge-, with at most '.self::MAXIMUM_LENGTH.' characters.');

            return;
        }
        $first = explode('-', $value, 2)[0];
        // The platform's own name, cdnf, is the default and stays allowed; everything else on the list is owned by someone else.
        if ($value !== 'cdnf-' && in_array($first, self::RESERVED_FIRST_WORDS, true)) {
            $fail('The prefix would collide with a standard, security, forwarding or platform header. Choose a name of your own, for example edge-.');
        }
    }
}
