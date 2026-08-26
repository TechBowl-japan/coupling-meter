# php-coupling

PHP プロジェクトの結合バランスを計測する。

依存の有無ではなく、**その結合が釣り合っているか**を出す。Vlad Khononov『Balancing Coupling in Software Design』の
統合強度（strength）、距離（distance）、変動性（volatility）を、静的解析と git 履歴から測る。

## 原著の規則

```
MODULARITY = STRENGTH XOR DISTANCE
COMPLEXITY = STRENGTH AND DISTANCE
BALANCE    = (STRENGTH XOR DISTANCE) OR NOT VOLATILITY
```

強度と距離が打ち消し合っていればモジュラー、そろっていれば複雑になる。両方が低い組も低凝集として複雑の側に入る。
変動性が低ければ、崩れていても実害は出ない。

| | 距離が低い | 距離が高い |
|---|---|---|
| **強度が低い** | 低凝集（複雑） | 疎結合（モジュラー） |
| **強度が高い** | 高凝集（モジュラー） | 密結合（複雑） |

## 何を測るか

| 軸 | 出どころ | 中身 |
|---|---|---|
| strength | AST | contract、model、functional、intrusive の 4 段階。相手のどこまで知っているか |
| distance | 名前空間と git | 2 つのモジュールの最も近い共通の祖先から出す。多くのモジュールが依存する相手は共有カーネルとみなして 1 段割り引き、触っている人が分かれている組は 1 段遠くする |
| volatility | git log | そのモジュールが実際に変更されたコミット数の分位 |
| 推定変動性 | 上記の組み合わせ | 依存先の変動性を強度に応じて受け取った値。原著 9.5 の推定変動性 |
| 変更の中身 | git log | Conventional Commits の prefix から、機能を足す変更（feat、perf）、修正（fix）、整備（refactor ほか）に分ける |
| co-change | git log | 2 つのモジュールが同じコミットで変わった割合 |

3 以上を高、2 以下を低として規則に入れ、象限とバランスの成否を出す。

順位づけには原著 10.3 の均衡結合方程式を使う。3 つの次元を 1 から 10 の目盛りに載せて計算する。

```
モジュール性 = |strength - distance| + 1
均衡度       = max(|strength - distance|, 10 - volatility) + 1
```

均衡度が低いほど複雑性に傾いている。目盛りは原著の割り当てに従う。

| 次元 | 目盛り |
|---|---|
| strength | contract=1、model=3、functional=8、intrusive=10 |
| distance | 同じ名前空間=2、離れるほど 3 から 7（ライブラリ以上は対象外） |
| volatility | git の分位を 1、3、6、10 に写す |

著者は「これは正確な科学ではない」と断っている。数値範囲は目的に応じて調整してよい。

## 強度の判定

| 強度 | AST 上のしるし |
|---|---|
| intrusive | 具象クラスの継承、trait の use、静的プロパティの参照 |
| functional | new、静的メソッド呼び出し、型が判っている変数へのメソッド呼び出し、コンテナ経由の解決 |
| model | 引数と戻り値の型、プロパティの型、instanceof、catch、クラス定数、属性、文字列で書かれたクラス名 |
| contract | interface と抽象クラスへの依存 |

相手が interface または抽象クラスなら、new とメソッド呼び出しをどちらも contract に落とす。

## 使い方

```bash
composer install
bin/php-coupling <path> [--include=app,src] [--depth=2] [--since="12 months ago"] [--top=15] [--json]
```

```
php-coupling /path/to/project
  クラス 6530 / 参照 46710 / モジュール 22 / 組 111 / 解析コミット 5317

  バランスが崩れている組: 20 / 111

直す順（強度と距離の釣り合いの悪さ × 観測された変動性）
  RANK  STRENGTH   DIST  VOL  CO-CHG  QUADRANT       MODULE PAIR
    16  functional    3    4    40%   tight-coupling ! App\Observers -> Package\Domain
    16  model         2    4     7%   low-cohesion ! App\Providers -> App\Filament

指摘
  [型に出ない結合] App\Providers -> App\Policies
      型の上は model だが、16 回のコミットで同時に変わっている（48%）
```

## 指摘の種類

| 種類 | 条件 | 読み方 |
|---|---|---|
| 互いに依存 | 双方向に参照がある | 層の分割が効いていない |
| 強度も距離も高い | 密結合の象限で、相手もよく変わる | 強度を下げるか、距離を縮める |
| 近いのに関係が薄い | 低凝集の象限で、相手もよく変わる | 近くに置く理由を確認する |
| 型に出ない結合 | 型の上は弱いのに 30% 以上同時に変わる | 静的解析では見えない。設計の意図を確認する |
| 文字列で書かれた依存 | クラス名を文字列で書いている箇所が 3 件以上 | 型に現れず、名前を変えても追えない |
| 触っている人が分かれている | 所有者の重なりが 1/3 未満で、functional 以上が 20 箇所以上 | 変更を合わせるのに人をまたぐ調整が要る |
| 相手の変動性をもらっている | 自分は変わらないのに、よく変わる相手へ強く依存している | 自分の履歴だけを見ても出てこない変動性がある |
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

- **本物の変動性**。git 履歴から出るのは観測された変更頻度であって、設計が悪くて頻繁に変わっている場合と、
  危険で誰も触れていない場合を区別しない。原著はソース管理の解析とドメイン分析を併用すべきとしている。
  機能追加と修正の区別までは実装しているが、サブドメインの判定（コア、支援、汎用）はしていない
- **チームの構造**。git のコミット著者を所有者の代わりに使っているだけで、実際のチーム編成、勤務地、タイムゾーンは見ていない
- **実行時結合による距離**。同期か非同期かは距離に効くが、そこは測っていない
- **実行時の依存の一部**。`app(Foo::class)` や `$this->app->make(Foo::class)` のようにクラス名が式として書かれていれば追える。
  文字列で組み立てたクラス名、Facade 越しの呼び出し、設定ファイル経由の解決は追えない
- **依存の数**。原著は関係の数ではなく性質を見る立場を取る。実装もそれに従うため、参照 1 箇所の依存が上位に来る
- テストコード（既定で除外する）

## 先行する指標との関係

| 系統 | 例 | 本ツールとの関係 |
|---|---|---|
| 依存の数を数える | Martin の指標。PHP では PhpMetrics、PDepend | 数ではなく性質を 4 段階に分類する |
| 境界の違反を出す | deptrac、PHPArkitect | 二値ではなく、崩れの度合いと順位を出す |
| 履歴から結合を見つける | CodeScene、Code Maat、Qafoo changetrack | 同じ考え方を使う。AST 由来の強度と 1 つの表にまとめた点が違う |
| 結合の質を分類する | 構造化設計の結合度（1974）、connascence | 分類を機械判定に落とした |

著者自身も [vladikk/modularity](https://github.com/vladikk/modularity) で Claude Code スキルを公開している。
あちらは判断の枠組みを AI に渡すもので、計測はしない。本ツールは同じ入力に同じ出力を返す代わりに、判断はしない。

## 開発

```bash
vendor/bin/phpunit
```

`tests/fixtures/` に判定を確かめるための小さなプロジェクトを置いてある。

## ライセンス

MIT
