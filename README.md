# Laravel Pro Docs CLI Tool

[![Tests](https://github.com/akshit-arora/laravel-pro-docs/actions/workflows/tests.yml/badge.svg)](https://github.com/akshit-arora/laravel-pro-docs/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)

A high-performance PHP CLI tool that:
1. Scans `laravel/framework` repository Git history and AST symbols (`nikic/php-parser`).
2. Builds a JSON lookup index of when methods, Facade docblocks, and helper functions were introduced, along with their GitHub PR link.
3. Automatically rewrites `laravel/docs` Markdown files using `league/commonmark` to inject `<x-since>` version and PR badges.

---

## Requirements

- PHP 8.2+
- Composer
- Git (for analyzing framework history)

## Installation

```bash
git clone https://github.com/akshit-arora/laravel-pro-docs.git
cd laravel-pro-docs
composer install
```

> **Note on Initial Setup:**  
> When you first clone the repository, the `storage/symbols_index.json` and `output/docs/` directories are empty. You must build the index using a local clone of the Laravel framework (`build:index`) and then rewrite the docs (`docs:rewrite`) before running the preview server.

---

## Commands

### 1. Build Framework Symbol & PR Index (`build:index`)

Scans release tags in `laravel/framework` (from `v9.0.0` upwards), extracts PR numbers from commit messages, parses AST symbols, and writes the index to JSON:

```bash
php bin/console build:index --framework-path=/path/to/laravel/framework
```

#### Options:
- `--framework-path`: Path to local clone of `laravel/framework` (required).
- `--from-tag`: Starting release tag (default: `v9.0.0`).
- `--to-tag`: Ending release tag (optional, default: latest tag).
- `-o, --output`: Output JSON path (default: `storage/symbols_index.json`).

#### Example Output (`storage/symbols_index.json`):
```json
{
  "Number::currency": {
    "version": "v10.38.0",
    "pr": 49451,
    "pr_url": "https://github.com/laravel/framework/pull/49451"
  }
}
```

---

### 2. Rewrite Laravel Documentation (`docs:rewrite`)

Parses Markdown files in `laravel/docs` and injects `<x-since>` badges after matching inline code tokens and heading definitions:

```bash
php bin/console docs:rewrite --docs-path=/path/to/laravel/docs
```

#### Options:
- `--docs-path`: Path to `laravel/docs` markdown files directory (required).
- `-i, --index-path`: Path to `storage/symbols_index.json` (default: `storage/symbols_index.json`).
- `-o, --output-path`: Target output directory for rewritten markdown (default: `output/docs`).
- `--overrides-path`: Directory with override markdown files applied over `--docs-path` (default: `overrides`). Mirrors CI's "Apply Overrides" step so local preview matches deploys.
- `--dry-run`: Simulate rewriting without writing files.

---

### 3. View / Browse Docs in Browser (`docs:serve`)

Launch a local web preview server with sidebar navigation, clean typography, and interactive `<x-since>` badges linking to GitHub PRs:

```bash
php bin/console docs:serve
```

#### Options:
- `--docs-path`: Path to rewritten markdown directory (default: `output/docs`).
- `-p, --port`: Port to listen on (default: `8080`).
- `--host`: Host to bind to (default: `127.0.0.1`).
- `-i, --index-path`: Path to `storage/symbols_index.json` for search (default: `storage/symbols_index.json`).

Open your browser at **http://127.0.0.1:8080** to browse the rendered documentation!

#### Examples of Rewriting:
- **Inline Code**:
  - *Input*: `` `Number::currency($amount)` ``
  - *Output*: `` `Number::currency($amount)` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" /> ``
- **Headings**:
  - *Input*: `### Number::currency`
  - *Output*: `### Number::currency <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" />`
- **Guardrails**:
  - Bare instance methods (like `->get()`, `->find()`, `->save()`) and fenced code blocks (` ```php ... ``` `) are strictly untouched.
  - Rewriting is idempotent: existing `<x-since>` tags are updated or preserved rather than duplicated.

---

### 4. Build Static HTML Site (`docs:build-static`)

Builds a deployable static site (used by GitHub Pages) from rewritten markdown. Uses the same badge rendering as `docs:serve`:

```bash
php bin/console docs:build-static --docs-path=output/docs --output-path=dist
```

#### Options:
- `--docs-path`: Path to rewritten markdown directory (default: `output/docs`).
- `-o, --output-path`: Target output directory for static HTML (default: `dist`).
- `-i, --index-path`: Path to `storage/symbols_index.json` for the search dataset (default: `storage/symbols_index.json`).

> Both `docs:serve` and `docs:build-static` render through the same shared
> pipeline (`src/Support/MarkdownPipeline`, `BadgeRenderer`, `SidebarBuilder`,
> `TocBuilder`, `SearchIndexBuilder`, `Layout`), so preview and deployed HTML
> stay identical. `Source` links point at the introducing tag; API links point
> at the living `master` API docs.
>
> Search behavior: clicking a symbol row deep-links to the exact documenting
> section (e.g. `numbers.html#formatting-numbers` — `docs:rewrite` writes this
> map to `output/docs/symbol_pages.json`); the API/PR badges open only via
> their own links. Heading anchors are slugified identically at rewrite time
> (`Support\Anchor::fromMarkdownHeading`) and render time
> (`Support\Anchor::fromHtmlHeading`), excluding badges and `{.markers}`.
>
> The Markdown pipeline allows the `<style>` blocks Laravel's own docs ship
> (e.g. the 3-column method-list layout) while still escaping dangerous tags
> like `<script>`; badges inside those lists are re-scoped to inline pills so
> the docs' `display: block` list CSS doesn't blow them up.

---

## Running Tests

```bash
composer test
```
