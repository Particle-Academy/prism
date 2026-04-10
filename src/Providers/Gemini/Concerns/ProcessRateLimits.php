<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Concerns;

use Carbon\Carbon;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    /**
     * @param  array<string, mixed>  $responseData
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(array $responseData): array
    {
        $violations = data_get($responseData, 'error.details', []);

        if (! is_array($violations)) {
            return [];
        }

        $quotaFailure = collect($violations)
            ->first(fn (mixed $detail): bool => data_get($detail, '@type') === 'type.googleapis.com/google.rpc.QuotaFailure');

        $quotaViolations = data_get($quotaFailure, 'violations', []);

        if (! is_array($quotaViolations)) {
            return [];
        }

        return collect($quotaViolations)
            ->filter(fn (mixed $violation): bool => is_array($violation))
            ->map(fn (array $violation): ProviderRateLimit => new ProviderRateLimit(
                name: (string) data_get($violation, 'quotaId', data_get($violation, 'quotaMetric', 'quota')),
                limit: $this->toNullableInt(data_get($violation, 'quotaValue')),
                remaining: null,
                resetsAt: $this->buildResetsAtFromResponse($responseData)
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    protected function extractRetryAfterSeconds(array $responseData): ?int
    {
        $details = data_get($responseData, 'error.details', []);

        if (! is_array($details)) {
            return null;
        }

        $retryInfo = collect($details)
            ->first(fn (mixed $detail): bool => data_get($detail, '@type') === 'type.googleapis.com/google.rpc.RetryInfo');

        $retryDelay = data_get($retryInfo, 'retryDelay');

        $retryAfterFromMessage = $this->extractRetryAfterFromMessage(
            data_get($responseData, 'error.message')
        );

        if (is_string($retryDelay) && preg_match('/^(?<seconds>\d+)(?:\.\d+)?s$/', $retryDelay, $matches) === 1) {
            $retryAfterFromDetails = (int) $matches['seconds'];

            if ($retryAfterFromDetails > 0 || $retryAfterFromMessage === null) {
                return $retryAfterFromDetails;
            }
        }

        return $retryAfterFromMessage;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function buildResetsAtFromResponse(array $responseData): ?Carbon
    {
        $retryAfter = $this->extractRetryAfterSeconds($responseData);

        return $retryAfter === null ? null : Carbon::now()->addSeconds($retryAfter);
    }

    private function extractRetryAfterFromMessage(mixed $message): ?int
    {
        if (is_string($message) && preg_match('/Please retry in (?<delay>[0-9.]+)(?<unit>ms|s)\./', $message, $matches) === 1) {
            $delay = (float) $matches['delay'];

            return $matches['unit'] === 'ms'
                ? (int) ceil($delay / 1000)
                : (int) ceil($delay);
        }

        return null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
