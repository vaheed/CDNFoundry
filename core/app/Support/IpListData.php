<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\IpList;
use Illuminate\Validation\ValidationException;

/**
 * Named IP lists: validation, canonical form, and the compiled form cells read.
 *
 * Entries are IPv4 or IPv6 addresses or CIDR networks, stored as canonical CIDR strings with the host bits cleared. A cell
 * never parses them: the control plane compiles a list into sorted, non-overlapping ranges (IPv4 as whole numbers, IPv6 as four
 * 32-bit words per address, first then last), which the cell searches in logarithmic time.
 */
final class IpListData
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    public static function maximumEntries(): int
    {
        return (int) config('security.ip_lists.maximum_entries');
    }

    public static function validName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * One entry in canonical form: an address becomes a host network, host bits of a network are cleared, IPv4-mapped IPv6
     * addresses are IPv4, and the text is lower case.
     */
    public static function canonical(mixed $entry): ?string
    {
        if (! is_string($entry)) {
            return null;
        }
        $text = trim($entry);
        $prefix = null;
        if (str_contains($text, '/')) {
            [$text, $length] = explode('/', $text, 2);
            if (! ctype_digit($length)) {
                return null;
            }
            $prefix = (int) $length;
        }
        $packed = @inet_pton($text);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
            $prefix = $prefix === null ? null : max(0, $prefix - 96);
        }
        $bits = strlen($packed) * 8;
        $prefix ??= $bits;
        if ($prefix > $bits) {
            return null;
        }

        return inet_ntop(self::networkStart($packed, $prefix)).'/'.$prefix;
    }

    /**
     * @return list<string> canonical, unique, sorted entries
     */
    public static function normalize(mixed $entries, string $path = 'entries'): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw ValidationException::withMessages([$path => 'The entries must be a list.']);
        }
        if (count($entries) > self::maximumEntries()) {
            throw ValidationException::withMessages([$path => 'A list holds at most '.self::maximumEntries().' entries.']);
        }
        $canonical = [];
        foreach ($entries as $index => $entry) {
            $value = self::canonical($entry);
            if ($value === null) {
                throw ValidationException::withMessages(["$path.$index" => 'Each entry must be an IPv4 or IPv6 address or a CIDR network.']);
            }
            $canonical[$value] = true;
        }
        $values = array_keys($canonical);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * The ranges a cell searches: sorted by first address, overlapping and touching ranges merged.
     *
     * @param  list<string>  $entries  canonical entries
     * @return array{v4: list<int>, v6: list<int>}
     */
    public static function compile(array $entries): array
    {
        $four = [];
        $six = [];
        foreach ($entries as $entry) {
            [$address, $prefix] = explode('/', $entry);
            $packed = inet_pton($address);
            $first = self::networkStart($packed, (int) $prefix);
            $last = self::networkEnd($packed, (int) $prefix);
            if (strlen($packed) === 4) {
                $four[] = [$first, $last];
            } else {
                $six[] = [$first, $last];
            }
        }
        $v4 = [];
        foreach (self::merge($four) as [$first, $last]) {
            $v4[] = unpack('N', $first)[1];
            $v4[] = unpack('N', $last)[1];
        }
        $v6 = [];
        foreach (self::merge($six) as [$first, $last]) {
            array_push($v6, ...array_values(unpack('N4', $first)), ...array_values(unpack('N4', $last)));
        }

        return ['v4' => $v4, 'v6' => $v6];
    }

    /** The compiled lists a domain's enabled rules use, by name; a name with no list compiles to an empty list that matches nothing. */
    public static function compileUsed(Domain $domain): array
    {
        $names = [];
        foreach ($domain->securityRules()->where('enabled', true)->pluck('conditions') as $conditions) {
            foreach (is_array($conditions) ? $conditions : [] as $condition) {
                if (is_array($condition) && ($condition['field'] ?? null) === 'client_ip_list' && is_string($condition['value'] ?? null)) {
                    $names[$condition['value']] = true;
                }
            }
        }
        if ($names === []) {
            return [];
        }
        $lists = $domain->ipLists()->whereIn('name', array_keys($names))->get()->keyBy('name');
        $compiled = [];
        ksort($names);
        foreach (array_keys($names) as $name) {
            /** @var IpList|null $list */
            $list = $lists->get($name);
            $compiled[$name] = $list === null ? ['v4' => [], 'v6' => []] : self::compile($list->entries);
        }

        return $compiled;
    }

    /** The rules of the domain that test a client against the named list: [rule id, enabled]. */
    public static function rulesUsing(Domain $domain, string $name): array
    {
        $using = [];
        foreach ($domain->securityRules()->get(['id', 'conditions', 'enabled']) as $rule) {
            $conditions = $rule->getAttribute('conditions');
            foreach (is_array($conditions) ? $conditions : [] as $condition) {
                if (is_array($condition) && ($condition['field'] ?? null) === 'client_ip_list' && ($condition['value'] ?? null) === $name) {
                    $using[] = ['id' => (int) $rule->getKey(), 'enabled' => (bool) $rule->getAttribute('enabled')];

                    break;
                }
            }
        }

        return $using;
    }

    /** Refuses a rule that names a list the domain does not have. */
    public static function assertListsExist(Domain $domain, array $conditions, string $path = 'conditions'): void
    {
        foreach (array_values($conditions) as $index => $condition) {
            if (($condition['field'] ?? null) === 'client_ip_list' && ! $domain->ipLists()->where('name', $condition['value'])->exists()) {
                throw ValidationException::withMessages(["$path.$index.value" => 'The domain has no IP list with this name.']);
            }
        }
    }

    /** The first and last address of a network, as packed bytes. */
    private static function networkStart(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed));
        foreach ($bytes as $index => $byte) {
            $keep = max(0, min(8, $prefix - $index * 8));
            $bytes[$index] = $byte & ((0xFF << (8 - $keep)) & 0xFF);
        }

        return pack('C*', ...$bytes);
    }

    private static function networkEnd(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed));
        foreach ($bytes as $index => $byte) {
            $keep = max(0, min(8, $prefix - $index * 8));
            $mask = (0xFF << (8 - $keep)) & 0xFF;
            $bytes[$index] = ($byte & $mask) | (~$mask & 0xFF);
        }

        return pack('C*', ...$bytes);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $ranges
     * @return list<array{0: string, 1: string}>
     */
    private static function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $count = count($merged);
            if ($count > 0 && strcmp($first, $merged[$count - 1][1]) <= 0) {
                if (strcmp($last, $merged[$count - 1][1]) > 0) {
                    $merged[$count - 1][1] = $last;
                }

                continue;
            }
            $merged[] = [$first, $last];
        }

        return $merged;
    }
}
