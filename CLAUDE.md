# laravel-campaigns

TODO(scaffold): one paragraph, in plain language, on what the package does and for whom.

## Commands

```bash
composer test                  # lint + static analysis + type coverage + tests, the gate before every commit
composer lint                  # fix code style (Pint)
composer test:lint             # check code style without fixing
composer test:types            # PHPStan
composer test:type-coverage    # Pest type coverage (minimum 100%)
composer test:unit             # Pest

vendor/bin/pest tests/SomeTest.php          # one file
vendor/bin/pest --filter="test name here"   # one test
```

## Architecture

TODO(scaffold): every directory and main class under src/ and what each one owns, and how they depend on each other.

## Key design decisions

TODO(scaffold): the decisions a future session would otherwise undo, each with its reason.

## Extension points

TODO(scaffold): how users extend the package (config-bound classes, events, interfaces, macros), or delete this section when there are none.

## Testing

TODO(scaffold): the test patterns and helpers, what runs against real tools or servers and how those tests skip themselves, and anything a test must clean up.

## Development notes

- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- The PHP floor is 8.2: no typed class constants (`public const string X`) and no `new Foo()->bar()` without wrapping parentheses. PHPStan's `type_coverage.constant` is 0 for that reason.
- The package depends on split `illuminate/*` components, not `laravel/framework`, so `src/` uses facades and the container instead of foundation helpers (`config()`, `base_path()`, `now()`). Every `Illuminate\*` class or facade `src/` uses needs its component in `require`; testbench installs the whole framework, so a green suite doesn't prove it.
