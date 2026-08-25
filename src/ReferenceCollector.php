<?php

declare(strict_types=1);

namespace Valbeat\PhpCoupling;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 1 ファイル分の AST を走査し、クラス間の参照を強度つきで集める。
 * 参照先はプロジェクト内で宣言された型に限る（vendor と組み込みは対象外）。
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    /** @var list<Reference> */
    private array $references = [];

    private ?string $currentClass = null;

    /** @var array<string, string> プロパティ名 => 型 FQCN */
    private array $propertyTypes = [];

    /** @var array<string, string> 変数名 => 型 FQCN */
    private array $variableTypes = [];

    /** @param array<string, array{kind: string, abstract: bool}> $index */
    public function __construct(
        private readonly array $index,
        private readonly string $file,
    ) {
    }

    /** @return list<Reference> */
    public function references(): array
    {
        return $this->references;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        if ($this->currentClass === null) {
            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure) {
            $this->enterFunctionLike($node);

            return null;
        }

        $this->collectFromExpression($node);

        return null;
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        $this->currentClass = $node->namespacedName?->toString() ?? $node->name?->toString();
        $this->propertyTypes = [];
        $this->variableTypes = [];

        if ($this->currentClass === null) {
            return;
        }

        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends !== null) {
                // 実装継承は親の内部を引き継ぐ。相手が抽象なら契約側に寄せる。
                $parent = $node->extends->toString();
                $this->add($parent, $this->isAbstraction($parent) ? Strength::Contract : Strength::Intrusive, 'extends', $node->getLine());
            }
            foreach ($node->implements as $interface) {
                $this->add($interface->toString(), Strength::Contract, 'implements', $node->getLine());
            }
        }

        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->add($interface->toString(), Strength::Contract, 'extends', $node->getLine());
            }
        }

        if ($node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->add($interface->toString(), Strength::Contract, 'implements', $node->getLine());
            }
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    // trait は相手の実装をそのまま自分の内部に取り込む。
                    $this->add($trait->toString(), Strength::Intrusive, 'use-trait', $stmt->getLine());
                }
            }

            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($this->typeNames($stmt->type) as $type) {
                    $this->add($type, Strength::Model, 'property-type', $stmt->getLine());
                    foreach ($stmt->props as $prop) {
                        $this->propertyTypes[$prop->name->toString()] = $type;
                    }
                }
            }
        }
    }

    private function enterFunctionLike(Node\FunctionLike $node): void
    {
        $this->variableTypes = [];

        foreach ($node->getParams() as $param) {
            foreach ($this->typeNames($param->type) as $type) {
                $this->add($type, Strength::Model, 'param-type', $param->getLine());
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $this->variableTypes[$param->var->name] = $type;
                }
                // コンストラクタのプロモートされたプロパティ
                if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $this->propertyTypes[$param->var->name] = $type;
                }
            }
        }

        foreach ($this->typeNames($node->getReturnType()) as $type) {
            $this->add($type, Strength::Model, 'return-type', $node->getLine());
        }
    }

    private function collectFromExpression(Node $node): void
    {
        $line = $node->getLine();

        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            // 生成は相手の構築方法を知っている。抽象なら契約止まり。
            $target = $node->class->toString();
            $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Functional, 'new', $line);

            return;
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $target = $node->class->toString();
            $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Functional, 'static-call', $line);

            return;
        }

        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->class instanceof Node\Name) {
            // 静的プロパティの共有は相手の状態そのものへの依存。
            $this->add($node->class->toString(), Strength::Intrusive, 'static-property', $line);

            return;
        }

        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $this->add($node->class->toString(), Strength::Model, 'class-const', $line);

            return;
        }

        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $this->add($node->class->toString(), Strength::Model, 'instanceof', $line);

            return;
        }

        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->add($type->toString(), Strength::Model, 'catch', $line);
            }

            return;
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $target = $this->resolveVarType($node->var);
            if ($target !== null) {
                // 型が判っている変数へのメソッド呼び出しは、相手の振る舞いへの依存。
                $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Functional, 'method-call', $line);
            }
        }
    }

    private function resolveVarType(Node\Expr $var): ?string
    {
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return $this->variableTypes[$var->name] ?? null;
        }

        if ($var instanceof Node\Expr\PropertyFetch
            && $var->var instanceof Node\Expr\Variable
            && $var->var->name === 'this'
            && $var->name instanceof Node\Identifier
        ) {
            return $this->propertyTypes[$var->name->toString()] ?? null;
        }

        return null;
    }

    /** @return list<string> */
    private function typeNames(?Node $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof Node\NullableType) {
            return $this->typeNames($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->typeNames($inner)];
            }

            return $names;
        }

        if ($type instanceof Node\Name) {
            return [$type->toString()];
        }

        return [];
    }

    private function isAbstraction(string $fqcn): bool
    {
        $entry = $this->index[$fqcn] ?? null;

        return $entry !== null && ($entry['kind'] === 'interface' || $entry['abstract']);
    }

    private function add(string $to, Strength $strength, string $kind, int $line): void
    {
        $to = ltrim($to, '\\');
        if ($this->currentClass === null || $to === $this->currentClass) {
            return;
        }
        if (!isset($this->index[$to])) {
            return;
        }

        $this->references[] = new Reference($this->currentClass, $to, $strength, $kind, $this->file, $line);
    }
}
