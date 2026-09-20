# coupling-meter

**English** | [日本語](README.ja.md)

Measure the coupling balance of a PHP project.

Instead of reporting whether a dependency exists, it reports **whether that coupling is balanced**. It measures integration strength, distance and volatility — the three dimensions from Vlad Khononov's *Balancing Coupling in Software Design* — from static analysis and git history.

## The rules from the book

```
MODULARITY = STRENGTH XOR DISTANCE
COMPLEXITY = STRENGTH AND DISTANCE
BALANCE    = (STRENGTH XOR DISTANCE) OR NOT VOLATILITY
```

A pair is modular when strength and distance cancel each other out, and complex when they line up. A pair where both are low counts as low cohesion, which is on the complex side too. When volatility is low, an unbalanced pair does no real harm.

| | Low distance | High distance |
|---|---|---|
| **Low strength** | Low cohesion (complex) | Loose coupling (modular) |
| **High strength** | High cohesion (modular) | Tight coupling (complex) |

## What it measures

| Dimension | Source | What it is |
|---|---|---|
| strength | AST | Four levels: contract, model, functional, intrusive. How much of the other side you know |
| distance | Namespaces, composer, CODEOWNERS, git, call style | Derived from the nearest common ancestor of the two modules. Capped within a single composer package, and pushed two steps further apart across packages. A module many others depend on is treated as a shared kernel and discounted one step; a pair with different owners (CODEOWNERS if declared, otherwise git authors) is pushed one step apart, as is a pair connected only through asynchronous calls such as queues and events |
| volatility | git log | The quantile of the commit count that actually touched the module. Counts are weighted by the kind of change (feat / perf = 1, fix = 0.5, refactor and other upkeep = 0.25, unclassified = 1). Modules untouched in the window are included in the distribution as zero and get a volatility of 1. Ties take the average rank, and if everything ties they land in the middle. A `volatility` entry in `coupling-meter.yaml` declares the book's scale (1 to 10) and takes precedence |
| Inferred volatility | The above, combined | Volatility received from a dependency according to strength. Inferred volatility, section 9.5 of the book |
| Kind of change | git log | Conventional Commits prefixes split changes into evolution (feat, perf), correction (fix) and maintenance (refactor and others) |
| co-change | git log | How often the two modules change in the same commit. The Jaccard index of their commit sets (intersection / union), so that a pair involving a huge module that changes with everything does not pin at 100% |

Three and above counts as high, two and below as low. Those feed the rules and produce the quadrant and the balance verdict.

Ranking uses the balanced coupling equation from section 10.3, with all three dimensions placed on a 1 to 10 scale.

```
modularity = |strength - distance| + 1
balance    = max(|strength - distance|, 10 - volatility) + 1
```

The lower the balance, the further the pair leans toward complexity. The scale follows the book's assignment.

| Dimension | Scale |
|---|---|
| strength | contract=1, model=3, functional=8, intrusive=10 |
| distance | Same namespace=2, 3 to 7 as it gets further (library and beyond is out of scope) |
| volatility | git quantiles mapped onto 1, 3, 6, 10 |

The author notes that this is not an exact science. Adjust the ranges to fit your purpose.

## How strength is decided

| Strength | Signal in the AST |
|---|---|
| intrusive | Extending a concrete class, using a trait, reading a static property, touching the same table (below) |
| functional | `new`, static method calls, method calls on a variable of a known type, container resolution, `Job::dispatch()` / `dispatch(new Job)` / `event(new Event)` (asynchronous; affects distance) |
| model | Parameter and return types, property types, `instanceof`, `catch`, class constants, attributes, class names written as strings |
| contract | Dependencies on interfaces and abstract classes |

When the target is an interface or an abstract class, both `new` and method calls drop to contract.

### Coupling that types don't show: the same table

Coupling that never appears as a class reference, but touches the same table, is collected as `shared-table`. Since the table schema is an internal representation being shared, it is placed at intrusive.

| Source | How it is found | preset |
|---|---|---|
| Eloquent model | A class extending `Model`. Uses `protected $table` when present, otherwise the class name in snake_case, pluralized | laravel |
| Doctrine entity | The `#[ORM\Table(name: 'x')]` attribute, or `@ORM\Table(name="x")` in the docblock | symfony |
| Raw SQL | Identifiers following `FROM` / `JOIN` / `INTO` / `UPDATE` / `DELETE FROM` inside string literals | common |
| Query builder | The string argument of `DB::table('x')`, `->table('x')`, `->from('x')` | laravel / symfony |

For each pair of classes touching the same table, one reference is added in each direction. Tables with no model at all (raw SQL on both sides) become pairs too.

## preset — framework conventions

Which call hands work off asynchronously, which one goes through the container, which class owns a table: the framework decides all of that, and it cannot be read from the code. Baking it into the analyzer means silently missing it on every other framework, so it lives outside as a preset.

```bash
coupling-meter . --preset=symfony
coupling-meter . --preset=laravel,symfony   # several presets add up
coupling-meter . --preset=none              # turn off everything convention-dependent
```

When omitted, presets are guessed from the `require` section of `composer.json` (`laravel/*` and `illuminate/*` for laravel, `symfony/*` and `doctrine/*` for symfony). The presets actually used appear in the header line and in `preset` in `--json`.

| preset | What it makes visible |
|---|---|
| laravel | `Job::dispatch()` / `dispatch(new Job)` / `event(new E)` as asynchronous (which affects distance), container resolution through `app(Foo::class)` and `$container->make(Foo::class)`, Eloquent model tables |
| symfony | `$bus->dispatch(new Message)` as asynchronous, container resolution through `$container->get(Foo::class)`, Doctrine entity tables |

### Writing your own preset

Project-specific conventions go into `presets:` in `coupling-meter.yaml` and are then called by name. Combine them with the built-ins and both apply.

```yaml
presets:
  house:
    async_methods: [publish]          # treat $emitter->publish(new Event) as asynchronous
    container_methods: [locate]       # treat $registry->locate(Foo::class) as container resolution
    table_attributes: ["Persisted"]   # treat #[Persisted(name: 'x')] as a table declaration
```

```bash
coupling-meter . --preset=symfony,house
```

The available keys are `container_functions`, `container_methods`, `async_static_methods`, `async_functions`, `async_methods`, `table_builder_methods`, `entity_base_classes`, `table_properties`, `table_attributes` and `pluralize_entity_name`. Unknown keys are rejected as typos.

A preset should only hold what the framework has officially decided, in a finite set that does not change. Per-project naming conventions grow without limit, and putting them here makes presets unmaintainable.

## Install

```bash
composer require --dev techtrain/coupling-meter
```

To run it from outside the project you are measuring, install it globally.

```bash
composer global require techtrain/coupling-meter
```

Requires PHP 8.2 or newer. The project being analyzed can be any PHP version php-parser can read.

## Usage

```bash
vendor/bin/coupling-meter <path> [--include=app,src] [--exclude=legacy] [--depth=2] [--since="12 months ago"] [--top=15] [--format=text|json|samples|github]
```

When using a clone of this repository, run `bin/coupling-meter` after `composer install`.

```
coupling-meter /path/to/project --depth=2
  classes 1840 / references 12530 / modules 22 / pairs 111 / commits 2317 / preset laravel

  unbalanced pairs: 20 / 111

Fix in this order (lowest balance first: max(|strength - distance|, 10 - volatility) + 1)
   BAL  STRENGTH    STR DIST  VOL  CO-CHG  MODULE PAIR
     1  model         3    3   10     48%  Shop\Checkout -> Shop\Catalog
     2  functional    8    7   10     40%  Billing\Invoice -> Shop\Catalog
     4  intrusive    10    7   10     25%  Legacy\Reports -> Shop\Orders

Findings
  [coupling types don't show] Shop\Checkout -> Shop\Catalog
      model as far as types go, yet they changed together in 16 commits (48%)
  [intrusive and moving] Legacy\Reports -> Shop\Orders
      31 places reach inside, and 25% of commits change both
```

(The CLI prints in Japanese; the output above is translated for this document.)

How to read it. `Shop\Checkout -> Shop\Catalog` is model coupling by type, and Catalog is a shared kernel many modules use, so the distance is short (3) too. Both strength and distance are low, which is the low-cohesion quadrant — and because Catalog changes often (10) and 48% of commits change both, the balance lands at the worst value, 1. `Legacy\Reports -> Shop\Orders` reaches into Orders through inheritance and traits (10), and the two are far apart in namespace and ownership (7). Its balance of 4 is better than the two above, but being intrusive with 25% co-change makes it the most worthwhile finding to act on.

## Kinds of findings

| Kind | Condition | How to read it |
|---|---|---|
| Mutual dependency | Both directions reference each other at model or above | The layering is not doing its job |
| Inverted dependency | Both directions exist, but one goes only through an interface (contract) | Inverted with DIP. Reported for information |
| High strength and distance | Unbalanced in the tight-coupling quadrant with 20 or more references | Lower the strength or shorten the distance |
| Close but unrelated | Unbalanced in the low-cohesion quadrant with 20 or more references | Check why they sit next to each other |
| Coupling types don't show | model or below by type, yet they change together 5 or more times with a Jaccard index of 20% or more | Static analysis cannot see it. Confirm the design intent |
| Dependency written as a string | Class names written as strings in 3 or more places | Invisible to types, and renaming cannot follow it |
| Different people touch it | Owner overlap (declared in CODEOWNERS, otherwise git authors) below 1/3 with 20 or more references at functional or above | Changing them together needs coordination across people or teams |
| Inheriting the other side's volatility | Stable itself (2 or below), yet depending on a frequently changing module at functional or above in 20 or more places | Volatility its own history cannot reveal |
| Intrusive and moving | intrusive, changing together 5 or more times with a Jaccard index of 15% or more | The most worthwhile thing to fix |

## Options

| Option | Default | Meaning |
|---|---|---|
| `--include` | none | Look only at these directories directly under root |
| `--exclude` | vendor, node_modules, storage, bootstrap/cache, tests, test | Add exclusions. Directories with the same name deeper in the tree are excluded too |
| `--depth` | 2 | How many namespace segments make up one module |
| `--since` | 12 months ago | How far back to read git history |
| `--top` | 15 | How many pairs to display |
| `--json` | none | Machine-readable output. Emits every pair regardless of `--top`. Cannot be combined with `--samples` |
| `--samples` | none | Representative examples per pair with file and line. Each comes with a short note on why that strength and what would weaken it one step. Meant to be handed to an AI for judgement |
| `--preset` | auto-detect | Framework conventions (`laravel` / `symfony` / `none`, comma separated). Guessed from composer.json when omitted. Define your own under `presets:` in `coupling-meter.yaml` |
| `--rules` | auto-detect | Allowed dependencies. Looks for `coupling-meter.yaml` / `deptrac.yaml` / `deptrac.config.yaml` at root when omitted |
| `--codeowners` | auto-detect | Ownership declaration. Looks for `CODEOWNERS` at root, `.github/`, `docs/` and `.gitlab/` when omitted. Takes precedence over git authors |
| `--split` | 0 | Namespaces with more classes than this are split into their child namespaces. Prevents VOL and co-change from pinning at the ceiling for pairs involving a huge module such as `App\Models`. Splits again if a child is still too large |
| `--weight-by-references` | none | Weight the ranking by the logarithm of the reference count. Off by default, since the book looks at the nature of a relationship rather than its count. Useful when pairs with a single reference crowd the top |
| `--format` | text | Output shape (`text` / `json` / `samples` / `github`). `--json` and `--samples` are aliases for the same thing |
| `--fail-on` | none | Exit with code 1 if a pair at or below this balance is present |
| `--baseline` | none | A file recording known pairs. When given, only pairs added or made worse since then are reported |
| `--write-baseline` | none | Rewrite the `--baseline` file from the current measurement and exit |

## Using it in CI

Coupling is never fixed once and for all — it accumulates faster than it is repaired. So the tool supports recording a baseline once and stopping only what is added on top of it.

```bash
# 1. record the current state as the baseline and commit it
vendor/bin/coupling-meter . --include=src --baseline=.coupling-baseline.json --write-baseline

# 2. in CI, look only at what grew past the baseline; fail when a pair at balance 3 or below appears
vendor/bin/coupling-meter . --include=src --baseline=.coupling-baseline.json --fail-on=3 --format=github
```

Any existing codebase starts with plenty of unbalanced pairs, so failing on all of them keeps CI red forever. With a baseline, the order of repair stays a human decision while **newly added imbalance** is the only thing blocked.

`--format=github` emits GitHub Actions annotations. Representative examples carry a file and a line, so they land directly on the pull request diff (GitHub displays up to 10 annotations per job).

```
::warning title=Coupling balance%3A new pair (balance 1),file=src/Shop/Checkout/Cart.php,line=59::Shop\Checkout -> Shop\Catalog is strength model(3) / distance 3 / volatility 10. Replace the concrete type with an interface or a DTO
```

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Nothing crossed the `--fail-on` threshold |
| 1 | A pair at or below the threshold was added since the baseline |
| 2 | Nothing to measure (only one module, zero pairs). A flat namespace has no coupling to measure, so it is not reported as green |

### Example workflow

```yaml
name: coupling
on: pull_request

jobs:
  balance:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
        with:
          fetch-depth: 0   # volatility and co-change need git history
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --no-progress
      - run: >-
          vendor/bin/coupling-meter . --include=src
          --baseline=.coupling-baseline.json --fail-on=3 --format=github
```

Without `fetch-depth: 0` the history is shallow, and volatility and co-change come out empty.

## Excluding intended dependencies from findings

Dependencies you accept by design — `Application -> Infrastructure` inverted with DIP, or `Adapter -> Http` — can be excluded from findings. They stay in the ranking (`intended: true` in `--json`).

If you use deptrac, the `layers` (regular expressions over `classLike`) and `ruleset` in `deptrac.yaml` are read as they are.

```yaml
# coupling-meter.yaml (when not using deptrac)
allow:
  - 'App\Application -> App\Domain'
  - 'App\Infrastructure -> App\*'
```

`*` is a wildcard. The left side depends on the right side.

## Declaring volatility

What git yields is observed change frequency; it says nothing about what is about to change. The book asks for domain analysis (core, supporting and generic subdomains) alongside it. When you know, write it in `coupling-meter.yaml` on the book's scale. It takes precedence over the observed value.

```yaml
volatility:
  'App\Domain\Pricing': 10   # core subdomain, and it will keep changing
  'App\Legacy': 1            # frozen, nobody touches it
```

## Declaring ownership

Git authorship tells you who actually touched the code, not which team is responsible. In a repository written entirely by one person, every pair looks like it shares an owner. If the repository has a `CODEOWNERS` file (at root, `.github/`, `docs/` or `.gitlab/`), the owners declared there take precedence over git authors when computing distance.

```
# .github/CODEOWNERS
/app/Billing/   @org/billing
/app/Shop/      @org/shop
```

Matching follows gitignore rules, later lines win, and a line with no owner cancels the owners set before it. A pattern ending in `*`, such as `docs/*`, applies to that level only (same as GitHub). A module's owners are the union of the owners declared for its files.

Mixing declared and observed ownership would mark every pair between a declared module and an undeclared one as "far apart". So declarations are compared only when both modules have them, and git authors are used otherwise. `owners_declared` in `--json` tells you which comparison was used for a given pair.

## What it does not measure

- **Real volatility.** Git history yields observed change frequency, which does not distinguish "changes often because the design is bad" from "nobody dares touch it". The book asks for source control analysis and domain analysis together. Changes are weighted by kind, but subdomains (core, supporting, generic) are never inferred. Declare them under `volatility` when you know
- **Location and time zones.** Ownership comes from CODEOWNERS or git commit authors only; where the team sits and how the hours overlap is not considered
- **Part of runtime coupling in distance.** Asynchronous handoffs through `dispatch` / `event` / `broadcast` are seen, but observer and listener registration, and scheduler-driven invocation, are not
- **Part of runtime dependencies.** Class names written as expressions, such as `app(Foo::class)` or `$this->app->make(Foo::class)`, are followed. Class names assembled from strings, calls through facades, and resolution driven by config files are not
- **The number of dependencies.** The book looks at the nature of a relationship rather than its count, and so does the implementation — which means a dependency with a single reference can rank high. Use `--weight-by-references` when that makes the output hard to read
- Test code (excluded by default)

## Relation to earlier metrics

| Family | Examples | How this tool relates |
|---|---|---|
| Counting dependencies | Martin's metrics; PhpMetrics and PDepend in PHP | Classifies the nature into four levels instead of counting |
| Reporting boundary violations | deptrac, PHPArkitect | Reports the degree of imbalance and an order, not a binary verdict |
| Finding coupling in history | CodeScene, Code Maat, Qafoo changetrack | Uses the same idea. The difference is putting it in one table together with AST-derived strength |
| Classifying the quality of coupling | Structured design coupling (1974), connascence | Turns the classification into a mechanical decision |

The author also publishes a Claude Code skill, [vladikk/modularity](https://github.com/vladikk/modularity). That one hands the framework for judgement to an AI and does not measure. This tool returns the same output for the same input, and does not judge.

## Development

```bash
composer check      # phpstan (level max) → php-cs-fixer (dry-run) → phpunit
composer phpstan    # static analysis only
composer cs-fix     # fix code style
composer test       # tests only
```

CI runs phpstan and the tests on PHP 8.2 through 8.5 (collecting coverage with PCOV and sending it to Codecov), and checks formatting with php-cs-fixer.

`tests/fixtures/` holds small projects used to verify the decisions.

## License

MIT
