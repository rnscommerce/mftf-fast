# mftf-fast

A drop-in for `vendor/bin/mftf` that makes generating one test take seconds
instead of half a minute.

MFTF re-parses the whole XML corpus on every invocation. References are flat
global names with no module qualifier, so every handler builds a registry of
every object before it can resolve any one of them, and nothing is kept
between runs. That is 15 to 25 seconds before a single test is generated.
`mftf-fast` snapshots the parsed handlers into `var/mftf-cache` and puts them
back on the next run. The snapshot is keyed on the corpus, the enabled
modules and the framework version, so a change to any of them is a miss, not
a wrong answer.

## Install

```
composer require --dev rnscommerce/mftf-fast
```

Then use `vendor/bin/mftf-fast` wherever you would use `vendor/bin/mftf`:

```
vendor/bin/mftf-fast generate:tests AdminLoginSuccessfulTest
vendor/bin/mftf-fast run:test AdminLoginSuccessfulTest
```

Every MFTF command works. The first run parses and snapshots; every run after
that restores. Three commands are its own:

| Command | What it does |
| --- | --- |
| `cache:status` | which handlers are snapshotted, and whether the snapshots are still valid |
| `cache:warm` | parse everything now and snapshot it |
| `cache:clear` | remove the snapshots |

Set `MFTF_FAST_QUIET=1` to silence the one line it prints to stderr about
what it restored and what it snapshotted.

## Which release

The snapshots reflect MFTF's own class shapes, so each major of this package
follows a major of MFTF. Composer picks the right one for the framework you
have.

| mftf-fast | MFTF |
| --- | --- |
| 4.x | 4.x |
| 5.x | 5.x, to follow |
| 6.x | 6.x, to follow |

## Carried by MFTF Studio

[MFTF Studio](https://github.com/rnscommerce/mftf-studio) carries a copy of
this package for each MFTF it ships, and uses yours when your project has one
installed. Outside a project the entry point loads its own three classes and
then the project's autoloader, which is all it needs: `ReflectionClass`,
`ReflectionProperty` and `Throwable` are its only dependencies.

## Licence

MIT.
