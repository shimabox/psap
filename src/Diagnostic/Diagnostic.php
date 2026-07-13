<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

/**
 * A language-neutral diagnostic produced by analysis.
 *
 * @phpstan-type ContextValue bool|float|int|string|null
 */
final readonly class Diagnostic
{
    /** @var array<string, bool|float|int|string|null> */
    public array $context;
    /** @var list<DiagnosticAction> */
    public array $actions;

    /**
     * @param array<mixed, mixed> $context
     * @param array<mixed>        $actions
     */
    public function __construct(
        public DiagnosticCode $code,
        public DiagnosticSeverity $severity,
        public ?string $file = null,
        public ?int $line = null,
        array $context = [],
        array $actions = [],
    ) {
        if ($file !== null && trim($file) === '') {
            throw new \InvalidArgumentException('Diagnostic file must be null or a non-empty path.');
        }
        if ($line !== null && $line < 1) {
            throw new \InvalidArgumentException('Diagnostic line must be null or a positive integer.');
        }
        if ($line !== null && $file === null) {
            throw new \InvalidArgumentException('A diagnostic line requires a file.');
        }

        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Diagnostic context keys must be non-empty strings.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Diagnostic context values must be scalar or null.');
            }
        }

        if (!array_is_list($actions)) {
            throw new \InvalidArgumentException('Diagnostic actions must be a list.');
        }

        $seenActions = [];
        foreach ($actions as $action) {
            if (!$action instanceof DiagnosticAction) {
                throw new \InvalidArgumentException('Diagnostic actions must be DiagnosticAction values.');
            }
            if (isset($seenActions[$action->value])) {
                throw new \InvalidArgumentException('Diagnostic actions must not contain duplicates.');
            }
            $seenActions[$action->value] = true;
        }

        /** @var array<string, bool|float|int|string|null> $context */
        $this->context = $context;
        /** @var list<DiagnosticAction> $actions */
        $this->actions = $actions;
    }
}
