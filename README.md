# BestiaBase

*Versione italiana: [README-IT.md](README-IT.md).*

**BESTIA** stands for **B**LAST **E**nvironment for **S**earch, **T**racking, **I**nspection and
**A**nalysis. “Base” refers to the sequence databases and the platform that makes them accessible.

BestiaBase is a web server for similarity searches with **NCBI BLAST+** (blastn, blastp, blastx,
tblastn, tblastx) on sequence databases. Searches run in the background, results are stored and can
be browsed, downloaded and shared, and databases can be public or restricted to selected users.

## Origin

BestiaBase is **open source**. It was born from the *idea* behind
[ViroblastStandalone](https://github.com/MullinsLab/ViroblastStandalone); it is **not a fork and not a
refactoring** of it. That project is deprecated, unmaintained and affected by security issues.

BestiaBase has no dependency on Perl and uses an up-to-date, easily maintainable stack: PHP 8.5,
Symfony, Doctrine, MySQL 9.7, Redis, Twig and Stimulus.

## License

Released under the [GNU General Public License v2.0](LICENSE) (`GPL-2.0-only`).

## Installation

See [SETUP.md](SETUP.md).

## Contributing

BestiaBase follows the standard set by its creator, **Stefano Perrini**. Contributions are welcome
as long as they respect it:

- **Small, focused changes.** One pull request, one purpose, with a clear description.
- **Strict, typed PHP.** `declare(strict_types=1)` everywhere, explicit types, `final` classes,
  native enums, Symfony conventions (attributes, autowiring, thin controllers).
- **Security first.** No paths, binary names or CLI options from the client; processes only with an
  argv array; no secrets in the repository.
- **Tests with every change.** New behavior is exercised the way a caller would use it (HTTP request
  or service call). The suite must stay green and coverage at 100%.
- **Quality checks must pass** before opening a pull request:

  ```bash
  composer fix    # Rector + php-cs-fixer
  composer qa     # Rector, php-cs-fixer, PHPStan (level max), PHPUnit, linters, browser tests
  ```

  Do not lower the PHPStan level, add generic ignores or disable a check to make it pass.
- **Documentation up to date.** Record every visible change in `CHANGELOG.md` (Keep a Changelog)
  and keep `TODO.md` aligned.
- **Respect and clarity.** Be constructive in reviews and issues; report security problems privately
  to the maintainer instead of opening a public issue.
