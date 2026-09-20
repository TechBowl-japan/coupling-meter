<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Source;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Techtrain\CouplingMeter\Config\Preset;

/**
 * 1 ファイル分の AST から、クラスごとに「触っているテーブル」を集める。
 *
 * クラス参照には現れない結合（双子モデル、SQL の複製、生 SQL）を見るための材料。
 */
final class TableUsageCollector extends NodeVisitorAbstract
{
    /** @var list<array{class: string, table: string, file: string, line: int}> */
    private array $usages = [];

    /** @var list<?string> 入れ子のクラスを抜けたときに戻す */
    private array $stack = [];

    private ?string $currentClass = null;

    public function __construct(
        private readonly string $file,
        /** どのクラスがテーブルを持つか、どの呼び出しがテーブル名を取るかを決める */
        private readonly Preset $preset = new Preset('none'),
    ) {
    }

    /** @return list<array{class: string, table: string, file: string, line: int}> */
    public function usages(): array
    {
        return $this->usages;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->stack[] = $this->currentClass;
            $named = $node->namespacedName?->toString();
            if ($named !== null) {
                $this->currentClass = $named;
            }
            if ($node instanceof Node\Stmt\Class_ && $this->currentClass !== null) {
                $this->collectEntity($node);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Function_) {
            $this->stack[] = $this->currentClass;
            $this->currentClass = null;

            return null;
        }

        if ($this->currentClass === null) {
            return null;
        }

        if ($node instanceof Node\Scalar\String_) {
            foreach (TableNames::fromSql($node->value) as $table) {
                $this->add($table, $node->getLine());
            }

            return null;
        }

        if (($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall)
            && $node->name instanceof Node\Identifier
            && \in_array($node->name->toLowerString(), $this->preset->tableBuilderMethods, true)
        ) {
            $first = $node->getArgs()[0] ?? null;
            if ($first !== null && $first->value instanceof Node\Scalar\String_) {
                $this->add(strtolower($first->value->value), $first->value->getLine());
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
            $this->currentClass = array_pop($this->stack);
        }

        return null;
    }

    /**
     * テーブルを持つクラスなら、そのテーブルを記録する。
     *
     * 見分け方は preset が決める。Eloquent は親クラスの短い名前（Model）、Doctrine は属性（ORM\Table）。
     * vendor は読まないので継承チェーンは追えず、どちらも 1 段の宣言だけを見る。
     */
    private function collectEntity(Node\Stmt\Class_ $node): void
    {
        if ($node->name === null || !$this->preset->findsEntities()) {
            return;
        }

        $declared = $this->tableFromAttributes($node) ?? $this->tableFromAnnotation($node);
        if ($declared !== null) {
            $this->add(strtolower($declared[0]), $declared[1]);

            return;
        }

        $extends = $node->extends?->getLast();
        if ($extends === null || !\in_array($extends, $this->preset->entityBaseClasses, true)) {
            return;
        }

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (\in_array($prop->name->toString(), $this->preset->tableProperties, true)
                    && $prop->default instanceof Node\Scalar\String_
                ) {
                    $this->add(strtolower($prop->default->value), $prop->getLine());

                    return;
                }
            }
        }

        if ($this->preset->pluralizeEntityName) {
            $this->add(TableNames::forModel($node->name->toString()), $node->getLine());
        }
    }

    /**
     * #[ORM\Table(name: 'dtb_product')] のような属性から取る。
     *
     * @return array{0: string, 1: int}|null テーブル名と行
     */
    private function tableFromAttributes(Node\Stmt\Class_ $node): ?array
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->isTableAttribute($attribute->name->toString())) {
                    continue;
                }
                foreach ($attribute->args as $arg) {
                    if ($arg->name?->toString() === 'name' && $arg->value instanceof Node\Scalar\String_) {
                        return [$arg->value->value, $attribute->getLine()];
                    }
                }
            }
        }

        return null;
    }

    /**
     * docblock の @ORM\Table(name="dtb_product") から取る。
     * 属性より前のバージョンの Doctrine はこちらで書かれている。
     *
     * @return array{0: string, 1: int}|null テーブル名と行
     */
    private function tableFromAnnotation(Node\Stmt\Class_ $node): ?array
    {
        $doc = $node->getDocComment();
        if ($doc === null || $this->preset->tableAttributes === []) {
            return null;
        }

        foreach ($this->preset->tableAttributes as $name) {
            $pattern = '/@' . preg_quote($name, '/') . '\s*\([^)]*name\s*=\s*"([^"]+)"/';
            if (preg_match($pattern, $doc->getText(), $matches) === 1) {
                return [$matches[1], $doc->getStartLine()];
            }
        }

        return null;
    }

    /**
     * 属性の名前が preset の宣言と同じものか。
     *
     * NameResolver を通った後の名前は完全修飾（Doctrine\ORM\Mapping\Table）になるが、
     * 設定には書かれたまま（ORM\Table）を書きたい。末尾で突き合わせる。
     */
    private function isTableAttribute(string $resolved): bool
    {
        $resolved = ltrim($resolved, '\\');
        foreach ($this->preset->tableAttributes as $declared) {
            $declared = ltrim($declared, '\\');
            if (strcasecmp($resolved, $declared) === 0) {
                return true;
            }
            if (str_ends_with(strtolower($resolved), '\\' . strtolower($declared))) {
                return true;
            }
            $last = static fn (string $name): string => strtolower(substr(strrchr($name, '\\') ?: '\\' . $name, 1));
            if ($last($resolved) === $last($declared)) {
                return true;
            }
        }

        return false;
    }

    private function add(string $table, int $line): void
    {
        if ($this->currentClass === null || $table === '') {
            return;
        }
        $this->usages[] = ['class' => $this->currentClass, 'table' => $table, 'file' => $this->file, 'line' => $line];
    }
}
