# JobChecker

[![Tests](https://github.com/vadimermolenko8787/jobchecker/actions/workflows/tests.yml/badge.svg)](https://github.com/vadimermolenko8787/jobchecker/actions/workflows/tests.yml)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A self-hosted job search assistant. JobChecker collects fresh postings from eight job boards, scores each one against your resume with Claude, and for the best matches writes a tailored resume and cover letter. New matches arrive in Telegram.

It is built for one person running it on their own machine: there is no registration, and your resume and job data never leave your computer except for the prompts sent to Claude.

## Features

- **Eight sources:** dou.ua, djinni.co, justjoin.it, it.pracuj.pl, jobico.io, jooble, LinkedIn and Indeed, deduplicated across boards.
- **Transparent scoring:** Claude rates four criteria (skills, stack, seniority, location and format) on anchored 0 to 10 scales with evidence for each one. The final score is a weighted sum computed in PHP, not a number the model makes up.
- **Tailored documents:** a resume and cover letter adapted to the posting, in Markdown, HTML or PDF, in the language you pick.
- **Company research (optional):** Claude searches the web for reviews and red flags about the employer.
- **Telegram notifications** with a mute button that works without a public webhook.
- **Scheduled runs** with a cron expression, plus manual runs with live progress and a stop button.
- **Full audit trail:** every run keeps its pipeline log and every HTTP request and response per source.
- **Russian and English interface.**

## How it works

```
sources ──► keyword / stop-word / location filters ──► new postings
        ──► Claude scores each posting against your resume (batched)
        ──► borderline scores are re-checked, median of three
        ──► score ≥ threshold: matched ──► resume + cover letter ──► Telegram
```

Claude is called through the local [Claude Code CLI](https://docs.anthropic.com/en/docs/claude-code) in headless mode (`claude -p`), so it runs on your Claude subscription and needs no separate API key.

## Quick start with Docker

Requirements: Docker with Compose, and a Claude subscription for the Claude Code CLI.

```bash
git clone https://github.com/vadimermolenko8787/jobchecker.git
cd jobchecker
cp .env.example .env
```

Edit `.env`:

1. Set `DB_PASSWORD` to any password; the database container is created with it.
2. Set `CLAUDE_CODE_OAUTH_TOKEN`. Create the token on a machine where Claude Code is installed with `claude setup-token`. A container can't reach your desktop login, so the token is the only way to authenticate there.
3. Generate the app key and paste it into `APP_KEY`:

   ```bash
   docker compose run --rm --no-deps web php artisan key:generate --show
   ```

Start everything and create your login:

```bash
docker compose up -d --build
docker compose exec web php artisan user:create you@example.com
```

Open http://127.0.0.1:8000 and sign in.

Compose starts three containers:

| Container | Role |
|---|---|
| `web` | the web UI on port 8000 (bound to localhost only); runs migrations on start |
| `scheduler` | `php artisan schedule:work`, runs scheduled searches and Telegram polling |
| `db` | MariaDB 11, data kept in the `db-data` volume |

`storage/` is mounted from the host, so uploaded resumes, generated documents and logs stay on your disk.

### Without Docker

You need PHP 8.3+, Composer, MariaDB or MySQL, and the Claude Code CLI logged in (`claude /login`).

```bash
composer install
cp .env.example .env        # set the DB_* values
php artisan key:generate
php artisan migrate
php artisan user:create you@example.com
php artisan serve
```

For scheduled runs add one line to your crontab:

```
* * * * * cd /path/to/jobchecker && php artisan schedule:run >> /dev/null 2>&1
```

If `claude` is not on the web server's `PATH`, set `CLAUDE_BIN` in `.env` to the full path.

## Usage

1. **Upload your resume** (PDF) on the dashboard. Claude extracts your stack keywords. The keywords and the resume text can then be edited right on the dashboard: the keywords drive the search on every source, the text is what vacancies are scored against and what the documents are written from. The PDF only sets the design of the generated CV. Vacancies scored before an edit keep their score.
2. **Set the filters** on the Settings page: required keywords and stop words, locations, remote only, the minimum score for document generation, and the weights of the scoring criteria.
3. **Pick the sources** and their options. API keys (jooble, Indeed) and the Telegram bot token are entered on the same Settings page and stored in the database. Locations for LinkedIn and Indeed are chosen from a catalog of EU/EFTA countries and the UK, each one is searched separately. Anything missing from the catalog goes into "Other locations", comma separated, with an optional country code (`Tbilisi:GE`) to include Indeed.
4. **Run a search** with the button in the sidebar, or turn on the schedule and set a cron expression.
5. **Review results** on the Vacancies page. On a posting's page you generate the resume and cover letter, with extra instructions or in another language, research the company, or mark the posting as applied. The documents open as a PDF preview next to the posting, and the editor lets you fix them by hand before downloading. Regenerating a document replaces those edits, so the page asks first.

The interface language is switched at the bottom of the sidebar. It is a global setting, so run logs and Telegram messages follow it as well.

## Scoring

Claude grades four criteria on a 0 to 10 scale, each with an anchored rubric and explicit evidence (`matched` / `missing`): skills, stack, seniority, and location and format. PHP turns them into the final score as a weighted sum (the weights are set on the Settings page and normalised automatically), then applies a language barrier penalty: `warning` subtracts 15, `critical` caps the score at 40.

Postings within ±7 points of the threshold are re-scored twice individually and the median of the three runs wins, because a single call near the threshold is noisy. The breakdown and every run are stored in `vacancies.score_breakdown` and shown on the posting page.

Descriptions are sent to the model in full. Truncating them was the main cause of false rejections, as remote policy and relocation terms often sit at the end of the text. That is why the scoring batch is small (`Pipeline::SCORE_BATCH_SIZE`).

## Telegram

Enable Telegram on the Settings page with a bot token and a chat id, then check them with "Send a test message to Telegram".

Each notification has a "🔕 Mute" button. Muting a posting also mutes its copies from other boards (same company and title). The app has no public address, so it doesn't use a webhook: `telegram:poll` fetches button presses with long polling. It is added to the schedule automatically when Telegram is enabled.

## Sources

| Source | Method | Notes |
|---|---|---|
| dou.ua | public RSS | stable |
| djinni.co | public RSS | stable, companies are anonymous |
| justjoin.it | internal JSON API (`Version: 2` header) | stable |
| it.pracuj.pl | public listing JSON API, categories picked in the settings | short descriptions, job pages are behind Cloudflare |
| jobico.io | public XML feed for aggregators, filtered locally | one request returns the whole site |
| jooble | official REST API, one key per country site (`de:key, pl:key`) | snippets instead of full descriptions; off by default |
| LinkedIn | guest HTML endpoint | fragile, rate limited, queried in batches with a pause |
| Indeed | mobile app GraphQL API | needs an API key; off by default |

Indeed has no public API. The key its mobile app uses is published by the [JobSpy](https://github.com/speedyapply/JobSpy) project; enter it in the source settings if you enable this source, and take a fresh one from there if Indeed rotates it.

The it.pracuj.pl gateway host is announced by its frontend. When the API stops answering with the expected JSON, the source fails loudly and writes the current host into the run log so `PracujSource::GATEWAY` can be updated. A failure of any source triggers a Telegram alert if Telegram is configured.

Please respect each site's terms of use. The sources use public feeds and endpoints and throttle their requests, but you are responsible for how you run them.

## CLI

```bash
php artisan user:create you@example.com   # create the login user or reset their password
php artisan jobs:search                   # run the full pipeline once
php artisan jobs:generate {id}            # (re)generate documents for a posting
php artisan company:research {id}         # research the company of a posting
php artisan telegram:poll                 # fetch Telegram button presses once
php artisan schedule:list                 # show the schedule
```

In Docker, prefix them with `docker compose exec web`.

## Development

```bash
composer install
php artisan test          # PHPUnit, uses in-memory SQLite
vendor/bin/pint           # code style
```

CI runs the test suite and the style check on PHP 8.3 and 8.4.

**Stack:** Laravel 13, Laravel Fortify, MariaDB, Blade with plain CSS and JS (no build step), dompdf, smalot/pdfparser, Claude Code CLI.

## License

[MIT](LICENSE)
