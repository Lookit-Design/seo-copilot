# Lookit SEO Copilot

[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Lint](https://github.com/Lookit-Design/seo-copilot/actions/workflows/lint.yml/badge.svg)](../../actions/workflows/lint.yml)
[![Coding Standards](https://github.com/Lookit-Design/seo-copilot/actions/workflows/coding-standards.yml/badge.svg)](../../actions/workflows/coding-standards.yml)
[![Plugin Check](https://github.com/Lookit-Design/seo-copilot/actions/workflows/plugin-check.yml/badge.svg)](../../actions/workflows/plugin-check.yml)
[![Tests](https://github.com/Lookit-Design/seo-copilot/actions/workflows/test.yml/badge.svg)](../../actions/workflows/test.yml)

Bulk-edit Yoast focus keyphrases and meta descriptions, auto-fill those fields on publish, and audit on-page SEO health from one WordPress admin screen.

Supports `WordPress >= 5.9` on `PHP >= 7.4`.

## Table of Contents

- [Getting Started](#getting-started)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [Features](#features)
- [Security and Privacy](#security-and-privacy)
- [Development](#development)
  - [Setup](#setup)
  - [Running the Test Suite](#running-the-test-suite)
  - [Coding Standards](#coding-standards)
  - [Continuous Integration](#continuous-integration)
- [Contributing](#contributing)
- [License](#license)

## Getting Started

### Installation

This plugin is installed from GitHub, not from WordPress.org.

1. Clone or copy this repository into `/wp-content/plugins/lookit-seo-copilot`.
2. Activate **Lookit SEO Copilot** through the **Plugins** menu in WordPress.
3. Yoast SEO should be active; the plugin writes Yoast meta fields.

### Configuration

Open **SEO Copilot → SEO Settings** to:

* Point the AI engine at your Lookit platform endpoint (optional; used for Nova Lite fills).
* Build reusable keyphrase, description, and title templates.
* Set how many related keyphrases to generate.

Auto SEO Manager rules live on the same plugin screen.

## Features

* **Bulk Editor** — edit keyphrases and meta descriptions across post types, including JetEngine CPTs, with filters, templates, and bulk fill.
* **Auto SEO Manager** — per-post-type rules that fill the focus keyphrase, meta description, related keyphrases, and SEO title when a post is published.
* **SEO Health** — on-page checks (keyphrase, titles and meta, content, alt text, internal links) with a score, drill-down, and a priority-fix list.
* **Lock SEO Fields** — a per-post metabox so Auto SEO does not overwrite a page you have already tuned.

Related keyphrases can use content extraction plus the free [Datamuse](https://www.datamuse.com/api/) API. Optional AI fills go through the Lookit platform webhook to Amazon Bedrock; no AWS keys are stored in WordPress.

## Security and Privacy

* Stored OpenRouter keys from older versions are **not autoloaded** and are **removed on uninstall**.
* The plugin does not echo leftover API keys into admin screens.
* On uninstall, plugin options are deleted from the database.

Datamuse is called only when related-keyphrase generation is enabled. The Lookit webhook is called only on an explicit AI fill. See Datamuse's [API notes](https://www.datamuse.com/api/).

## Development

### Setup

Install the development dependencies with [Composer](https://getcomposer.org/):

```bash
composer install
```

### Running the Test Suite

The integration tests run against a real WordPress test install and a MySQL database. Install the test suite once, then run PHPUnit:

```bash
# bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

composer test
```

### Coding Standards

This project follows the WordPress Coding Standards and checks PHP cross-version compatibility:

```bash
composer phpcs    # check coding standards
composer phpcbf   # auto-fix what can be fixed
composer compat   # check PHP 7.4+ compatibility
composer lint     # php -l syntax check on all files
```

### Continuous Integration

Every push and pull request runs the following GitHub Actions workflows:

| Workflow | Purpose |
| --- | --- |
| [Lint](../../actions/workflows/lint.yml) | `php -l` syntax check across the supported PHP versions |
| [Coding Standards](../../actions/workflows/coding-standards.yml) | WordPress Coding Standards (PHPCS) |
| [Plugin Check](../../actions/workflows/plugin-check.yml) | Official WordPress Plugin Check |
| [Test](../../actions/workflows/test.yml) | PHPUnit across a broad WordPress × PHP matrix |

A scheduled [Version Monitor](../../actions/workflows/version-monitor.yml) workflow watches for new PHP and WordPress releases so compatibility can be reviewed proactively.

## Contributing

Bug reports and pull requests are welcome on [GitHub](../../issues).

## License

This plugin is available as open source under the terms of the [GPL-2.0-or-later License](https://www.gnu.org/licenses/gpl-2.0.html).

---

_Lookit&reg; is a registered trademark of ZENOVA CORP. Yoast is a trademark of its respective owner; this plugin is an independent integration and is not affiliated with, sponsored by, or endorsed by Yoast._
