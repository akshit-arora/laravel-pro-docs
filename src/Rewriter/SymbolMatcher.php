<?php

declare(strict_types=1);

namespace LaravelProDocs\Rewriter;

use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Models\SymbolMeta;

class SymbolMatcher
{
    /**
     * Common / generic words, PHP keywords, types, and generic methods to never match unless explicitly scoped by class or called as a function.
     *
     * @var array<string>
     */
    private const GENERIC_BLACKLIST = [
        'get', 'set', 'find', 'save', 'update', 'delete', 'destroy', 'all', 'first', 'last', 'has', 'have',
        'put', 'push', 'pull', 'pop', 'shift', 'unshift', 'where', 'when', 'unless', 'tap', 'pipe', 'count',
        'map', 'filter', 'reduce', 'each', 'keys', 'values', 'merge', 'combine', 'chunk', 'split', 'slice',
        'take', 'skip', 'sum', 'avg', 'min', 'max', 'create', 'make', 'build', 'handle', 'boot', 'register',
        'run', 'start', 'stop', 'reset', 'clear', 'flush', 'clean', 'load', 'unload', 'enable', 'disable',
        'open', 'close', 'read', 'write', 'send', 'receive', 'connect', 'disconnect', 'dispatch', 'fire',
        'listen', 'subscribe', 'unsubscribe', 'before', 'after', 'around', 'in', 'on', 'at', 'by', 'for',
        'with', 'without', 'to', 'from', 'as', 'is', 'isnot', 'equals', 'diff', 'same', 'notsame', 'toarray',
        'tojson', 'tostring', 'tohtml', 'true', 'false', 'null', 'string', 'int', 'integer', 'float', 'double',
        'bool', 'boolean', 'array', 'object', 'callable', 'iterable', 'mixed', 'void', 'never', 'self', 'static',
        'parent', 'class', 'this', 'name', 'value', 'key', 'type', 'file', 'path', 'id', 'model', 'table', 'url',
        'view', 'route', 'session', 'cache', 'cookie', 'request', 'response', 'auth', 'user', 'event', 'queue',
        'mail', 'log', 'db', 'app', 'env', 'config', 'lang', 'trans', 'asset', 'mix', 'vite', 'now', 'today',
        '__construct', '__destruct', '__call', '__callstatic', '__get', '__set', '__isset', '__unset',
        '__sleep', '__wakeup', '__serialize', '__unserialize', '__tostring', '__invoke', '__set_state', '__clone', '__debuginfo'
    ];

    /**
     * Mapping from documentation file slug (e.g. "cache", "eloquent") to relevant primary classes / facades.
     *
     * @var array<string, array<string>>
     */
    private const DOC_CONTEXT_MAP = [
        'cache' => ['Cache', 'Repository'],
        'context' => ['Context'],
        'routing' => ['Route', 'Router'],
        'validation' => ['ValidatesAttributes', 'Validator', 'Rule'],
        'collections' => ['Collection', 'Enumerable', 'LazyCollection'],
        'eloquent-collections' => ['EloquentCollection', 'Illuminate\Database\Eloquent\Collection', 'Collection', 'Enumerable'],
        'strings' => ['Str', 'Stringable', 'Number'],
        'mail' => ['Mail', 'Mailable', 'Mailer'],
        'queues' => ['Queue', 'Bus', 'Job', 'Batch'],
        'events' => ['Event', 'Dispatcher'],
        'broadcasting' => ['Broadcast', 'Broadcaster'],
        'session' => ['Session', 'Store'],
        'filesystem' => ['Storage', 'File', 'Filesystem'],
        'processes' => ['Process', 'ProcessPool'],
        'concurrency' => ['Concurrency'],
        'rate-limiting' => ['RateLimiter', 'Limit'],
        'database' => ['DB', 'Schema', 'Builder'],
        'queries' => ['DB', 'Builder'],
        'migrations' => ['Blueprint', 'Schema', 'Builder'],
        'database-testing' => ['DB', 'Schema', 'TestCase'],
        'eloquent' => ['Model', 'Builder', 'Eloquent'],
        'eloquent-relationships' => ['Model', 'Relation', 'HasMany', 'BelongsTo', 'HasOne', 'BelongsToMany'],
        'eloquent-mutators' => ['Attribute', 'Cast'],
        'eloquent-resources' => ['JsonResource', 'ResourceCollection'],
        'eloquent-factories' => ['Factory', 'Sequence'],
        'http-client' => ['Http', 'PendingRequest', 'Response', 'Pool'],
        'http-tests' => ['TestResponse', 'AssertsStatusCodes', 'AssertableJson', 'TestCase'],
        'testing' => ['TestCase', 'TestResponse', 'AssertsStatusCodes', 'Event', 'Queue', 'Mail', 'Notification', 'Bus', 'Storage'],
        'dusk' => ['Browser'],
        'logging' => ['Log', 'Logger'],
        'scheduling' => ['Schedule', 'Event'],
        'authentication' => ['Auth', 'Gate', 'Authenticatable'],
        'authorization' => ['Gate', 'Policy'],
        'hashing' => ['Hash'],
        'encryption' => ['Crypt', 'Encrypter'],
        'pagination' => ['Paginator', 'LengthAwarePaginator', 'CursorPaginator'],
        'redis' => ['Redis'],
        'blade' => ['Blade', 'BladeCompiler'],
        'views' => ['View', 'Factory'],
    ];

    /**
     * Precomputed map of unique method names across the framework to their SymbolMeta.
     *
     * @var array<string, SymbolMeta>
     */
    private array $uniqueMethodMap = [];

    public function __construct(
        private readonly SymbolRegistry $registry,
    ) {
        $this->buildUniqueMethodMap();
    }

    /**
     * Build lookup map for methods that appear on only one class/facade across the framework.
     */
    private function buildUniqueMethodMap(): void
    {
        $methodToSymbols = [];
        foreach ($this->registry->all() as $symbol => $meta) {
            if (str_starts_with($symbol, 'header:') || str_starts_with($symbol, 'page:')) {
                continue;
            }

            if (str_contains($symbol, '::')) {
                [$class, $method] = explode('::', $symbol, 2);
                $parts = explode('\\', $class);
                $shortClass = end($parts);
                $methodToSymbols[$method][$shortClass] = $meta;
            }
        }

        foreach ($methodToSymbols as $method => $classMetas) {
            if (count($classMetas) === 1 && !in_array(strtolower($method), self::GENERIC_BLACKLIST, true)) {
                $this->uniqueMethodMap[$method] = reset($classMetas);
            }
        }
    }

    /**
     * Normalize and match an inline code snippet or heading title to an indexed SymbolMeta.
     *
     * @param string $rawToken Raw inline code token or heading text
     * @param string|null $docSlug Optional documentation file slug (e.g. "cache", "context") for contextual resolution
     */
    public function match(string $rawToken, ?string $docSlug = null): ?SymbolMeta
    {
        $cleaned = trim($rawToken);
        if ($cleaned === '') {
            return null;
        }

        // Strip backticks if passed
        $cleaned = trim($cleaned, '`');

        // Guardrail: Reject bare instance calls like ->get(), ->find(), ->save()
        if (str_starts_with($cleaned, '->') || str_starts_with($cleaned, '$this->')) {
            return null;
        }

        $isFunctionCall = (bool) preg_match('/^[a-zA-Z0-9_\\\\]+\s*\(/', $cleaned);

        // Variable method call: $var->method(...) or $this->method(...)
        if (preg_match('/^\$[a-zA-Z0-9_]+->([a-zA-Z0-9_]+)(?:\(.*\))?/', $cleaned, $varMethodMatch)) {
            $methodName = $varMethodMatch[1];
            if ($docSlug !== null && isset(self::DOC_CONTEXT_MAP[$docSlug])) {
                foreach (self::DOC_CONTEXT_MAP[$docSlug] as $ctxClass) {
                    $meta = $this->registry->get("{$ctxClass}::{$methodName}");
                    if ($meta !== null) {
                        return $meta;
                    }
                }
            }
            if (!in_array(strtolower($methodName), self::GENERIC_BLACKLIST, true) && isset($this->uniqueMethodMap[$methodName])) {
                return $this->uniqueMethodMap[$methodName];
            }
            return null;
        }

        // Blade directive: @directive
        if (str_starts_with($cleaned, '@')) {
            $directive = preg_replace('/\(.*$/', '', $cleaned);
            $meta = $this->registry->get($directive);
            if ($meta !== null) {
                return $meta;
            }
        }

        // Extract potential symbol candidates from the raw token
        $candidates = $this->extractCandidates($cleaned);

        foreach ($candidates as $candidate) {
            // Check direct match in registry
            $meta = $this->registry->get($candidate);
            if ($meta !== null) {
                // If it's a bare word (no ::), allow if it was written as a function call, otherwise check blacklist
                if (!str_contains($candidate, '::') && !str_starts_with($candidate, '@') && !str_contains($candidate, ':')) {
                    if (!$isFunctionCall && in_array(strtolower($candidate), self::GENERIC_BLACKLIST, true)) {
                        continue;
                    }
                }
                return $meta;
            }

            // Check Artisan command (e.g. make:model, route:list)
            if (str_contains($candidate, ':') && !str_contains($candidate, '::')) {
                $meta = $this->registry->get("artisan:{$candidate}");
                if ($meta !== null) {
                    return $meta;
                }
            }

            // Try short class name if candidate is FQCN
            if (str_contains($candidate, '\\') && str_contains($candidate, '::')) {
                [$class, $method] = explode('::', $candidate, 2);
                $parts = explode('\\', $class);
                $shortName = end($parts) . '::' . $method;
                $meta = $this->registry->get($shortName);
                if ($meta !== null) {
                    return $meta;
                }
            }

            // Contextual resolution: If candidate is a method name on the primary class of this doc
            if (!str_contains($candidate, '::')) {
                if ($docSlug !== null && isset(self::DOC_CONTEXT_MAP[$docSlug])) {
                    foreach (self::DOC_CONTEXT_MAP[$docSlug] as $ctxClass) {
                        $meta = $this->registry->get("{$ctxClass}::{$candidate}");
                        if ($meta !== null) {
                            // If it's a generic word, only match if explicitly called as a method e.g. `after()`
                            if ($isFunctionCall || !in_array(strtolower($candidate), self::GENERIC_BLACKLIST, true)) {
                                return $meta;
                            }
                        }
                    }
                }

                // Fallback: Only match uniquely defined methods across the entire framework if written as an explicit call (e.g. `foo()`)
                if ($isFunctionCall && !in_array(strtolower($candidate), self::GENERIC_BLACKLIST, true) && isset($this->uniqueMethodMap[$candidate])) {
                    return $this->uniqueMethodMap[$candidate];
                }
            }
        }

        return null;
    }

    /**
     * Generate symbol candidate strings from a raw token.
     *
     * @return array<string>
     */
    private function extractCandidates(string $token): array
    {
        $candidates = [];

        // 1. Direct candidate
        $candidates[] = $token;

        // 2. If it contains parenthesis (e.g. Number::currency(...) or rescue(...)), strip call arguments
        if (preg_match('/^([a-zA-Z0-9_\\\\]+(?:::[a-zA-Z0-9_]+)?)\s*\(/', $token, $matches)) {
            $candidates[] = $matches[1];
        }

        // 3. Testing assertion calls: $response->assertOk() or assertOk()
        if (preg_match('/^(?:\$response->)?(assert[a-zA-Z0-9_]+)(?:\(.*\))?;?$/', $token, $matches)) {
            $candidates[] = $matches[1];
            $candidates[] = "TestResponse::{$matches[1]}";
        }

        // 4. Blueprint column/index calls: $table->id(), $table->vector(), $table->ulid()
        if (preg_match('/^\$table->([a-zA-Z0-9_]+)(?:\(.*\))?;?$/', $token, $matches)) {
            $candidates[] = $matches[1];
            $candidates[] = "Blueprint::{$matches[1]}";
        }

        // 5. Validation rule with parameters: decimal:min,max or doesnt_start_with:foo,bar or missing_if:...
        $cleanRuleToken = str_replace('\\_', '_', $token);
        if (preg_match('/^([a-z0-9_]+):/', $cleanRuleToken, $matches)) {
            $candidates[] = $matches[1];
            $candidates[] = "rule:{$matches[1]}";
            $candidates[] = "validation:{$matches[1]}";
        }

        // 6. If it's a Static call syntax: ClassName::method
        if (preg_match('/([a-zA-Z0-9_\\\\]+::[a-zA-Z0-9_]+)/', $token, $matches)) {
            $candidates[] = $matches[1];
        }

        // 7. If it's a simple function call: func_name()
        if (preg_match('/^([a-zA-Z0-9_]+)\s*\(/', $token, $matches)) {
            $candidates[] = $matches[1];
        }

        // 8. If it's an Artisan command invocation: `php artisan make:model` or `artisan route:list` or `php artisan serve`
        if (preg_match('/^(?:php\s+)?artisan\s+([a-z0-9:-]+)/', $token, $matches)) {
            $candidates[] = "artisan:{$matches[1]}";
            if (str_contains($matches[1], ':')) {
                $candidates[] = $matches[1];
            }
        }

        // 9. If it's a colon command: make:model
        if (preg_match('/^([a-z0-9]+:[a-z0-9-]+)/', $token, $matches)) {
            $candidates[] = $matches[1];
            $candidates[] = "artisan:{$matches[1]}";
        }

        // 10. If it's just a single identifier: rescue, str, rememberWithWarmth, etc.
        if (preg_match('/^[a-zA-Z0-9_]+$/', $cleanRuleToken)) {
            $candidates[] = $cleanRuleToken;
            $candidates[] = "rule:{$cleanRuleToken}";
            $candidates[] = "validation:{$cleanRuleToken}";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Match a heading (H1, H2, H3, H4) against page introductions, section introductions, or symbol features.
     */
    public function matchHeading(string $headingTitle, int $level, ?string $docSlug = null): ?SymbolMeta
    {
        $cleanTitle = trim($headingTitle);
        if ($cleanTitle === '') {
            return null;
        }

        // Normalize escaped underscores in Markdown headings (e.g. after\_or\_equal)
        $cleanTitle = str_replace('\\_', '_', $cleanTitle);

        // Strip backticks or {.collection-method} markers if present
        $cleanTitle = trim(preg_replace('/\{\s*\.[^}]+\}/', '', $cleanTitle));
        $strippedTitle = trim($cleanTitle, '` ');

        // 1. First priority: Check if heading explicitly defines an AST code symbol (e.g. `Str::doesntEndWith()`, `Number::currency`, `@use`)
        $astSymbolMeta = $this->match($cleanTitle, $docSlug) ?? $this->match($strippedTitle, $docSlug);
        if ($astSymbolMeta !== null) {
            return $astSymbolMeta;
        }

        // 2. If heading is written as `methodName()` or methodName() in a specific docSlug context (e.g. `after()` in collections)
        if ($docSlug !== null && isset(self::DOC_CONTEXT_MAP[$docSlug]) && preg_match('/^`?([a-zA-Z0-9_]+)\s*\(\)`?$/', $cleanTitle, $methodMatch)) {
            $methodName = $methodMatch[1];
            foreach (self::DOC_CONTEXT_MAP[$docSlug] as $ctxClass) {
                $meta = $this->registry->get("{$ctxClass}::{$methodName}");
                if ($meta !== null) {
                    return $meta;
                }
            }
        }

        // 3. Validation rule headings (e.g. #### accepted, #### decimal:min,max, #### doesnt_start_with:foo,bar)
        if ($docSlug === 'validation') {
            $ruleName = explode(':', $strippedTitle)[0];
            $ruleName = trim($ruleName, '` ');
            $ruleMeta = $this->registry->get("rule:{$ruleName}") ?? $this->registry->get("validation:{$ruleName}");
            if ($ruleMeta !== null) {
                return $ruleMeta;
            }
        }

        // 4. Testing response assertion headings (e.g. #### assertConflict, #### assertJsonIsArray)
        if ($docSlug === 'http-tests' && str_starts_with($strippedTitle, 'assert')) {
            $assertMeta = $this->registry->get("TestResponse::{$strippedTitle}");
            if ($assertMeta !== null) {
                return $assertMeta;
            }
        }

        // 5. Page title (H1, e.g. # AI SDK or # Concurrency)
        if ($level === 1 && $docSlug !== null) {
            $pageMeta = $this->registry->get("page:{$docSlug}");
            if ($pageMeta !== null) {
                return $pageMeta;
            }
        }

        // 6. Section heading lookup in docSlug (H2, H3, H4)
        if ($docSlug !== null) {
            $headerMeta = $this->registry->get("header:{$docSlug}:{$cleanTitle}")
                ?? $this->registry->get("header:{$docSlug}:{$strippedTitle}");
            if ($headerMeta !== null) {
                return $headerMeta;
            }
        }

        return null;
    }
}
