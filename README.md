# mftf-fast

A drop-in for `vendor/bin/mftf` that makes generating one test take seconds
instead of half a minute.

MFTF re-parses the whole XML corpus on every invocation. References are flat
global names with no module qualifier, so every handler builds a registry of
every object before it can resolve any one of them, and nothing is kept
between runs. `mftf-fast` snapshots the parsed handlers into `var/mftf-cache`
and puts them back on the next run.

Generating one test on Magento Open Source 2.4.7-p10 with MFTF 4.7.6:

| | |
| --- | --- |
| `vendor/bin/mftf` | 31 s |
| `mftf-fast`, first run, which parses and snapshots | 26 s |
| `mftf-fast`, every run after | 2 to 4 s |

## Install

The package is not on Packagist, so Composer is told where it lives first:

```
composer config repositories.mftf-fast vcs https://github.com/rnscommerce/mftf-fast
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

`--force` makes MFTF merge every module, enabled or not, so a run with it and
a run without it parse two different corpora and keep a snapshot each.
`cache:status --force` describes the ones `generate:tests --force` restores,
`cache:status` the others, and `cache:clear` removes both.

Set `MFTF_FAST_QUIET=1` to silence the one line it prints to stderr about
what it restored and what it snapshotted.

Run it from inside the project: it finds the project by walking up from the
working directory to the first folder that holds `dev/tests/acceptance` and
`vendor`.

## What makes a snapshot stale

A snapshot is keyed on everything that changes what MFTF would have parsed, so
a change to any of it is a miss and one slow run, never a wrong answer:

- the path, size and modification time of every XML file under a `Test/Mftf`
  folder in `vendor` and `app/code`
- the modules enabled in `app/etc/config.php`
- whether the run had `--force`
- the MFTF version, the PHP version and `MAGENTO_BP`

Test XML kept anywhere else, `dev/tests/acceptance/tests/functional` included,
is not part of the key. After changing a file there, run `cache:clear`.

A handler that loaded less than the XML declares is not snapshotted, and the
line on stderr says which and what was missing. It happens when a run without
`--force` meets an installation with a module switched off.

Snapshots are written with igbinary when the extension is there, and with
`serialize` when it is not. igbinary is worth installing: it stores the test
registry in 12 MB against 152 MB, and reads it back in a third of the time.

## Which MFTF

Any from 4.0 up. The package reflects on whatever framework is loaded rather
than naming its shapes, and the snapshots are keyed on that framework's
version, so an upgrade is a clean miss and one slow run, never a wrong answer.
Measured on MFTF 4.7.6 and 5.3.0.

## Outside a project

The package does not have to be installed in the project it runs against. A
copy kept anywhere works: the entry point loads its own three classes and then
the project's autoloader, which is all it needs. `ReflectionClass`,
`ReflectionProperty` and `Throwable` are its only dependencies.

The framework need not be the one in vendor either. Whatever bootstrapped MFTF
before the entry point ran - through `auto_prepend_file`, say - is the
framework `FW_BP` names, and the snapshots are keyed on that one. An MFTF kept
outside the project and the project's own share `var/mftf-cache` without ever
restoring each other's graphs.

## Licence

MIT.
