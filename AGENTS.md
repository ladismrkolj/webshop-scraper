# AGENTS.md (Master Project Rules)

## Project Overview
We are building a daily automated product import pipeline. 
1. `scraper-app/` uses Python & Scrapy to extract daily competitor product listings and dump them to a structured JSON file.
2. `prestashop-product-import/` is a custom PHP module that registers a server cron job to parse that JSON file and programmatically create/update products in the PrestaShop catalog daily.

## 🧠 Model Token-Saving Hierarchy
- **Claude Code (The Architect):** 
  - Operate strictly at `medium` effort.
  - Act as the Quality Gatekeeper, System Planner, and Code Reviewer.
  - DO NOT write bulk code blocks. Generate a clear markdown blueprint, then pass execution to Codex.
- **Codex CLI (The Builder):**
  - Act as the primary raw code executor.
  - Generate spiders, database sync scripts, PHP module hooks, and native tests.

## 🧪 Testing & Validation Pipelines (Mandatory)
Before Claude presents any completed code diff to the user, the models must autonomously run and pass the validation gates relative to the folder they are altering:

### 🐍 BACKEND 1: `scraper-app/` (Python/Scrapy)
- **Format & Lint:** Codex must format code using `black` and lint via `flake8`.
  - Command: `cd scraper-app && black . && flake8`
- **Unit Testing:** We use `pytest` to mock Scrapy responses. Codex *must* write a corresponding test file in `tests/` for any new selector logic.
  - Command: `cd scraper-app && pytest`
- **Runtime Checks:** Spiders must utilize native **Scrapy Contracts** (`@url`, `@returns`) in docstrings.
  - Command: `cd scraper-app && scrapy check`

### 🐘 BACKEND 2: `prestashop-product-import/` (PHP/PrestaShop)
- **Format & Lint:** Code must strictly comply with PrestaShop's `PHP-CS-Fixer` standards.
  - Command: `cd prestashop-plugin && ./vendor/bin/php-cs-fixer fix --dry-run`
- **Unit Testing:** We use `PHPUnit` to test the JSON ingestion and product parsing logic.
  - Command: `cd prestashop-plugin && ./vendor/bin/phpunit`
- **PrestaShop Compliance:** Codex must validate module syntax against standard PrestaShop module validator schemas, ensuring correct use of `Db::getInstance()->execute()` and security escaping (`pSQL`).

## 🔄 Automated Execution & Self-Healing Loop
1. **Context Aware Switch:** Claude identifies which folder is being modified based on the prompt.
2. **Blueprint:** Claude maps out the changes explicitly referencing Python/Scrapy or PHP/PrestaShop standards.
3. **Execute:** Codex writes the code.
4. **Test:** Claude triggers the local test suite corresponding to that specific folder.
5. **Self-Heal:** If any test fails, Claude intercepts the error log, passes it back to Codex, and orders a fix. (Max 3 attempts before asking the human user).

## 🔒 Environment & Dependency Rules
- **Python (scraper-app/):** All packages MUST be isolated inside a local virtual environment (`.venv`). Never use system pip globally.
- **PHP (prestashop-plugin/):** All packages MUST be isolated locally inside the module using Composer. Never install PHP tools globally via PEAR or system-wide configurations. Run operations via local vendor binaries (e.g., `./vendor/bin/phpunit`).



