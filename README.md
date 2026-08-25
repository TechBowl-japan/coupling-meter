# php-coupling

PHP プロジェクトの結合バランスを計測する。

依存の有無ではなく、**その依存がどれだけ痛いか**を出す。Vlad Khononov『Balancing Coupling in Software Design』の
統合強度（strength）、距離（distance）、変動（volatility）を、静的解析と git 履歴から測る。

## 何を測るか

| 軸 | 出どころ | 中身 |
|---|---|---|
| strength | AST | contract → model → functional → intrusive の 4 段階。相手のどこまで知っているか |
| distance | 名前空間 | 共通の階層を除いた距離。多くのモジュールが依存する相手は共有カーネルとみなして 1 段割り引く |
| volatility | git log | そのモジュールが実際に変更されたコミット数の分位 |
| co-change | git log | 2 つのモジュールが同じコミットで変わった割合 |

痛み（pain）は strength × distance × volatility で出す。3 つが同時に高い箇所ほど、変更のたびに手が入る。

## 強度の判定

| 強度 | AST 上のしるし |
|---|---|
| intrusive | 具象クラスの継承、trait の use、静的プロパティの参照 |
| functional | new、静的メソッド呼び出し、型が判っている変数へのメソッド呼び出し |
| model | 引数と戻り値の型、プロパティの型、instanceof、catch、クラス定数 |
| contract | interface と抽象クラスへの依存 |

相手が interface または抽象クラスなら、new もメソッド呼び出しも contract に落とす。

## 使い方

```bash
composer install
bin/php-coupling <path> [--include=app,src] [--depth=2] [--since="12 months ago"] [--top=15] [--json]
```

```
php-coupling /path/to/project
  クラス 1812 / 参照 6211 / モジュール 58 / 組 158 / 解析コミット 5323

痛みの上位（強度 × 距離 × 変動）
  PAIN  STRENGTH   DIST  VOL   CO-CHG  MODULE PAIR
    24  intrusive     2    3      36%  App\Filament\Resources -> App\Filament\Traits

指摘
  [踏み込んだ依存が動いている] App\Filament\Resources -> App\Filament\Traits
      内部に踏み込んだ依存が 111 箇所あり、36% のコミットで同時に変わっている
```

## 指摘の種類

| 種類 | 条件 | 読み方 |
|---|---|---|
| 互いに依存 | 双方向に参照がある | 層の分割が効いていない |
| 遠いのに強い | 距離 3 以上で functional 以上、20 箇所以上 | 強度を下げるか、距離を縮める |
| 型に出ない結合 | 型の上は弱いのに 30% 以上同時に変わる | 静的解析では見えない。設計の意図を確認する |
| 踏み込んだ依存が動いている | intrusive かつ 20% 以上同時に変わる | 最も直す価値が高い |

## オプション

| オプション | 既定 | 意味 |
|---|---|---|
| `--include` | なし | root 直下のこのディレクトリだけを見る |
| `--exclude` | vendor, node_modules, storage, tests | 除外を追加する |
| `--depth` | 2 | 名前空間の何段目までを 1 モジュールとするか |
| `--since` | 12 months ago | git 履歴をさかのぼる範囲 |
| `--top` | 15 | 表示する組の数 |
| `--json` | なし | 機械可読な出力 |

## 測らないもの

- 実行時の依存（DI コンテナ経由の解決、文字列のクラス名、Facade）
- ドメインとしての近さ。名前空間の距離はその代理でしかない
- テストコード（既定で除外する）

## ライセンス

MIT
