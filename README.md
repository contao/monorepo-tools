Contao Monorepo Tools
=====================

[![](https://img.shields.io/packagist/v/contao/monorepo-tools.svg?style=flat-square)](https://packagist.org/packages/contao/monorepo-tools)
[![](https://img.shields.io/packagist/dt/contao/monorepo-tools.svg?style=flat-square)](https://packagist.org/packages/contao/monorepo-tools)

This project provides tools
to work with a <abbr title="Mono Repository">[monorepo]</abbr>.
The main usage is continuously splitting up a monorepo of a PHP project
into multiple read-only splits for every commit, branch and tag.
It also provides a command
that can merge the *composer.json* files of the splits
into a single *composer.json* file for the root directory
to make it possible to install the monorepo itself
as a replacement of the single packages.

[monorepo]: https://en.wikipedia.org/wiki/Monorepo

There is also a [merger](#merge-command) available that can be used
to merge multiple projects into one monorepo
as a one-time process,
but it is still experimental and should be used with caution.

Installation
------------

```sh
composer require --dev contao/monorepo-tools
```

Usage
-----

How the tools are used in action
can be seen in the *monorepo.yml* and *.github/workflows/ci.yml* files
of the projects [Contao] and [BoringSearch].

[Contao]: https://github.com/contao/contao
[BoringSearch]: https://github.com/BoringSearch/BoringSearch

### Split command

```sh
vendor/bin/monorepo-tools split [--force-push] [<branch-or-tag>]
```

Splits the monorepo into repositories by subfolder
as configured in the *monorepo.yml* file
and pushes the results to the configured remotes.

### Composer-json command

```sh
vendor/bin/monorepo-tools composer-json [--validate]
```

Updates (or validates) the root *composer.json* file
to include a union of all settings from the splits.
The autoload configuration gets rewritten
to include the correct path to the right subfolder.
Version constraints for requirements and conflicts
get merged using intersections and unions.

### Merge command

Merges multiple repositories into one monorepo.
This is intended to be a one-time process,
and most probably needs some fine-tuning.
The biggest benefit of using it is that it’s reversible,
meaning that after splitting the monorepo back
the splits commit history of the past is kept untouched.

Feel free to contact me (`@ausi`) on the [Contao Slack workspace]
if you consider using it for your project.

[Contao Slack workspace]: https://to.contao.org/slack

### Configuration

The configuration is stored in a *monorepo.yml* file
in the root of your monorepo project.

```yaml
# URL or absolute path to the remote GIT repository of the monorepo
monorepo_url: https://github.com/YOUR-VENDORNAME/YOUR-PROJECT.git

# All branches that match this regular expression will be split by default
branch_filter: /^(main|develop|\d+\.\d+)$/

# List of all split projects
repositories:

    # The first split project living in the folder /first-subfolder
    first-subfolder:

        # URL or absolute path to the remote GIT repository
        url: https://github.com/YOUR-VENDORNAME/YOUR-FIRST-SPLIT-PROJECT.git

    # Second split project living in the folder /second-subfolder
    second-subfolder:

        # URL or absolute path to the remote GIT repository
        url: https://github.com/YOUR-VENDORNAME/YOUR-SECOND-SPLIT-PROJECT.git

        # Optional mapping of commit hashes between the monorepo and split repo
        # This is only relevant to projects that got merged from existing split repos in the past
        mapping:
            # <commit-hash-in-the-monorepo>: <commit-hash-in-the-split>
            86f7e437faa5a7fce15d1ddcb9eaeaea377667b8: e9d71f5ee7c92d6dc9e92ffdad17b8bd49418f98

# Optional additional composer settings for the root composer.json
composer:
    require-dev:
        contao/monorepo-tools: ^1.0
    require:
        vendor/package: ^1.2.3
    conflict:
        vendor/package: ^1.2.3
```
