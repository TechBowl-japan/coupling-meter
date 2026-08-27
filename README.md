# coupling-meter

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
| distance | 名前空間、composer、git、呼び出し方 | 2 つのモジュールの最も近い共通の祖先から出す。同じ composer パッケージの中では上限を設け、別パッケージなら 2 段遠くする。多くのモジュールが依存する相手は共有カーネルとみなして 1 段割り引き、触っている人が分かれている組は 1 段遠くする。キューやイベントなど非同期の呼び出しだけでつながっている組は 1 段遠くする |
| volatility | git log | そのモジュールが実際に変更されたコミット数の分位。回数は変更の種類で重み付けする（feat / perf = 1、fix = 0.5、refactor などの整備 = 0.25、分類できないものは 1）。期間内に変わらなかったモジュールも 0 回として分布に含め、その変動性は 1。同じ回数のモジュールは平均順位を取り、全員が同じなら中位に置く。`coupling-meter.yaml` の `volatility` で原著の目盛り（1 から 10）を宣言すれば、そちらを優先する |
| 推定変動性 | 上記の組み合わせ | 依存先の変動性を強度に応じて受け取った値。原著 9.5 の推定変動性 |
| 変更の中身 | git log | Conventional Commits の prefix から、機能を足す変更（feat、perf）、修正（fix）、整備（refactor ほか）に分ける |
| co-change | git log | 2 つのモジュールが同じコミットで変わった割合。コミット集合の Jaccard 係数（共起 / 和集合）。何とでも一緒に変わる巨大モジュールとの組が 100% に張り付かないようにしている |

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
| intrusive | 具象クラスの継承、trait の use、静的プロパティの参照、同じテーブルを触る（下記） |
| functional | new、静的メソッド呼び出し、型が判っている変数へのメソッド呼び出し、コンテナ経由の解決、`Job::dispatch()` / `dispatch(new Job)` / `event(new Event)`（非同期。距離に効く） |
| model | 引数と戻り値の型、プロパティの型、instanceof、catch、クラス定数、属性、文字列で書かれたクラス名 |
| contract | interface と抽象クラスへの依存 |

相手が interface または抽象クラスなら、new とメソッド呼び出しをどちらも contract に落とす。

### 型に出ない結合: 同じテーブル

クラス参照に現れない結合のうち、同じテーブルを触っているものは `shared-table` として拾う。テーブルのスキーマという内部表現を共有しているので intrusive に置く。

| 出どころ | 判定 |
|---|---|
| Eloquent モデル | `Model` を継承するクラス。`protected $table` があればその値、なければクラス名を snake_case の複数形にしたもの |
| 生 SQL | 文字列リテラル中の `FROM` / `JOIN` / `INTO` / `UPDATE` / `DELETE FROM` に続く識別子 |
| クエリビルダ | `DB::table('x')`、`->table('x')`、`->from('x')` の文字列引数 |

同じテーブルを触るクラスの組ごとに、双方向の参照を 1 件ずつ足す。モデルが 1 つもないテーブル（生 SQL 同士だけ）も組になる。

## インストール

```bash
composer require --dev techtrain/coupling-meter
```

計測したいプロジェクトの外から使うなら、グローバルに入れてもよい。

```bash
composer global require techtrain/coupling-meter
```

PHP 8.2 以上。解析対象のプロジェクトは PHP のバージョンを問わない（php-parser が読める構文であればよい）。

## 使い方

```bash
vendor/bin/coupling-meter <path> [--include=app,src] [--exclude=legacy] [--depth=2] [--since="12 months ago"] [--top=15] [--json|--samples]
```

リポジトリを clone して使う場合は `composer install` のあと `bin/coupling-meter` を実行する。

```
coupling-meter /path/to/project --depth=2
  クラス 1840 / 参照 12530 / モジュール 22 / 組 111 / 解析コミット 2317

  バランスが崩れている組: 20 / 111

直す順（均衡度の低い順。max(|強度 - 距離|, 10 - 変動性) + 1）
   BAL  STRENGTH    STR DIST  VOL  CO-CHG  MODULE PAIR
     1  model         3    3   10     48%  Shop\Checkout -> Shop\Catalog
     2  functional    8    7   10     40%  Billing\Invoice -> Shop\Catalog
     4  intrusive    10    7   10     25%  Legacy\Reports -> Shop\Orders

指摘
  [型に出ない結合] Shop\Checkout -> Shop\Catalog
      型の上は model だが、16 回のコミットで同時に変わっている（48%）
  [踏み込んだ依存が動いている] Legacy\Reports -> Shop\Orders
      内部に踏み込んだ依存が 31 箇所あり、25% のコミットで同時に変わっている
```

読み方の例。`Shop\Checkout -> Shop\Catalog` は型の上では model 結合で、Catalog は多くのモジュールが使う共有カーネルとして距離も近い（3）。
強度も距離も低い低凝集の組だが、Catalog がよく変わり（10）、しかも 48% のコミットで一緒に変わっているので、均衡度は最低の 1 になる。
`Legacy\Reports -> Shop\Orders` は継承や trait で Orders の内部に踏み込んでおり（10）、名前空間も担当者も離れている（7）。
均衡度は 4 で上の 2 つより高いが、intrusive かつ同時変更 25% なので指摘としては最も直す価値が高い。

## 指摘の種類

| 種類 | 条件 | 読み方 |
|---|---|---|
| 互いに依存 | 双方向とも model 以上で参照している | 層の分割が効いていない |
| 逆転済みの依存 | 双方向に参照があるが、片方は interface 経由（contract）だけ | DIP で逆転している。情報として出す |
| 強度も距離も高い | 密結合の象限でバランスが崩れており、参照が 20 箇所以上 | 強度を下げるか、距離を縮める |
| 近いのに関係が薄い | 低凝集の象限でバランスが崩れており、参照が 20 箇所以上 | 近くに置く理由を確認する |
| 型に出ない結合 | 型の上は model 以下なのに、5 回以上かつ Jaccard 20% 以上同時に変わる | 静的解析では見えない。設計の意図を確認する |
| 文字列で書かれた依存 | クラス名を文字列で書いている箇所が 3 件以上 | 型に現れず、名前を変えても追えない |
| 触っている人が分かれている | 所有者の重なりが 1/3 未満で、functional 以上が 20 箇所以上 | 変更を合わせるのに人をまたぐ調整が要る |
| 相手の変動性をもらっている | 自分は変わらない（2 以下）のに、よく変わる相手へ functional 以上で 20 箇所以上依存している | 自分の履歴だけを見ても出てこない変動性がある |
| 踏み込んだ依存が動いている | intrusive かつ 5 回以上かつ Jaccard 15% 以上同時に変わる | 最も直す価値が高い |

## オプション

| オプション | 既定 | 意味 |
|---|---|---|
| `--include` | なし | root 直下のこのディレクトリだけを見る |
| `--exclude` | vendor, node_modules, storage, bootstrap/cache, tests, test | 除外を追加する。root 直下だけでなく、途中の階層にある同名ディレクトリも除外する |
| `--depth` | 2 | 名前空間の何段目までを 1 モジュールとするか |
| `--since` | 12 months ago | git 履歴をさかのぼる範囲 |
| `--top` | 15 | 表示する組の数 |
| `--json` | なし | 機械可読な出力。`--top` に関係なく全組を出す。`--samples` とは同時に指定できない |
| `--samples` | なし | 組ごとの代表例をファイルと行つきで出す。各例に「なぜその強度か」「1 段弱めるなら何をするか」の定型文を添える。AI に渡して判断させる用 |
| `--rules` | 自動検出 | 意図した依存の許可ルール。省略時は root の `coupling-meter.yaml` / `deptrac.yaml` / `deptrac.config.yaml` を探す |
| `--split` | 0 | この数を超えるクラスを持つ名前空間は、その子名前空間を別モジュールとして切る。`App\Models` のような巨大モジュールとの組で VOL と同時変更率が天井に張り付くのを防ぐ。子もまだ大きければさらに切る |
| `--weight-by-references` | なし | 順位づけを参照数の対数で重み付けする。原著は数ではなく性質を見る立場なので既定では使わない。参照 1 箇所の組が上位を埋めて読みにくいときに |

## 意図した依存を指摘から外す

DIP で逆転した `Application -> Infrastructure` や、設計上の `Adapter -> Http` のように、設計として認めている方向の依存は指摘の対象から外せる。
順位表には残る（`--json` では `intended: true`）。

deptrac を使っているなら、`deptrac.yaml` の `layers`（`classLike` の正規表現）と `ruleset` をそのまま読む。

```yaml
# coupling-meter.yaml（deptrac を使っていない場合）
allow:
  - 'App\Application -> App\Domain'
  - 'App\Infrastructure -> App\*'
```

`*` はワイルドカード。左が依存する側、右が依存される側。

## 変動性を宣言する

git から出るのは観測された変更頻度で、これから変わる見込みは入らない。原著はドメイン分析（コア、支援、汎用のサブドメイン）との併用を求めている。
分かっているなら `coupling-meter.yaml` に原著の目盛りで書く。git の観測値より優先する。

```yaml
volatility:
  'App\Domain\Pricing': 10   # コアサブドメイン。これからも変わり続ける
  'App\Legacy': 1             # 塩漬け。触らない
```

## 測らないもの

- **本物の変動性**。git 履歴から出るのは観測された変更頻度であって、設計が悪くて頻繁に変わっている場合と、
  危険で誰も触れていない場合を区別しない。原著はソース管理の解析とドメイン分析を併用すべきとしている。
  変更の種類で重み付けはするが、サブドメインの判定（コア、支援、汎用）はしない。分かっているなら `volatility` で宣言する
- **チームの構造**。git のコミット著者を所有者の代わりに使っているだけで、実際のチーム編成、勤務地、タイムゾーンは見ていない
- **実行時結合による距離の一部**。`dispatch` / `event` / `broadcast` で渡す非同期は見るが、Observer やリスナーの登録、スケジューラ経由の起動は追えない
- **実行時の依存の一部**。`app(Foo::class)` や `$this->app->make(Foo::class)` のようにクラス名が式として書かれていれば追える。
  文字列で組み立てたクラス名、Facade 越しの呼び出し、設定ファイル経由の解決は追えない
- **依存の数**。原著は関係の数ではなく性質を見る立場を取る。実装もそれに従うため、参照 1 箇所の依存が上位に来る。読みにくければ `--weight-by-references`
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
composer check      # phpstan (level max) → php-cs-fixer (dry-run) → phpunit
composer phpstan    # 静的解析だけ
composer cs-fix     # コードスタイルを直す
composer test       # テストだけ
```

CI では PHP 8.2 から 8.5 の各バージョンで phpstan とテスト（PCOV でカバレッジを取り Codecov に送る）を回し、php-cs-fixer で整形を確認する。

`tests/fixtures/` に判定を確かめるための小さなプロジェクトを置いてある。

## ライセンス

MIT
