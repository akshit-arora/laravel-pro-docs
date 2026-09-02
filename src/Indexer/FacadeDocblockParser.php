<?php

declare(strict_types=1);

namespace LaravelProDocs\Indexer;

class FacadeDocblockParser
{
    /**
     * Regex pattern to match @method and @method static annotations in PHPDocs.
     * Supports:
     * - @method static string currency(float|int $number, string $in = 'USD')
     * - @method static \Illuminate\Support\Stringable of(string $string)
     * - @method array<string, mixed> all()
     * - @method string headline(string $value)
     * - @method static mixed get(string $key)
     * - @method static bool|null when(...)
     */
    private const METHOD_PATTERN = '/@method\s+(?:static\s+)?(?:(?!\()[^\n])*\b([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/i';

    /**
     * Parse method names from a class DocBlock comment.
     *
     * @return array<string> List of method names
     */
    public function parseMethods(string $docComment): array
    {
        if (trim($docComment) === '') {
            return [];
        }

        $methods = [];
        if (preg_match_all(self::METHOD_PATTERN, $docComment, $matches)) {
            foreach ($matches[1] as $method) {
                $method = trim($method);
                if ($method !== '') {
                    $methods[] = $method;
                }
            }
        }

        return array_values(array_unique($methods));
    }
}
