<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** プロジェクトを 2 パスで読む。1 パス目で型の索引、2 パス目で参照の収集。 */
final class Analyzer
{
    private readonly Parser $parser;

    /** @var array<string, array{kind: string, abstract: bool, file: string}> */
    private array $index = [];

    /** @var list<Reference> */
    private array $references = [];

    /**
     * @param list<string> $excludes
     * @param list<string> $includes root 直下のこのディレクトリだけを見る。空なら root 全体。
     */
    public function __construct(
        private readonly string $root,
        private readonly array $excludes = Options::DEFAULT_EXCLUDES,
        private readonly array $includes = [],
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function run(): void
    {
        $files = $this->scan();
        foreach ($files as $file) {
            $this->indexFile($file);
        }
        foreach ($files as $file) {
            $this->collectFile($file);
        }
    }

    /** @return array<string, array{kind: string, abstract: bool, file: string}> */
    public function index(): array
    {
        return $this->index;
    }

    /** @return list<Reference> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return list<string> */
    public function scan(): array
    {
        $files = [];
        $excludes = $this->excludes;

        if ($this->includes !== []) {
            foreach ($this->includes as $include) {
                $path = rtrim($this->root, '/') . '/' . trim($include, '/');
                if (is_dir($path)) {
                    $files = [...$files, ...(new self($path, $this->excludes))->scan()];
                }
            }
            sort($files);

            return $files;
        }

        $directory = new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $entry) use ($excludes): bool {
                if (!$entry->isDir()) {
                    return $entry->getExtension() === "php";
                }
                $name = $entry->getFilename();
                if (str_starts_with($name, ".")) {
                    return false;
                }

                return !in_array($name, $excludes, true);
            },
        );
        $iterator = new \RecursiveIteratorIterator($filtered);

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }
            $path = $entry->getPathname();
            $relative = ltrim(str_replace($this->root, '', $path), '/');
            foreach ($this->excludes as $exclude) {
                if (str_starts_with($relative, $exclude . '/') || str_contains($relative, '/' . $exclude . '/')) {
                    continue 2;
                }
            }
            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    private function indexFile(string $file): void
    {
        $ast = $this->parse($file);
        if ($ast === null) {
            return;
        }

        $traverser = new NodeTraverser(new NameResolver());
        $ast = $traverser->traverse($ast);

        $visitor = new class($file) extends \PhpParser\NodeVisitorAbstract {
            /** @var array<string, array{kind: string, abstract: bool, file: string}> */
            public array $found = [];

            public function __construct(private readonly string $file)
            {
            }

            public function enterNode(\PhpParser\Node $node): null
            {
                if (!$node instanceof \PhpParser\Node\Stmt\ClassLike) {
                    return null;
                }
                $name = $node->namespacedName?->toString();
                if ($name === null) {
                    return null;
                }
                $kind = match (true) {
                    $node instanceof \PhpParser\Node\Stmt\Interface_ => 'interface',
                    $node instanceof \PhpParser\Node\Stmt\Trait_ => 'trait',
                    $node instanceof \PhpParser\Node\Stmt\Enum_ => 'enum',
                    default => 'class',
                };
                $abstract = $node instanceof \PhpParser\Node\Stmt\Class_ && $node->isAbstract();
                $this->found[$name] = ['kind' => $kind, 'abstract' => $abstract, 'file' => $this->file];

                return null;
            }
        };

        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->found as $name => $entry) {
            $this->index[$name] = $entry;
        }
    }

    private function collectFile(string $file): void
    {
        $ast = $this->parse($file);
        if ($ast === null) {
            return;
        }

        $traverser = new NodeTraverser(new NameResolver(), new ParentConnectingVisitor());
        $ast = $traverser->traverse($ast);

        $collector = new ReferenceCollector($this->index, $file);
        $traverser = new NodeTraverser($collector);
        $traverser->traverse($ast);

        foreach ($collector->references() as $reference) {
            $this->references[] = $reference;
        }
    }

    /** @return array<\PhpParser\Node\Stmt>|null */
    private function parse(string $file): ?array
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return null;
        }

        try {
            return $this->parser->parse($code);
        } catch (\PhpParser\Error) {
            return null;
        }
    }
}
