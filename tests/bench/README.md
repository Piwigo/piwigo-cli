# Benches

The suite in `tests/run.php` runs `bin/pwg.php` for real, so it can only reach the
commands that need no database, plus the read-only ones when a gallery is there.

A bench fills the other half: it loads one command file with **fake core functions**
and calls its callbacks directly. No database, no network, no gallery. It proves the
decisions a command makes (who is protected, which order, which message, which exit
code) without proving that the core behaves like the fake.

Each bench is one file, run it with a scenario name:

```bash
php tests/bench/user.php add-dry
php tests/bench/plugin.php update-all
```

`run_bench.php` plays every scenario of every bench and compares the output to the
expectations written next to them, so the whole thing stays a test and not a demo:

```bash
php tests/bench/run_bench.php
```
