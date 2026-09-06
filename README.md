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

## Which MFTF

Any from 4.0 up. The package reflects on whatever framework is loaded rather
than naming its shapes, and the snapshots are keyed on that framework's
version, so an upgrade is a clean miss and one slow run, never a wrong answer.
Measured on MFTF 4.7.6 and 5.3.0.

## Carried by MFTF Studio

[MFTF Studio](https://github.com/rnscommerce/mftf-studio) carries a copy of
this package for each MFTF it ships, and uses yours when your project has one
installed. Outside a project the entry point loads its own three classes and
then the project's autoloader, which is all it needs: `ReflectionClass`,
`ReflectionProperty` and `Throwable` are its only dependencies.

The framework need not be the one in vendor. Whatever bootstrapped MFTF
before the entry point ran - the Studio does, through `auto_prepend_file` -
is the framework `FW_BP` names, and the snapshots are keyed on that one, so
a carried MFTF and the project's own share `var/mftf-cache` without ever
restoring each other's graphs.

## Licence

MIT.
