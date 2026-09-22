<script setup>
import ExceptionSupport from '../components/ExceptionSupport.vue'
</script>

# Error handling

By default, Prism throws a `PrismException` for Prism errors, or a `PrismServerException` for Prism Server errors.

Catch specific exception types to implement retries, failover or application error messages.

## Provider agnostic exceptions

- `PrismStructuredDecodingException` where a provider has returned invalid JSON for a structured request.

## Exceptions based on provider feedback

Prism currently supports three exceptions based on provider feedback:

- `PrismRateLimitedException` where you have hit a rate limit or quota (see [Handling rate limits](/advanced/rate-limits.html) for more info).
- `PrismProviderOverloadedException` where the provider is unable to fulfil your request due to capacity issues.
- `PrismRequestTooLargeException` where your request is too large.

Exception mapping varies by provider. Keep a fallback handler for `PrismException` when a more specific exception is unavailable.

<ExceptionSupport />
