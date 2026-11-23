<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reporting period query param for the observability page (D-18).
 *
 * This is the only user input in the read path: an arbitrary string never
 * reaches the aggregate query (ASVS V5 / T-01-10). An unrecognized value is
 * normalized to the 7-day default in prepareForValidation so a bad
 * ?period= degrades to the documented default page instead of a validation
 * redirect loop on a GET page load.
 */
class ObservabilityPeriodRequest extends FormRequest
{
    /**
     * Periods the observability page understands (D-18), bounded by the
     * 90-day retention window (D-03).
     */
    public const PERIODS = ['today', '7', '30', '90'];

    public const DEFAULT_PERIOD = '7';

    /**
     * Reporting is available to every workspace member on the dashboard.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $period = $this->input('period');

        if ($period !== null && ! in_array($period, self::PERIODS, true)) {
            $this->merge(['period' => null]);
        }
    }

    /**
     * The validated period with the 7-day default (D-18) applied.
     */
    public function period(): string
    {
        return (string) ($this->validated('period') ?? self::DEFAULT_PERIOD);
    }
}
