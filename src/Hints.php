<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 参照の種類ごとの定型ヒント。なぜその強度になるか（why）と、1 段弱めるなら何をするか（next）。
 *
 * 判断はしない。--samples を読む人や AI が、コードを開く前に当たりを付けるための材料。
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
                '具象クラスを継承し、親の実装と内部状態をそのまま引き継いでいる（intrusive）',
                '継承を委譲に変える。親をフィールドに持って必要なメソッドだけ呼べば functional に下がる',
            ),
            'use-trait' => new self(
                'trait の実装を自分の内部に取り込んでいる（intrusive）',
                'trait をクラスにして注入する。呼び出しになれば functional、interface を挟めば contract',
            ),
            'static-property' => new self(
                '相手の静的プロパティ、つまり状態そのものを共有している（intrusive）',
                '状態を持つオブジェクトにして引数で渡す。参照経路を 1 つにすれば functional',
            ),
            'shared-table' => new self(
                '同じテーブルを触っている。スキーマという内部表現を共有している（intrusive）',
                'テーブルの所有者を 1 つのモジュールに決め、他はその公開メソッドか読み取り専用のビュー経由にする',
            ),
            'new' => new self(
                '相手の生成方法（コンストラクタ引数）を知っている（functional）',
                'interface を切って factory かコンテナから受け取る。相手が抽象なら contract に下がる',
            ),
            'static-call' => new self(
                '静的メソッドの実装に直接依存している（functional）',
                'インスタンスメソッドにして interface 越しに注入する。テストでも差し替えられるようになる',
            ),
            'method-call' => new self(
                '具象クラスのメソッドを呼んでいる（functional）',
                '型を interface に置き換える。呼ぶ側は contract だけを知る状態になる',
            ),
            'container' => new self(
                'コンテナから具象クラスを解決している（functional）',
                'interface を bind して interface で解決する。具象名がコードから消える',
            ),
            'async-dispatch' => new self(
                'キューやイベントで非同期に渡している（functional）。実行時の距離は遠い',
                '渡すデータを DTO や配列に絞り、相手のクラスではなくメッセージの契約に依存する',
            ),
            'param-type', 'return-type', 'property-type' => new self(
                '相手を型として知っている（model）。相手のプロパティやメソッドの形が変わると影響を受ける',
                '相手が具象なら interface か DTO に置き換える。読み取り専用の値なら DTO で十分',
            ),
            'instanceof', 'catch' => new self(
                '相手の型で分岐している（model）',
                '分岐を相手側のメソッドに移す（ポリモーフィズム）か、例外なら共通の基底例外で受ける',
            ),
            'class-const' => new self(
                '相手のクラス定数か ::class を参照している（model）',
                '定数なら自分側に写すか設定に出す。::class なら interface の ::class にする',
            ),
            'attribute' => new self(
                '属性として相手のクラスを使っている（model）',
                '属性はフレームワークの契約であることが多い。相手が自前なら interface の属性にできないか検討する',
            ),
            'string-class' => new self(
                'クラス名を文字列で書いている（model）。型に現れず、名前を変えても追えない',
                '::class に置き換える。設定ファイル経由なら設定側に寄せる',
            ),
            'implements' => new self(
                'interface か抽象クラスを実装している（contract）',
                '十分に弱い。これ以上は弱めなくてよい',
            ),
            default => new self(
                '相手の内部に何らかの形で依存している',
                '--samples の該当行を開いて、相手の何を知っているかを確かめる',
            ),
        };
    }
}
