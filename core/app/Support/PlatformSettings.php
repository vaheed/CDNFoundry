<?php

namespace App\Support;

use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PlatformSettings
{
    /** @var array<string, SystemSetting> */
    private array $loaded = [];

    public function definitions(): array
    {
        return config('platform.groups', []);
    }

    public function definition(string $group): array
    {
        return $this->definitions()[$group] ?? throw ValidationException::withMessages(['group' => 'The selected platform setting group is invalid.']);
    }

    public function row(string $group, bool $fresh = false): SystemSetting
    {
        $this->definition($group);
        if ($fresh || ! isset($this->loaded[$group])) {
            $row = SystemSetting::query()->find($group)
                ?? throw new RuntimeException("Platform setting group '{$group}' is missing. Run the database migrations.");
            try {
                $values = $this->validate($group, $row->values);
            } catch (ValidationException $exception) {
                throw new RuntimeException("Platform setting group '{$group}' contains invalid persisted values.", previous: $exception);
            }
            if (collect($values)->contains(fn (mixed $value, string $key): bool => ! array_key_exists($key, $row->values) || $row->values[$key] !== $value)) {
                throw new RuntimeException("Platform setting group '{$group}' contains values that are not stored in their canonical types.");
            }
            $this->loaded[$group] = $row;
        }

        return $this->loaded[$group];
    }

    public function values(string $group): array
    {
        return $this->row($group)->values;
    }

    public function get(string $group, string $key): mixed
    {
        $values = $this->values($group);
        if (! array_key_exists($key, $values)) {
            throw new RuntimeException("Platform setting '{$group}.{$key}' is missing. Run the database migrations.");
        }

        return $values[$key];
    }

    public function integer(string $group, string $key): int
    {
        return (int) $this->get($group, $key);
    }

    public function validate(string $group, array $input): array
    {
        $definition = $this->definition($group);
        $allowed = array_keys($definition['fields']);
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => 'Unknown setting fields: '.implode(', ', $unknown).'.']);
        }
        $rules = collect($definition['fields'])->mapWithKeys(fn (array $field, string $key): array => [$key => $field['rules']])->all();
        foreach ($definition['fields'] as $key => $field) {
            if (in_array($field['type'], ['cidr_list', 'ip_list', 'choice_list'], true)) {
                $rules["{$key}.*"] = ['required', 'string', 'max:64'];
            }
        }
        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($definition, $input): void {
            foreach ($definition['fields'] as $key => $field) {
                $values = $input[$key] ?? [];
                if (! is_array($values)) {
                    continue;
                }
                foreach ($values as $index => $value) {
                    if ($field['type'] === 'ip_list' && filter_var($value, FILTER_VALIDATE_IP) === false) {
                        $validator->errors()->add("{$key}.{$index}", 'The value must be a valid IPv4 or IPv6 address.');
                    }
                    if ($field['type'] === 'cidr_list' && ! self::validCidr($value)) {
                        $validator->errors()->add("{$key}.{$index}", 'The value must be a valid IPv4 or IPv6 CIDR.');
                    }
                    if ($key === 'private_origin_allowlist' && self::validCidr($value) && ! self::privateCidr($value)) {
                        $validator->errors()->add("{$key}.{$index}", 'The allow-list accepts only CIDRs fully contained in ordinary private or carrier-grade NAT space.');
                    }
                    if ($field['type'] === 'choice_list' && ! array_key_exists($value, $field['options'])) {
                        $validator->errors()->add("{$key}.{$index}", 'The selected value is invalid.');
                    }
                }
                if (count($values) !== count(array_unique($values))) {
                    $validator->errors()->add($key, 'Duplicate values are not allowed.');
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        foreach ($definition['fields'] as $key => $field) {
            $validated[$key] = match ($field['type']) {
                'integer' => (int) $validated[$key],
                'boolean' => (bool) $validated[$key],
                'cidr_list', 'ip_list' => array_values(array_map(fn (string $value): string => strtolower(trim($value)), $validated[$key])),
                'choice_list' => array_values($validated[$key]),
                default => $validated[$key],
            };
        }

        return $validated;
    }

    public function present(?string $onlyGroup = null): array
    {
        $definitions = $onlyGroup === null ? $this->definitions() : [$onlyGroup => $this->definition($onlyGroup)];

        return collect($definitions)->map(function (array $definition, string $group): array {
            $row = $this->row($group, true);
            $fields = collect($definition['fields'])->map(function (array $field, string $key) use ($row): array {
                return ['key' => $key, 'value' => $row->values[$key], ...$field];
            })->values()->all();

            return ['group' => $group, 'label' => $definition['label'], 'description' => $definition['description'], 'revision' => $row->revision, 'fields' => $fields];
        })->values()->all();
    }

    /** @return array{setting:SystemSetting,operation:?Operation} */
    public function update(string $group, array $input, ?User $actor = null, ?string $ipAddress = null): array
    {
        $this->definition($group);
        $runtimeGroups = ['edge_runtime', 'origin_safety', 'proxy_defaults'];
        $result = DB::transaction(function () use ($group, $input, $actor, $ipAddress, $runtimeGroups): array {
            $setting = SystemSetting::query()->lockForUpdate()->find($group)
                ?? throw new RuntimeException("Platform setting group '{$group}' is missing. Run the database migrations.");
            $values = $this->validate($group, [...$setting->values, ...$input]);
            if ($setting->values === $values) {
                return ['setting' => $setting, 'operation' => null];
            }
            $setting->update(['values' => $values, 'revision' => $setting->revision + 1]);
            $operation = in_array($group, $runtimeGroups, true) ? Operation::query()->create([
                'actor_id' => $actor?->getKey(), 'type' => 'system_settings.update', 'status' => 'pending',
                'input' => ['group' => $group, 'revision' => $setting->revision],
            ]) : null;
            AuditLog::record($actor, 'system_settings.updated', $setting, [
                'group' => $group, 'revision' => $setting->revision, 'operation_id' => $operation?->getKey(),
            ], $ipAddress);

            return ['setting' => $setting, 'operation' => $operation];
        });
        $this->loaded[$group] = $result['setting'];
        if ($result['operation'] !== null) {
            self::dispatchOperation($result['operation']);
        }

        return $result;
    }

    public static function dispatchOperation(Operation $operation): void
    {
        if (($operation->input['group'] ?? null) === 'edge_runtime') {
            ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
        }
        ReconcileAllEdgeDomains::dispatch($operation->getKey())->afterCommit();
    }

    private static function validCidr(string $cidr): bool
    {
        [$address, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null || filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($bits)) {
            return false;
        }
        $packed = @inet_pton($address);

        return $packed !== false && (int) $bits >= 0 && (int) $bits <= strlen($packed) * 8;
    }

    private static function privateCidr(string $cidr): bool
    {
        [$address, $bits] = explode('/', $cidr, 2);

        return collect(['10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'])
            ->contains(function (string $private) use ($address, $bits): bool {
                [, $privateBits] = explode('/', $private, 2);

                return (int) $bits >= (int) $privateBits && NetworkAddress::inCidr($address, $private);
            });
    }
}
