<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

/**
 * Ambient stack of active telemetry contexts for the current process.
 *
 * Bound as a container singleton so tool-execution chokepoints can discover the
 * generation they run inside without threading the context through every handler
 * signature. Nested generations push/pop in LIFO order; {@see pop()} tolerates
 * out-of-order removal so an abandoned streaming generator cannot corrupt the
 * stack for later calls.
 */
class ContextStack
{
    /** @var list<TelemetryContext> */
    protected array $stack = [];

    public function push(TelemetryContext $context): void
    {
        $this->stack[] = $context;
    }

    public function current(): ?TelemetryContext
    {
        $key = array_key_last($this->stack);

        return $key === null ? null : $this->stack[$key];
    }

    public function pop(?TelemetryContext $context = null): ?TelemetryContext
    {
        $key = array_key_last($this->stack);

        if ($key === null) {
            return null;
        }

        if ($context instanceof TelemetryContext && $this->stack[$key] !== $context) {
            foreach (array_reverse(array_keys($this->stack)) as $index) {
                if ($this->stack[$index] === $context) {
                    array_splice($this->stack, $index, 1);

                    return $context;
                }
            }

            return null;
        }

        return array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
    }
}
