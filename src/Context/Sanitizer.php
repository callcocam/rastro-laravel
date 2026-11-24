<?php

namespace Callcocam\Rastro\Context;

use Illuminate\Support\Str;

/**
 * Everything that goes into a conversation's technical context passes here:
 * values under sensitive keys are hidden, long digit runs (CPF, card, phone)
 * are masked, strings are cut short and nesting is bounded.
 */
class Sanitizer
{
    public const HIDDEN = '[oculto]';

    /**
     * @param  array<int, string>  $hiddenKeys
     */
    public function __construct(protected array $hiddenKeys = []) {}

    public static function fromConfig(): self
    {
        return new self((array) config('rastro.context.hidden_keys', []));
    }

    public function clean(mixed $value, int $depth = 0, int $maxLength = 500): mixed
    {
        if ($depth > 4) {
            return null;
        }

        if (is_array($value)) {
            $clean = [];

            foreach (array_slice($value, 0, 60, true) as $key => $item) {
                $clean[$key] = is_string($key) && $this->isHiddenKey($key)
                    ? self::HIDDEN
                    : $this->clean($item, $depth + 1, $maxLength);
            }

            return $clean;
        }

        if (is_string($value)) {
            return $this->text($value, $maxLength);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }

    public function text(string $value, int $maxLength = 500): string
    {
        // Bearer / key-looking tokens.
        $value = (string) preg_replace('/\b(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i', '$1'.self::HIDDEN, $value);
        $value = (string) preg_replace('/\b(sk|pk|rk)[-_][A-Za-z0-9\-_]{12,}\b/', self::HIDDEN, $value);

        // Long digit runs, with or without separators: keep the last two digits.
        $value = (string) preg_replace_callback(
            '/\d(?:[\d.\-\/ ]{9,22})\d/',
            function (array $match) {
                $digits = preg_replace('/\D/', '', $match[0]) ?? '';

                return strlen($digits) >= 9 ? str_repeat('•', strlen($digits) - 2).substr($digits, -2) : $match[0];
            },
            $value,
        );

        return Str::limit($value, $maxLength);
    }

    /**
     * A URL without query values: `/cadastro?step=2&email=x` → `/cadastro?step&email`.
     */
    public function url(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '';
        }

        $path = $parts['path'] ?? '/';

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $path .= '?'.implode('&', array_map('strval', array_keys($query)));
        }

        return Str::limit($path, 300);
    }

    public function isHiddenKey(string $key): bool
    {
        $normalized = Str::lower(str_replace(['-', ' '], '_', $key));

        foreach ($this->hiddenKeys as $hidden) {
            if (str_contains($normalized, Str::lower($hidden))) {
                return true;
            }
        }

        return false;
    }
}
