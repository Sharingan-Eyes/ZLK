# ZLK PHP Utilities

This repository contains multiple PHP utility scripts used for data scraping, document transfer, and static email UI templates.

## Repository Structure

- `YahooDataScraper/`: scrapes Yahoo Finance loser stocks, parses price/float data, and inserts records into MySQL.
- `CaseNote2SharePointFilePusher/`: downloads case-note files from DB links (SharePoint or direct URL) and re-uploads them to SharePoint, then marks rows as processed.
- `CORE2SharePointFilePusher/`: downloads CORE litigation update files from DB links, uploads them to SharePoint, then marks rows as processed.
- `Email Template/`: static Bootstrap HTML mockups for compose/inbox/claim/non-claim/sent/draft/trash/contact screens.

## Tech Stack

- PHP (CLI/web execution style scripts)
- Composer dependencies:
  - `vlucas/phpdotenv` (all script folders)
  - `guzzlehttp/guzzle` (SharePoint pusher folders)
- MySQL via PDO
- Microsoft Graph API for SharePoint operations

## Environment Configuration

Each script folder includes an `env.example` file. Copy it to `.env` in the same folder and fill in values.

### Required keys

For all script folders:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

For SharePoint pusher folders (`CaseNote2SharePointFilePusher`, `CORE2SharePointFilePusher`) also set:

- `TENANT_ID`
- `CLIENT_ID`
- `CLIENT_SECRET`
- `SITE_HOSTNAME`
- `SITE_PATH`

## Setup

Run these inside each script folder you want to use:

```bash
composer install
cp env.example .env
```

Then update `.env` values with your DB and Microsoft Graph credentials.

## Running Scripts

Examples:

```bash
php YahooDataScraper/index.php
php CaseNote2SharePointFilePusher/index.php
php CORE2SharePointFilePusher/index.php
```

Notes:

- `init.php` exists in both SharePoint pusher folders as alternate query/version entry points.
- Scripts are designed as batch jobs and can run for a long time (`max_execution_time` disabled in pusher scripts).

## Deployment

Each script folder contains a `.cpanel.yml` used for cPanel deployment tasks, copying key files and `vendor/` into folder-specific cron locations.

## Git Ignore

The repo ignores per-project:

- `vendor/`
- `composer.lock`
- `.env`

for each script folder.

## Important Considerations

- Current scripts disable SSL verification in several cURL/Guzzle calls (`verify => false`, `CURLOPT_SSL_VERIFYPEER => false`).
- Some SharePoint path/drive values are hardcoded in script logic.
- Error handling is mostly echo/continue style; consider adding structured logging if these jobs are business-critical.