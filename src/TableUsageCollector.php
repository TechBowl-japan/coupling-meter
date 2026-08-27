<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 1 ファイル分の AST から、クラスごとに「触っているテーブル」を集める。
 *
 * クラス参照には現れない結合（双子モデル、SQL の複製、生 SQL）を見るための材料。
 */
final class TableUsageCollector extends NodeVisitorAbstract
{
    /** テーブル名を文字列で受けるビルダのメソッド */
    private const BUILDER_METHODS = ['table', 'from'];

    /** @var list<array{class: string, table: string, file: string, line: int}> */
    private array $usages = [];

    /** @var list<?string> 入れ子のクラスを抜けたときに戻す */
    private array $stack = [];

    private ?string $currentClass = null;

    public function __construct(private readonly string $file)
    {
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
                $this->collectModel($node);
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
            && \in_array($node->name->toLowerString(), self::BUILDER_METHODS, true)
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
     * Eloquent モデルなら、そのテーブルを記録する。
     * 親クラスの短い名前が Model なら Eloquent とみなす（vendor は読まないので継承チェーンは追えない）。
     */
    private function collectModel(Node\Stmt\Class_ $node): void
    {
        if ($node->extends === null || $node->extends->getLast() !== 'Model' || $node->name === null) {
            return;
        }

        $line = $node->getLine();
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                    $this->add(strtolower($prop->default->value), $prop->getLine());

                    return;
                }
            }
        }

        $this->add(TableNames::forModel($node->name->toString()), $line);
    }

    private function add(string $table, int $line): void
    {
        if ($this->currentClass === null || $table === '') {
            return;
        }
        $this->usages[] = ['class' => $this->currentClass, 'table' => $table, 'file' => $this->file, 'line' => $line];
    }
}
