<?php

declare(strict_types=1);

namespace LaravelProDocs\Indexer;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class AstSymbolExtractor
{
    private Parser $parser;
    private FacadeDocblockParser $docblockParser;

    public const MAGIC_METHODS = [
        '__construct',
        '__destruct',
        '__call',
        '__callStatic',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__toString',
        '__invoke',
        '__set_state',
        '__clone',
        '__debugInfo',
    ];

    public function __construct(
        ?Parser $parser = null,
        ?FacadeDocblockParser $docblockParser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
        $this->docblockParser = $docblockParser ?? new FacadeDocblockParser();
    }

    /**
     * Extract all public symbols (classes methods, facade docblock methods, helper functions, artisan commands, blade directives) from PHP code.
     *
     * @return array<string> List of symbol identifiers (e.g. ['Number::currency', 'Illuminate\Support\Number::currency', 'str', 'make:model', '@use'])
     */
    public function extractSymbolsFromCode(string $code, string $filePath = ''): array
    {
        if (trim($code) === '') {
            return [];
        }

        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return [];
            }
        } catch (Error) {
            return [];
        }

        $docblockParser = $this->docblockParser;

        $visitor = new class($docblockParser, $filePath) extends NodeVisitorAbstract {
            private ?string $currentNamespace = null;
            /** @var array<string, ?string> */
            public array $collectedSymbols = [];

            public function __construct(
                private readonly FacadeDocblockParser $docblockParser,
                private readonly string $filePath,
            ) {
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name ? $node->name->toString() : null;
                    return null;
                }

                if ($node instanceof ClassLike) {
                    if ($node->name === null) {
                        return null;
                    }

                    $shortClassName = $node->name->toString();
                    $fqcn = $this->currentNamespace !== null
                        ? $this->currentNamespace . '\\' . $shortClassName
                        : $shortClassName;

                    $classApiUrl = 'https://api.laravel.com/docs/master/' . str_replace('\\', '/', $fqcn) . '.html';

                    // Index class symbol itself (e.g. Number, Context, Concurrency)
                    $this->collectedSymbols[$shortClassName] = $classApiUrl;
                    $this->collectedSymbols[$fqcn] = $classApiUrl;

                    // Parse Facade docblock annotations (@method static ...)
                    $docComment = $node->getDocComment();
                    if ($docComment !== null) {
                        $docMethods = $this->docblockParser->parseMethods($docComment->getText());
                        foreach ($docMethods as $docMethod) {
                            $methodApiUrl = "{$classApiUrl}#method_{$docMethod}";
                            $this->collectedSymbols["{$shortClassName}::{$docMethod}"] = $methodApiUrl;
                            $this->collectedSymbols["{$fqcn}::{$docMethod}"] = $methodApiUrl;
                        }
                    }

                    $isBladeConcern = ($shortClassName === 'BladeCompiler' || str_starts_with($shortClassName, 'Compiles'));

                    // Parse methods
                    foreach ($node->getMethods() as $method) {
                        $methodName = $method->name->toString();

                        // Blade compiler directives (@directive) from BladeCompiler or Compiles* traits
                        if ($isBladeConcern && str_starts_with($methodName, 'compile') && strlen($methodName) > 7) {
                            $dirName = lcfirst(substr($methodName, 7));
                            $ignoredCompilers = ['string', 'statements', 'statement', 'extensions', 'componentTags', 'comments', 'rawPhp', 'classComponentOpening', 'endComponentClass'];
                            if (!in_array($dirName, $ignoredCompilers, true)) {
                                $this->collectedSymbols['@' . $dirName] = 'https://laravel.com/docs/master/blade';
                            }
                        }

                        // Validation rule methods (validateFooBar -> rule:foo_bar, validation:foo_bar) from ValidatesAttributes or Validator
                        if (($shortClassName === 'ValidatesAttributes' || $shortClassName === 'Validator') && str_starts_with($methodName, 'validate') && strlen($methodName) > 8) {
                            $rawRuleName = substr($methodName, 8);
                            $snakeRule = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $rawRuleName));

                            $ruleStartLine = $method->getStartLine();
                            $ruleEndLine = $method->getEndLine();
                            $ruleSourceUrl = null;
                            if ($this->filePath !== '') {
                                $relPath = str_replace('\\', '/', $this->filePath);
                                if (preg_match('/(src\/Illuminate\/[a-zA-Z0-9_\/.-]+\.php)/', $relPath, $m)) {
                                    $ruleSourceUrl = sprintf('https://github.com/laravel/framework/blob/master/%s#L%d-L%d', $m[1], $ruleStartLine, $ruleEndLine);
                                }
                            }
                            if ($ruleSourceUrl === null) {
                                $ruleSourceUrl = "https://laravel.com/docs/master/validation#rule-{$snakeRule}";
                            }

                            $this->collectedSymbols["rule:{$snakeRule}"] = $ruleSourceUrl;
                            $this->collectedSymbols["validation:{$snakeRule}"] = $ruleSourceUrl;
                        }

                        if ($this->shouldIndexMethod($method)) {
                            $methodApiUrl = "{$classApiUrl}#method_{$methodName}";
                            $this->collectedSymbols["{$shortClassName}::{$methodName}"] = $methodApiUrl;
                            $this->collectedSymbols["{$fqcn}::{$methodName}"] = $methodApiUrl;
                        }
                    }

                    // Parse Artisan command signature / name properties
                    if ($node instanceof Class_) {
                        foreach ($node->getProperties() as $property) {
                            foreach ($property->props as $prop) {
                                $propName = $prop->name->toString();
                                if (($propName === 'signature' || $propName === 'name') && $prop->default instanceof String_) {
                                    $sig = $prop->default->value;
                                    if (preg_match('/^([a-z0-9:-]+)/', $sig, $cmdMatch)) {
                                        $cmd = $cmdMatch[1];
                                        $this->collectedSymbols["artisan:{$cmd}"] = $classApiUrl;
                                        if (str_contains($cmd, ':')) {
                                            $this->collectedSymbols[$cmd] = $classApiUrl;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    return null;
                }

                if ($node instanceof Function_) {
                    $funcName = $node->name->toString();
                    $startLine = $node->getStartLine();
                    $endLine = $node->getEndLine();

                    $helperSourceUrl = null;
                    if ($this->filePath !== '') {
                        $relPath = str_replace('\\', '/', $this->filePath);
                        if (preg_match('/(src\/Illuminate\/[a-zA-Z0-9_\/.-]+\.php)/', $relPath, $m)) {
                            $cleanRelPath = $m[1];
                            $helperSourceUrl = sprintf('https://github.com/laravel/framework/blob/master/%s#L%d-L%d', $cleanRelPath, $startLine, $endLine);
                        }
                    }

                    if ($helperSourceUrl === null) {
                        $helperSourceUrl = 'https://laravel.com/docs/master/helpers#method-' . strtolower(str_replace('_', '-', $funcName));
                    }

                    $this->collectedSymbols[$funcName] = $helperSourceUrl;

                    if ($this->currentNamespace !== null) {
                        $this->collectedSymbols[$this->currentNamespace . '\\' . $funcName] = $helperSourceUrl;
                    }

                    return null;
                }

                return null;
            }

            private function shouldIndexMethod(ClassMethod $method): bool
            {
                if (!$method->isPublic()) {
                    return false;
                }

                $name = $method->name->toString();
                if (in_array($name, AstSymbolExtractor::MAGIC_METHODS, true)) {
                    return false;
                }

                return true;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->collectedSymbols;
    }
}
