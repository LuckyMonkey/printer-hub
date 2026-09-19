<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

/**
 * Splits a ZPL stream into commands.
 *
 * ZPL is not a general-purpose language: a command is a caret or tilde, a
 * two-letter mnemonic, and a comma-separated parameter list that runs until the
 * next caret or tilde. The one case that breaks that rule is ^FD (field data),
 * whose payload is arbitrary text and routinely contains commas - so it is read
 * raw to the next caret rather than split on commas. ^FX (comment) behaves the
 * same way, which is why a comment containing a comma does not explode.
 */
final class Lexer
{
    /** Commands whose payload is raw text, not a parameter list. */
    private const RAW_PAYLOAD = ['FD', 'FX', 'FV', 'FH', 'SN'];

    /**
     * @return list<Command>
     */
    public function tokenize(string $zpl): array
    {
        // Strip UTF-8 BOM and normalise newlines; Zebra ignores CR/LF between
        // commands, and label files routinely arrive with Windows endings.
        $zpl = preg_replace('/^\xEF\xBB\xBF/', '', $zpl) ?? $zpl;

        $out = [];
        $len = strlen($zpl);
        $i = 0;

        while ($i < $len) {
            $ch = $zpl[$i];
            if ($ch !== '^' && $ch !== '~') {
                $i++;
                continue;
            }

            $prefix = $ch;
            $i++;

            // Mnemonic: letters/digits, at most two (e.g. FO, B3, GB, A0 is
            // really ^A with font '0', handled by the renderer).
            // Mnemonics are two characters, with one exception: ^A (scalable
            // font) is a single letter whose font designator follows it with no
            // separator, as in ^A0N,48,48. Taking two characters there would
            // yield a command called "A0" and silently lose every font change.
            // ^A and ^A@ are the only caret commands beginning with A.
            $maxLen = ($prefix === '^' && strtoupper($zpl[$i] ?? '') === 'A') ? 1 : 2;

            $mnemonic = '';
            while ($i < $len && strlen($mnemonic) < $maxLen && ctype_alnum($zpl[$i])) {
                $mnemonic .= strtoupper($zpl[$i]);
                $i++;
            }

            if ($mnemonic === '') {
                continue;
            }

            // Payload runs to the next caret or tilde.
            $start = $i;
            while ($i < $len && $zpl[$i] !== '^' && $zpl[$i] !== '~') {
                $i++;
            }
            $payload = substr($zpl, $start, $i - $start);

            if (in_array($mnemonic, self::RAW_PAYLOAD, true)) {
                $out[] = new Command($prefix, $mnemonic, [], $payload);
                continue;
            }

            // Newlines inside a parameter list are formatting, not data.
            $payload = str_replace(["\r", "\n"], '', $payload);
            $params = $payload === '' ? [] : explode(',', $payload);

            $out[] = new Command($prefix, $mnemonic, $params, $payload);
        }

        return $out;
    }
}
