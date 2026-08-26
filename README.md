# Piwigo CLI

* Internal name: `piwigo-cli` (directory name in `plugins/`)
* Plugin page: http://piwigo.org/ext/extension_view.php?eid=

The official command line for Piwigo. Diagnose the server (`doctor`), get the gallery in numbers (`status`), manage users and more from a shell.

```bash
php <path-to-your-piwigo>/plugins/piwigo-cli/bin/pwg.php <command> [options] [arguments]
```

## Installation

Install it like any plugin, from the admin (Plugins > Add a plugin) or by copying this directory into `plugins/`. The directory must be named `piwigo-cli`. **Activation is not required**: the CLI is not loaded by the gallery, it boots Piwigo by itself from the shell.

To get a global `pwg` command instead of the full path:

```bash
php <path-to-your-piwigo>/plugins/piwigo-cli/bin/pwg.php shortcut
```

It links the launcher into `/usr/local/bin` (revert with `shortcut --revert`).
Note: updating the plugin re-extracts its files, which drops the executable bit on the launcher. If `pwg` answers "Permission denied" after an update, run the `shortcut` command above again, it restores the bit (or simply `chmod +x <path-to-your-piwigo>/plugins/piwigo-cli/bin/pwg.php`).

You can now use:

```bash
pwg doctor
```

The CLI can be disabled with `$conf['allow_cli'] = false` in `local/config/config.inc.php` (defaults live in `cli_default_config.php`).

## Usage

A command is declared as `namespace.command` and typed either way:
`pwg user add` and `pwg user.add` are the same command. The longest declared name wins, whatever follows becomes arguments.

Run `pwg list` to see the available commands, `pwg <command> --help` for one command.

## The contract

Every command follows these rules. They are enforced by the engine where possible, by review where not.

### Exit codes

| code | constant | meaning |
|------|----------|---------|
| 0 | `PwgCommand::SUCCESS` | the command ran fine |
| 1 | `PwgCommand::ERROR` | the command ran and failed, or was aborted by the user |
| 2 | `PwgCommand::INVALID` | the command never ran, the input was wrong |

The engine exits `INVALID` on its own for unknown commands, unknown options, missing values and missing operands. A callback returns `SUCCESS` or `ERROR` (returning nothing means `SUCCESS`).

### Identity

A `full` boot command acts as the **webmaster** (`$conf['webmaster_id']`), not as a guest: shell access is the security boundary. There is no password involved.

### Global options

Every command accepts these without declaring them. None of them lands in `$args`. The callback only receives its own declared args and operands, the global flags feed an engine state read through `PwgCommand`:

| option | read with | rule |
|--------|-----------|------|
| `--dry-run` | `PwgCommand::is_dry_run()` | a command that writes anything MUST honor it: report what would happen, change nothing, exit `SUCCESS` |
| `-y, --yes` | (feeds `confirm()`) | answers yes to every confirmation |
| `--verbose` | `PwgCommand::is_verbose()` | print debug details (traces, full errors), stay quiet otherwise |
| `-h, --help` | (engine only) | shows the command help, boots nothing |

Their names **and** their short letters are reserved: declaring an arg named like a global option, or reusing `-h`/`-y`, is refused at registration. `pwg --version` (or bare `pwg -v`, no command) prints the Piwigo and PHP versions.

### Destructive commands

A command that deletes or overwrites something MUST ask through `PwgCommand::confirm('...')` before acting:

- the answer defaults to **no**: pressing enter, or a closed STDIN (cron,
  CI), aborts;
- `--yes` is the only non-interactive way to say yes;
- an aborted command reports it and returns `ERROR`.

So the shape of every destructive command is:

```php
function cli_things_delete(array $args)
{
  // ... gather what would be deleted ...

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.count($things).' things');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.count($things).' things?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  // ... delete, then report ...
}
```

## Declaring a command

Commands live in `commands/cli.<namespace>.php` inside the plugin, listed explicitly in `PwgCli::load_commands()`. Nothing is auto-discovered.

```php
$cli->add_command('album.create', 'cli_album_create',
  array(
    'description' => 'Create an album',
    'boot' => 'full', // none | minimal | full: how much of Piwigo to load
    'hidden' => false, // hidden commands only show in "list -a"
    'args' => [
      'parent' => [
        'short' => 'p',
        'info' => 'Parent album id',
        'default' => null,
      ],
      'private' => [
        'info' => 'Make it private',
        'flag' => true, // true when passed, false otherwise, never a value
      ],
    ],
    'operands' => [ // positional arguments, in order
      'name' => [
        'info' => 'Album name', // mandatory unless it has a default
      ],
      'more' => [
        'info' => 'More names',
        'multiple' => true, // catches everything left, always an array
      ],
    ],
  )
);
```

The callback receives one flat array: its declared options and operands by name, nothing else. Values arrive as `--name value`,   `--name=value` or `-n value`; everything after a bare `--` is taken as operands verbatim.

### Operand order rules

Operands are positional: the parser distributes the typed words in declaration order. An operand is **optional** when it has a `default` or is `multiple`. The only valid shape is mandatory ones first, then defaulted ones, then at most one `multiple`, last. Two declarations are rejected at registration because no user input could ever satisfy them:

```php
'operands' => [
  'files' => ['multiple' => true], // eats every remaining word...
  'name' => [],                    // ...so "name" can never be filled
]
// [ERROR] Command "x": operand "name" is unreachable, the multiple operand must be the last one

'operands' => [
  'album' => ['default' => 'root'], // optional...
  'file' => [],                     // ...so one single typed word is ambiguous:
]                                   //    album or file?
// [ERROR] Command "x": mandatory operand "file" cannot follow an optional one
```

### A complete example

```php
$cli->add_command('album.import', 'cli_album_import',
  array(
    'description' => 'Import photos into an album',
    'boot' => 'full',
    'args' => [
      'quality' => [
        'short' => 'q',
        'info' => 'JPEG quality',
        'default' => 85,
      ],
      'private' => [
        'info' => 'Make the album private',
        'flag' => true,
      ],
    ],
    'operands' => [
      'album' => ['info' => 'Target album'],                     // mandatory
      'parent' => ['info' => 'Parent album', 'default' => null], // defaulted
      'files' => ['info' => 'Files to import', 'multiple' => true], // multiple, last
    ],
  )
);
function cli_album_import(array $args)
{
  // $args holds ONLY the declared names, e.g.:
  // ['quality' => '90', 'private' => true, 'album' => 'Vacances', 'parent' => null, 'files' => ['a.jpg', 'b.jpg']]

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would import '.count($args['files']).' files into "'.$args['album'].'"');
    return PwgCommand::SUCCESS;
  }

  // ... import, then report ...
  PwgCommand::success(count($args['files']).' files imported into "'.$args['album'].'"');
  return PwgCommand::SUCCESS;
}
```

All these invocations reach the same callback:

```bash
pwg album import Vacances --quality 90 --private a.jpg b.jpg
pwg album.import Vacances -q=90 --private a.jpg b.jpg
pwg album import "Vacances 2026" root --dry-run
pwg album import Vacances root -- -dashed-name.jpg
```

And `pwg album import --help` renders from the spec alone:

```
Description:
  Import photos into an album

Usage:
  pwg album import [options] <album> [<parent>] [<files>...]

Arguments:
  album    Target album
  parent   Parent album
  files    Files to import

Options:
  -q, --quality   JPEG quality [default: 85]
      --private   Make the album private
  -h, --help      Show this help
      --dry-run   Simulate, do not change anything
  -y, --yes       Answer yes to every confirmation
      --verbose   Show debug details (stack traces, full errors)
```

## Directories the core writes to

The full `is_writable`/`mkgetdir` sweep of the core, and who should check what:

| directory | used for | checked by |
|-----------|----------|-----------|
| `_data/` | Smarty compilation (`templates_c/`), derivatives cache (`i/`), `logs/`, mail copies (`tmp/`), update zips, `*.cache` | **the engine**, at every `full` boot (the only boot blocker) |
| `upload/` | added photos, ws chunks | commands that write photos |
| `local/config/` | install writes `database.inc.php`, some upgrades append to config files | `install` / upgrade commands |
| `local/logo/`, `local/watermarks/` | admin uploads | the admin features using them |
| `plugins/`, `themes/`, `language/` (+ their trash) | installing extensions from the web UI | nobody blocks on these: read-only is a legitimate hardened setup |

`doctor` reports them all, none of them blocks it.

## Tests

```bash
php plugins/piwigo-cli/tests/run.php
```

Each case runs `bin/pwg.php` as a subprocess and checks exit code and output. The `test.*` commands are the fixtures: hidden, `boot none`, no database needed. Any change to the engine or a command ships with its cases.

## Support

* Forums: https://piwigo.org/forum/
* Bugs and feature requests: open an issue on the plugin repository.
