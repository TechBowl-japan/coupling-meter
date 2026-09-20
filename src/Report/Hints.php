<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Report;

/**
 * 参照の種類ごとの定型ヒント。なぜその強度になるか（why）と、1 段弱めるなら何をするか（next）。
 *
 * 判断はしない。--samples を読む人や AI が、コードを開く前に当たりを付けるための材料。
 * 文面は公開ツールの出力なので英語で書く（コメントとコミットは日本語のまま）。
 */
final class Hints
{
    private function __construct(
        public readonly string $why,
        public readonly string $next,
    ) {
    }

    public static function for(string $kind): self
    {
        return match ($kind) {
            'extends' => new self(
                'Extends a concrete class, inheriting its implementation and internal state (intrusive)',
                'Replace inheritance with delegation. Holding the parent in a field and calling only what you need drops it to functional',
            ),
            'use-trait' => new self(
                'Pulls a trait implementation into its own body (intrusive)',
                'Turn the trait into a class and inject it. A call drops it to functional; an interface drops it to contract',
            ),
            'static-property' => new self(
                'Shares a static property, which is the other side state itself (intrusive)',
                'Move the state into an object and pass it as an argument. A single access path drops it to functional',
            ),
            'shared-table' => new self(
                'Touches the same table, sharing the schema as an internal representation (intrusive)',
                'Give the table one owning module and let the others go through its public methods or a read-only view',
            ),
            'new' => new self(
                'Knows how the other side is constructed, down to its constructor arguments (functional)',
                'Introduce an interface and receive it from a factory or the container. An abstract target drops it to contract',
            ),
            'static-call' => new self(
                'Depends directly on a static method implementation (functional)',
                'Make it an instance method and inject it behind an interface, which also makes it replaceable in tests',
            ),
            'method-call' => new self(
                'Calls a method on a concrete class (functional)',
                'Replace the type with an interface so the caller knows only the contract',
            ),
            'container' => new self(
                'Resolves a concrete class from the container (functional)',
                'Bind and resolve the interface instead, so the concrete name disappears from the code',
            ),
            'async-dispatch' => new self(
                'Hands work off asynchronously through a queue or an event (functional). The runtime distance is long',
                'Narrow the payload to a DTO or an array, and depend on the message contract rather than the other class',
            ),
            'param-type', 'return-type', 'property-type' => new self(
                'Knows the other side as a type (model), so a change in its properties or method shapes propagates',
                'Replace a concrete type with an interface or a DTO. For read-only values a DTO is enough',
            ),
            'instanceof', 'catch' => new self(
                'Branches on the other side type (model)',
                'Move the branch into a method on that side (polymorphism), or catch a shared base exception',
            ),
            'class-const' => new self(
                'References a class constant or ::class of the other side (model)',
                'Copy the constant to your side or move it into configuration. For ::class, use the interface',
            ),
            'attribute' => new self(
                'Uses the other class as an attribute (model)',
                'Attributes are usually a framework contract. If it is your own, consider an interface-based attribute',
            ),
            'string-class' => new self(
                'Writes the class name as a string (model). It never appears as a type, and renaming cannot follow it',
                'Replace it with ::class, or move it into configuration if it comes from a config file',
            ),
            'implements' => new self(
                'Implements an interface or an abstract class (contract)',
                'Weak enough. Nothing to loosen here',
            ),
            default => new self(
                'Depends on the internals of the other side in some form',
                'Open the line from --samples and check what it actually knows about the other side',
            ),
        };
    }
}
