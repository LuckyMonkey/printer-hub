<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

/** One lexed ZPL command. Immutable. */
final class Command
{
    /** @param list<string> $params */
    public function __construct(
        public readonly string $prefix,
        public readonly string $name,
        public readonly array $params,
        public readonly string $raw,
    ) {
    }

    /** Parameter as int, with a default when absent or blank. */
    public function int(int $index, int $default = 0): int
    {
        $v = $this->params[$index] ?? '';
        $v = trim($v);
        if ($v === '' || !preg_match('/^-?\d+$/', $v)) {
            return $default;
        }

        return (int) $v;
    }

    /** Parameter as an uppercased string, with a default when absent or blank. */
    public function str(int $index, string $default = ''): string
    {
        $v = trim($this->params[$index] ?? '');

        return $v === '' ? $default : strtoupper($v);
    }
}
