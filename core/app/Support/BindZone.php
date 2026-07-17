<?php

namespace App\Support;

use App\Models\Domain;
use InvalidArgumentException;

final class BindZone
{
    public const MAX_BYTES = 1048576;

    public const MAX_RECORDS = 5000;

    /** @return list<array{type:string,name:string,content:string,content_hash:string,ttl:int,priority:int,weight:int,port:int,mode:string}> */
    public static function parse(string $text, string $zone): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Zone input exceeds the 1 MiB limit.');
        }
        $origin = $zone;
        $defaultTtl = 3600;
        $lastOwner = '@';
        $records = [];

        foreach (self::logicalLines($text) as [$lineNumber, $line, $ownerOmitted]) {
            $tokens = self::tokens($line);
            if ($tokens === []) {
                continue;
            }
            $directive = strtoupper($tokens[0]);
            if ($directive === '$ORIGIN') {
                if (count($tokens) !== 2) {
                    throw new InvalidArgumentException("Invalid ORIGIN directive on line $lineNumber.");
                }
                $origin = self::absoluteName($tokens[1], $origin);
                if ($origin !== $zone && ! str_ends_with($origin, '.'.$zone)) {
                    throw new InvalidArgumentException("ORIGIN escapes the managed zone on line $lineNumber.");
                }

                continue;
            }
            if ($directive === '$TTL') {
                $defaultTtl = self::ttl($tokens[1] ?? '', $lineNumber);

                continue;
            }
            if (str_starts_with($directive, '$')) {
                throw new InvalidArgumentException("Unsupported BIND directive on line $lineNumber.");
            }

            $typeIndex = null;
            foreach ($tokens as $index => $token) {
                if (in_array(strtoupper($token), [...DnsRecordData::TYPES, 'SOA'], true)) {
                    $typeIndex = $index;
                    break;
                }
            }
            if ($typeIndex === null) {
                throw new InvalidArgumentException("Missing or unsupported record type on line $lineNumber.");
            }
            $prefix = array_slice($tokens, 0, $typeIndex);
            $type = strtoupper($tokens[$typeIndex]);
            $rdata = array_slice($tokens, $typeIndex + 1);
            $owner = $ownerOmitted ? $lastOwner : (array_shift($prefix) ?? $lastOwner);
            $lastOwner = $owner;
            $ttl = $defaultTtl;
            foreach ($prefix as $token) {
                if (strtoupper($token) === 'IN') {
                    continue;
                }
                $ttl = self::ttl($token, $lineNumber);
            }
            if ($type === 'SOA') {
                continue;
            }
            if ($rdata === []) {
                throw new InvalidArgumentException("Missing record content on line $lineNumber.");
            }
            $input = self::recordInput($type, $owner, $rdata, $ttl, $origin, $lineNumber);
            try {
                $records[] = DnsRecordData::validate($input, $zone);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException("Invalid record on line $lineNumber: {$exception->getMessage()}", previous: $exception);
            }
            if (count($records) > self::MAX_RECORDS) {
                throw new InvalidArgumentException('Zone input exceeds the 5,000 record limit.');
            }
        }

        return $records;
    }

    public static function export(Domain $domain): string
    {
        $lines = ['$ORIGIN '.$domain->name.'.', '$TTL 3600', ''];
        foreach ($domain->dnsRecords()->orderBy('name')->orderBy('type')->orderBy('id')->get() as $record) {
            $owner = $record->name === $domain->name ? '@' : substr($record->name, 0, -strlen('.'.$domain->name));
            $content = match ($record->type) {
                'MX' => $record->priority.' '.$record->content,
                'SRV' => implode(' ', [$record->priority, $record->weight, $record->port, $record->content]),
                'TXT' => '"'.addcslashes($record->content, '"\\').'"',
                default => $record->content,
            };
            $lines[] = implode("\t", [$owner, $record->ttl, 'IN', $record->type, $content]);
        }

        return implode("\n", $lines)."\n";
    }

    private static function recordInput(string $type, string $owner, array $rdata, int $ttl, string $origin, int $line): array
    {
        $input = ['type' => $type, 'name' => self::absoluteName($owner, $origin), 'ttl' => $ttl];
        if ($type === 'MX') {
            if (count($rdata) !== 2) {
                throw new InvalidArgumentException("MX requires priority and target on line $line.");
            }

            return [...$input, 'priority' => (int) $rdata[0], 'content' => self::absoluteTarget($rdata[1], $origin)];
        }
        if ($type === 'SRV') {
            if (count($rdata) !== 4) {
                throw new InvalidArgumentException("SRV requires priority, weight, port, and target on line $line.");
            }

            return [...$input, 'priority' => (int) $rdata[0], 'weight' => (int) $rdata[1], 'port' => (int) $rdata[2], 'content' => self::absoluteTarget($rdata[3], $origin)];
        }
        if ($type === 'TXT') {
            $strings = array_map(fn (string $value): string => self::unquote($value), $rdata);

            return [...$input, 'content' => implode('', $strings)];
        }
        if ($type === 'CAA') {
            return [...$input, 'content' => implode(' ', $rdata)];
        }
        if (count($rdata) !== 1) {
            throw new InvalidArgumentException("Unexpected record content on line $line.");
        }
        $content = in_array($type, ['CNAME', 'NS', 'PTR'], true) ? self::absoluteTarget($rdata[0], $origin) : $rdata[0];

        return [...$input, 'content' => $content];
    }

    /** @return list<array{int,string,bool}> */
    private static function logicalLines(string $text): array
    {
        $result = [];
        $buffer = '';
        $start = 0;
        $omitted = false;
        $depth = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $offset => $raw) {
            $lineNumber = $offset + 1;
            $clean = self::stripComment($raw);
            if ($buffer === '' && trim($clean) !== '') {
                $start = $lineNumber;
                $omitted = preg_match('/^\s/', $clean) === 1;
            }
            $depth += substr_count($clean, '(') - substr_count($clean, ')');
            if ($depth < 0) {
                throw new InvalidArgumentException("Unbalanced parentheses on line $lineNumber.");
            }
            $buffer .= ' '.str_replace(['(', ')'], ' ', trim($clean));
            if ($depth === 0 && trim($buffer) !== '') {
                $result[] = [$start, trim($buffer), $omitted];
                $buffer = '';
            }
        }
        if ($depth !== 0) {
            throw new InvalidArgumentException('Unbalanced parentheses in zone input.');
        }

        return $result;
    }

    private static function stripComment(string $line): string
    {
        $quoted = false;
        $escaped = false;
        for ($index = 0, $length = strlen($line); $index < $length; $index++) {
            $character = $line[$index];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $quoted = ! $quoted;
            } elseif ($character === ';' && ! $quoted) {
                return substr($line, 0, $index);
            }
        }

        return $line;
    }

    private static function tokens(string $line): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\S+/', $line, $matches);

        return $matches[0];
    }

    private static function absoluteName(string $name, string $origin): string
    {
        if ($name === '@') {
            return $origin;
        }

        return str_ends_with($name, '.') ? rtrim($name, '.') : $name.'.'.$origin;
    }

    private static function absoluteTarget(string $name, string $origin): string
    {
        if ($name === '.') {
            return '.';
        }

        return self::absoluteName($name, $origin).'.';
    }

    private static function ttl(string $value, int $line): int
    {
        if (preg_match('/^(\d+)([smhdw]?)$/i', $value, $match) !== 1) {
            throw new InvalidArgumentException("Invalid TTL on line $line.");
        }
        $multiplier = match (strtolower($match[2])) {
            's', '' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800
        };
        $ttl = (int) $match[1] * $multiplier;
        if ($ttl < 30 || $ttl > 2147483647) {
            throw new InvalidArgumentException("TTL is outside the supported range on line $line.");
        }

        return $ttl;
    }

    private static function unquote(string $value): string
    {
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
