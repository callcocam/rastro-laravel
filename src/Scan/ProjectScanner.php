<?php

namespace Callcocam\Rastro\Scan;

use Callcocam\Rastro\Context\Sanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Reads, on the client's own server, what the AI needs to draft the help
 * tree — and nothing else. It only looks at an allowlist:
 *
 *   - the app's web routes (method, address, name, controller);
 *   - validation rules (Form Requests, and the rule arrays in `scan.paths`);
 *   - translations of the locale (the words the screens use);
 *   - the names of the page components;
 *   - the options already in the help tree.
 *
 * Never .env, config/, storage/, database/, vendor/ or node_modules — those
 * are skipped by path even when a configured folder contains them. Every
 * line that looks like a key or secret is dropped before anything leaves.
 */
class ProjectScanner
{
    /**
     * The locale's strings, flattened to `file.key: text`. From validation.php
     * only `attributes` and `custom` (the rest is the framework's).
     */
    protected function translations(string $locale): string
    {
        $base = lang_path($locale);
        $lines = [];

        if (is_dir($base)) {
            foreach ($this->files([$this->relative($base)], '*.php') as $file) {
                $key = str_replace(DIRECTORY_SEPARATOR, '/', Str::beforeLast(Str::after($file->getPathname(), $base.DIRECTORY_SEPARATOR), '.php'));

                try {
                    $strings = require $file->getPathname();
                } catch (Throwable) {
                    continue;
                }

                if (! is_array($strings)) {
                    continue;
                }

                if ($key === 'validation') {
                    $strings = Arr::only($strings, ['attributes', 'custom']);
                }

                foreach (Arr::dot($strings) as $dot => $value) {
                    if (is_string($value) && $value !== '') {
                        $lines[] = "{$key}.{$dot}: ".Str::limit($value, 200);
                    }
                }
            }
        }

        $json = lang_path($locale.'.json');

        if (is_file($json)) {
            foreach ((array) json_decode((string) file_get_contents($json), true) as $source => $value) {
                if (is_string($value)) {
                    $lines[] = Str::limit((string) $source, 120).' => '.Str::limit($value, 200);
                }
            }
        }

        return implode("\n", $lines);
    }

    /** Path pieces never read, whatever the configuration says. */
    protected const FORBIDDEN = ['.env', 'config', 'storage', 'database', 'vendor', 'node_modules', '.git', 'tests', 'bootstrap'];

    /** Lines matching these are dropped: they look like secrets. */
    protected const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b(?:sk|pk|rk)[-_](?:live|test|ant)?[-_]?[A-Za-z0-9]{16,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bxox[abpr]-[A-Za-z0-9-]{10,}/',
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        '/(?:password|passwd|secret|api[_-]?key|token)\s*[=:]\s*[\'"][^\'"]{6,}[\'"]/i',
    ];

    public function __construct(protected Router $router, protected Sanitizer $sanitizer) {}

    /**
     * The text sent to the AI, in sections, cut to `scan.max_chars`.
     */
    public function render(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $budget = max(5000, (int) config('rastro.scan.max_chars', 120000));

        $sections = [
            'aplicacao' => $this->application($locale),
            'rotas' => $this->routes(),
            'validacao' => $this->validation(),
            'telas' => $this->pages(),
            'traducoes' => $this->translations($locale),
        ];

        $text = '';

        foreach ($sections as $name => $body) {
            if (trim($body) === '') {
                continue;
            }

            $block = "<{$name}>\n".$this->scrub($body)."\n</{$name}>\n\n";
            $left = $budget - mb_strlen($text);

            if ($left <= 200) {
                break;
            }

            $text .= mb_strlen($block) > $left ? mb_substr($block, 0, $left - 20)."\n[…]\n</{$name}>\n" : $block;
        }

        return trim($text);
    }

    protected function application(string $locale): string
    {
        return 'Nome: '.config('app.name')."\nIdioma: ".$locale;
    }

    /**
     * Web routes a person can reach, without framework/tooling ones.
     */
    protected function routes(): string
    {
        $excluded = (array) config('rastro.scan.exclude_routes', []);
        $lines = [];

        /** @var Route $route */
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            if (! in_array('web', $middleware, true) || Str::is($excluded, $uri)) {
                continue;
            }

            $methods = implode('|', array_diff($route->methods(), ['HEAD']));
            $access = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'guest')) ? 'visitante' : (collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')) ? 'logado' : 'público');
            $action = $this->describeAction($route);

            $name = $route->getName() ? "[{$route->getName()}] " : '';
            $lines[] = trim("{$methods} /".ltrim($uri, '/')." {$name}({$access}) {$action}");
        }

        return implode("\n", array_unique($lines));
    }

    protected function describeAction(Route $route): string
    {
        $action = $route->getActionName();

        if ($action === 'Closure') {
            return '';
        }

        if (is_string($route->defaults['component'] ?? null)) {
            return 'tela '.$route->defaults['component'];
        }

        if (! str_contains($action, '@')) {
            return class_basename($action);
        }

        return class_basename(Str::before($action, '@')).'@'.Str::after($action, '@');
    }

    /**
     * Validation rules: from the Form Requests the routes use, and the rule
     * arrays written inside the code under `scan.paths`.
     */
    protected function validation(): string
    {
        $blocks = [];

        $blocks[] = 'Regra padrão de senha (Password::default()): '.$this->describePasswordRule(Password::default());

        foreach ($this->formRequests() as $class) {
            try {
                $request = new $class;
                $rules = method_exists($request, 'rules') ? app()->call([$request, 'rules']) : [];
            } catch (Throwable) {
                continue;
            }

            $blocks[] = class_basename($class).":\n".$this->describeRules((array) $rules);
        }

        foreach ($this->files((array) config('rastro.scan.paths', ['app']), '*.php') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/->validate\(|Validator::make\(|function \w*[Rr]ules\(|validateWithBag\(/', $source)) {
                continue;
            }

            $snippets = $this->ruleArrays($source);

            if ($snippets !== []) {
                $blocks[] = $this->relative($file->getPathname()).":\n".implode("\n", $snippets);
            }
        }

        return implode("\n\n", array_unique($blocks));
    }

    /**
     * @return array<int, class-string<FormRequest>>
     */
    protected function formRequests(): array
    {
        $classes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action, 2);

            try {
                $reflection = new ReflectionMethod($controller, $method);
            } catch (Throwable) {
                continue;
            }

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class) && ! str_starts_with($type->getName(), 'Laravel\\')) {
                    $classes[] = $type->getName();
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    protected function describeRules(array $rules): string
    {
        $lines = [];

        foreach ($rules as $field => $rule) {
            $parts = array_map(
                fn ($part) => match (true) {
                    is_string($part) => $part,
                    $part instanceof Password => 'Password('.$this->describePasswordRule($part).')',
                    is_object($part) => class_basename($part),
                    default => (string) json_encode($part),
                },
                is_string($rule) ? explode('|', $rule) : Arr::wrap($rule),
            );

            $lines[] = "  {$field}: ".implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * What a Password rule actually demands (min length, letters, …), read
     * from its settings — the AI can then tell the user the real requirement.
     */
    protected function describePasswordRule(mixed $rule): string
    {
        if (! $rule instanceof Password) {
            return 'padrão do Laravel (mínimo 8 caracteres)';
        }

        $read = function (string $property) use ($rule): mixed {
            try {
                return (new ReflectionProperty($rule, $property))->getValue($rule);
            } catch (Throwable) {
                return null;
            }
        };

        $parts = ['mínimo '.((int) ($read('min') ?? 8)).' caracteres'];

        foreach (['mixedCase' => 'maiúsculas e minúsculas', 'letters' => 'letras', 'numbers' => 'números', 'symbols' => 'símbolos', 'uncompromised' => 'não pode ser uma senha vazada'] as $property => $label) {
            if ($read($property)) {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The `[ ... ]` literals right after a validation call or inside a
     * `rules()` method — not the rest of the file.
     *
     * @return array<int, string>
     */
    protected function ruleArrays(string $source): array
    {
        $snippets = [];

        preg_match_all('/(?:->validate\(|Validator::make\([^,]+,|function \w*[Rr]ules\([^)]*\)[^{]*\{[^\[]*?return|validateWithBag\([^,]+,)\s*\[/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            $start = $offset + strlen($match) - 1;
            $array = $this->balancedArray($source, $start);

            if ($array !== null) {
                $snippets[] = preg_replace('/\s+/', ' ', Str::limit($array, 1500)) ?? '';
            }
        }

        return array_values(array_filter($snippets));
    }

    protected function balancedArray(string $source, int $start): ?string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Page component names (the screens), not their code.
     */
    protected function pages(): string
    {
        $path = base_path((string) config('rastro.scan.pages_path', 'resources/js/pages'));

        if (! is_dir($path)) {
            return '';
        }

        $names = [];

        $excluded = (array) config('rastro.scan.exclude_pages', []);

        foreach ($this->files([$this->relative($path)], ['*.vue', '*.tsx', '*.jsx', '*.svelte']) as $file) {
            $name = str_replace(DIRECTORY_SEPARATOR, '/', Str::after($file->getPathname(), $path.DIRECTORY_SEPARATOR));

            if (! Str::is($excluded, $name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return implode("\n", $names);
    }

    /**
     * Files under the given base-relative folders, minus anything forbidden.
     *
     * @param  array<int, string>  $folders
     * @param  string|array<int, string>  $names
     * @return array<int, SplFileInfo>
     */
    protected function files(array $folders, string|array $names): array
    {
        $dirs = [];

        foreach ($folders as $folder) {
            $dir = base_path(trim($folder, '/'));

            if (is_dir($dir) && ! $this->forbidden($dir)) {
                $dirs[] = $dir;
            }
        }

        if ($dirs === []) {
            return [];
        }

        $finder = Finder::create()->files()->in($dirs)->name($names)->ignoreDotFiles(true)->ignoreVCS(true);

        return array_values(array_filter(
            iterator_to_array($finder, false),
            fn (SplFileInfo $file) => ! $this->forbidden($file->getPathname()) && $file->getSize() < 300_000,
        ));
    }

    protected function forbidden(string $path): bool
    {
        $relative = $this->relative($path);

        foreach (explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $relative)) as $piece) {
            if (in_array($piece, self::FORBIDDEN, true) || str_starts_with($piece, '.env')) {
                return true;
            }
        }

        return false;
    }

    protected function relative(string $path): string
    {
        return ltrim(Str::after($path, base_path()), DIRECTORY_SEPARATOR);
    }

    /**
     * Drop lines that look like secrets and mask long numbers.
     */
    protected function scrub(string $text): string
    {
        $lines = array_filter(
            explode("\n", $text),
            function (string $line) {
                foreach (self::SECRET_PATTERNS as $pattern) {
                    if (preg_match($pattern, $line)) {
                        return false;
                    }
                }

                return true;
            },
        );

        return implode("\n", array_map(fn (string $line) => $this->sanitizer->text($line, 2000), $lines));
    }
}
