<?php

declare(strict_types=1);

namespace Psap\Diagnostic;

final readonly class DiagnosticFormatter
{
    private DiagnosticCatalog $catalog;

    public function __construct(public string $locale = 'en')
    {
        $this->catalog = match ($locale) {
            'en' => new EnglishDiagnosticCatalog(),
            'ja' => new JapaneseDiagnosticCatalog(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported diagnostic locale: %s', $locale)),
        };
    }

    public function format(Diagnostic $diagnostic): string
    {
        $parts = [$this->message($diagnostic)];
        $location = $this->location($diagnostic);
        if ($location !== null) {
            $parts[] = $location;
        }
        $actions = $this->actions($diagnostic);
        if ($actions !== []) {
            $parts[] = ($this->locale === 'ja' ? '対処: ' : 'Action: ') . implode(' ', $actions);
        }

        return implode(' ', $parts);
    }

    public function message(Diagnostic $diagnostic): string
    {
        return $this->catalog->message($diagnostic->code, $diagnostic->context);
    }

    public function action(DiagnosticAction $action): string
    {
        return $this->catalog->action($action);
    }

    /** @return list<string> */
    public function actions(Diagnostic $diagnostic): array
    {
        return array_map($this->action(...), $diagnostic->actions);
    }

    public function location(Diagnostic $diagnostic): ?string
    {
        if ($diagnostic->file === null) {
            return null;
        }

        return $diagnostic->line === null
            ? $diagnostic->file
            : sprintf('%s:%d', $diagnostic->file, $diagnostic->line);
    }
}
