<?php

namespace App\Http\Requests;

use App\Models\DomainNameTombstone;
use App\Support\DomainName;
use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        try {
            $this->merge(['name' => DomainName::normalize((string) $this->input('name'))]);
        } catch (\InvalidArgumentException) {
            // Keep the submitted value so validation returns the stable field error shape.
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    DomainName::normalize((string) $value);
                } catch (\InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }, function (string $attribute, mixed $value, \Closure $fail): void {
                $tombstone = DomainNameTombstone::query()->where('name', $value)->first();
                if ($tombstone?->reclaim_after?->isFuture()) {
                    $fail('The domain is still in its post-deprovisioning reclaim cooldown.');
                }
            }],
        ];
    }
}
