<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 1 ファイル分の AST を走査し、クラス間の参照を強度つきで集める。
 * 参照先はプロジェクト内で宣言された型に限る（vendor と組み込みは対象外）。
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    /** コンテナに生成を任せる関数。Laravel と Symfony でよく使われるもの。 */
    private const CONTAINER_FUNCTIONS = ['app', 'resolve'];

    /** コンテナのメソッド。$this->app->make(Foo::class) のような形。 */
    private const CONTAINER_METHODS = ['make', 'makewith', 'bind', 'singleton', 'instance'];

    /** @var list<Reference> */
    private array $references = [];

    private ?string $currentClass = null;

    /** @var array<string, string> プロパティ名 => 型 FQCN */
    private array $propertyTypes = [];

    /** @var array<string, string> 変数名 => 型 FQCN */
    private array $variableTypes = [];

    /**
     * 入れ子のスコープを抜けたときに戻すための退避先。
     * 無名クラスやクロージャに入っても、外側のクラスや変数の型を失わないようにする。
     *
     * @var list<array{class: ?string, properties: array<string, string>, variables: array<string, string>}>
     */
    private array $scopes = [];

    /**
     * コンテナ呼び出しの引数として既に数えたノード。
     * app(Foo::class) の Foo::class を class-const として重ねて数えないために持つ。
     *
     * @var \SplObjectStorage<Node, true>
     */
    private \SplObjectStorage $consumed;

    /** @param array<string, array{kind: string, abstract: bool}> $index */
    public function __construct(
        private readonly array $index,
        private readonly string $file,
    ) {
        $this->consumed = new \SplObjectStorage();
    }

    /** @return list<Reference> */
    public function references(): array
    {
        return $this->references;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->pushScope();
            $this->enterClassLike($node);

            return null;
        }

        if ($node instanceof Node\FunctionLike) {
            $this->pushScope();
            // クラスの外にある関数は、どのクラスからの参照でもない。
            if ($node instanceof Node\Stmt\Function_) {
                $this->currentClass = null;
            }
            if ($this->currentClass !== null) {
                $this->enterFunctionLike($node);
            }

            return null;
        }

        if ($this->currentClass === null || $this->consumed->contains($node)) {
            return null;
        }

        $this->collectFromExpression($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
            $this->popScope();
        }

        return null;
    }

    private function pushScope(): void
    {
        $this->scopes[] = [
            'class' => $this->currentClass,
            'properties' => $this->propertyTypes,
            'variables' => $this->variableTypes,
        ];
    }

    private function popScope(): void
    {
        $scope = array_pop($this->scopes);
        if ($scope === null) {
            return;
        }
        $this->currentClass = $scope['class'];
        $this->propertyTypes = $scope['properties'];
        $this->variableTypes = $scope['variables'];
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        // 無名クラスは名前を持たない。囲んでいるクラスがその継承や trait を知っているので、そちらに帰属させる。
        // トップレベルの無名クラス（return new class extends Migration など）は帰属先がないので捨てる。
        $named = $node->namespacedName?->toString();
        if ($named !== null) {
            $this->currentClass = $named;
        }
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
                    $this->addType($type, 'property-type', $stmt->getLine());
                    foreach ($stmt->props as $prop) {
                        $this->propertyTypes[$prop->name->toString()] = $type;
                    }
                }
            }

            // コンストラクタのプロモートされたプロパティ。クラス全体で使えるよう、ここで登録する。
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->getParams() as $param) {
                    if ($param->flags === 0 || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    foreach ($this->typeNames($param->type) as $type) {
                        $this->propertyTypes[$param->var->name] = $type;
                    }
                }
            }
        }
    }

    private function enterFunctionLike(Node\FunctionLike $node): void
    {
        // メソッドは新しい変数スコープ。クロージャやアロー関数は外側の型を引き継ぐ。
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->variableTypes = [];
        }

        foreach ($node->getParams() as $param) {
            foreach ($this->typeNames($param->type) as $type) {
                $this->addType($type, 'param-type', $param->getLine());
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $this->variableTypes[$param->var->name] = $type;
                }
            }
        }

        foreach ($this->typeNames($node->getReturnType()) as $type) {
            $this->addType($type, 'return-type', $node->getLine());
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

        if ($node instanceof Node\Attribute) {
            $this->addType($node->name->toString(), 'attribute', $line);

            return;
        }

        // コンテナ経由の解決。相手の存在と、生成を任せられることを知っている。
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), self::CONTAINER_FUNCTIONS, true)
        ) {
            $target = $this->classConstArgument($node->getArgs());
            if ($target !== null) {
                $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Functional, 'container', $line);

                return;
            }
        }

        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), self::CONTAINER_METHODS, true)
        ) {
            $target = $this->classConstArgument($node->getArgs());
            if ($target !== null) {
                $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Functional, 'container', $line);
            }
        }

        // 文字列で書かれたクラス名。型としては現れず、リネームでも追えない。
        if ($node instanceof Node\Scalar\String_) {
            $this->addType($node->value, 'string-class', $line);

            return;
        }

        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $this->addType($node->class->toString(), 'class-const', $line);

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

    /**
     * 型としての参照を足す。相手が interface や抽象クラスなら契約止まりとみなす。
     */
    private function addType(string $type, string $kind, int $line): void
    {
        $target = ltrim($type, '\\');
        $this->add($target, $this->isAbstraction($target) ? Strength::Contract : Strength::Model, $kind, $line);
    }

    /**
     * コンテナ呼び出しの第 1 引数からクラス名を取り出す。見つけた引数は消費済みにする。
     *
     * @param array<Node\Arg> $args
     */
    private function classConstArgument(array $args): ?string
    {
        foreach ($args as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name) {
                $class = $value->class;
                $this->consumed->attach($value);

                return ltrim($class->toString(), '\\');
            }
            if ($arg->value instanceof Node\Scalar\String_) {
                $this->consumed->attach($arg->value);

                return ltrim($arg->value->value, '\\');
            }
        }

        return null;
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
