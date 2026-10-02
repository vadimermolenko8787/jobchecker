# UI Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Graphite and blue redesign of JobChecker: a wider dashboard where the resume text and keywords are edited, pipeline settings on their own page, and a two-column vacancy page with a documents panel (PDF preview, Jodit editor, download).

**Architecture:** Server-rendered Blade on Laravel 13, no frontend build. All CSS stays in the inline `<style>` of `resources/views/layouts/app.blade.php`, page scripts go in `@section('scripts')`. New endpoints sit in the existing `ResumeController`, `SettingsController` and `VacancyController`. Document editing works on the stored HTML file: the edited body replaces the `<body>` of the stored document, a Markdown document is wrapped in the existing `pdf.document` template first.

**Tech Stack:** PHP 8.3/8.4, Laravel 13, Blade, dompdf (`barryvdh/laravel-dompdf`), `smalot/pdfparser`, PHPUnit 12, Pint, Jodit 4 from jsDelivr (the only new dependency, approved).

**Spec:** `docs/superpowers/specs/2026-10-02-ui-redesign-design.md` (mockups next to it in `2026-10-02-ui-redesign/`). Read the spec before starting any task.

## Global Constraints

- PHP floor is 8.3 (`composer.json` `"php": "^8.3"`, CI matrix 8.3 and 8.4). No PHP 8.4-only APIs (no `Dom\HTMLDocument`, no property hooks).
- No build step. CSS goes into the `<style>` block of `layouts/app.blade.php`, JS into the view's `@section('scripts')` or the layout's script block. Plain ES5-style JS like the existing scripts (`var`, `function`), no frameworks.
- Only new dependency: Jodit, loaded lazily from `https://cdn.jsdelivr.net/npm/jodit@4.17.1/es2021/jodit.fat.min.js` and `.../jodit.fat.min.css`. The spec says `jodit@4`; the plan pins the current 4.x release (4.17.1, checked 2026-10-02) so a CDN update cannot break the editor.
- CSS variable names stay (`--signal`, `--ok`, ...). New variables: `--signal-fill`, `--signal-fill-hover`, `--warn`, `--warn-dim`. Exact values are in Task 1.
- State classes use the project convention `is-active`, not the mockups' `.on`.
- Every new UI string goes through `__()` with an English key and gets a Russian value in `lang/ru.json`. UI copy in both languages uses commas instead of dashes as separators and no exclamation marks.
- `lang/ru.json` stays sorted by key (`ksort(..., SORT_STRING)`), pretty-printed with 4 spaces, unescaped Unicode and slashes. Add keys with:
  ```bash
  php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"Key":"Значение"}'
  ```
  Remove keys (only after `grep -rnF` over `app resources` shows no use) with:
  ```bash
  php -r '$f = "lang/ru.json"; $d = array_diff_key(json_decode(file_get_contents($f), true), array_flip(json_decode($argv[1], true))); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '["Key"]'
  ```
- Feature tests that read or write files point storage at a temp dir with `$this->app->useStoragePath(...)` and delete it in `tearDown`. Never write into the real `storage/`: `storage/app/private/output/{id}` holds the user's real documents and test ids start at 1.
- Settings fields, validation rules and messages stay as they are, except that `search_keywords` leaves the settings form and `SettingsController@update` (Task 2).
- Run `vendor/bin/pint` before each commit (`concat_space: one`), then `php artisan test`. Baseline before Task 1: 165 tests pass, Pint passes.
- Commit messages: lowercase conventional prefix and a plain English sentence, like `git log`. Every commit ends with the trailer `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

## Review Focus

1. Cyrillic and Polish text in an edited document (`Опыт`, `dziękuję`) must come back from the sanitizer unchanged, without mojibake or entity soup. Test in Task 6.
2. A CV generated before HTML output existed (`resume.md`) must open in the editor and save as `resume.html`, with the `.md` removed. Tests in Tasks 6 and 7.
3. Padded, duplicate or empty keywords (`" go "`, `"Go"`, `""`) must be trimmed, deduplicated case-insensitively and dropped, not saved as separate search terms. Test in Task 3.
4. A resume PDF that is missing on disk must give 404 on "Open PDF" and a JSON error on "Restore text from PDF", never a 500. Tests in Task 3.
5. A stored CV without a `<body>` element must still save as a valid document (wrapped in the PDF template). Test in Task 6.

---

### Task 1: Palette, shared styles and the unsaved-changes helper

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (`:root` block lines 12-27, `[data-theme="light"]` lines 28-39, buttons lines 149-158, badges near line 253, `.page.-wide` line 106, `.scanning::after` line 365, end of the `<style>` block, script block)
- Test: `tests/Feature/LayoutPaletteTest.php`

**Interfaces:**
- Produces CSS classes used by later tasks: `.page.-wider`, `[hidden]` override, `.btn-ok-line`, `.badge.-warn`, `.kw-box`, `.kw`, `.kw-add`, `.tabs`, `.tab`, `.tdot` / `.tdot.-ok`, `.vswitch`, `.vbtn`, `.settings`, `.settings-nav`, `.sub-a`, `.settings-cards`, `.savebar`, `.form-grid.-quad`, `.weight-track`, `.dash-grid`, `.dash-side`, `.run-row`, `.vacancy-grid`, `.vacancy-main`, `.doc-panel`, `.doc-pane`, `.doc-head`, `.doc-instructions`, `.doc-bar`, `.doc-frame`, `.doc-editor`, `.doc-editor-area`, `.doc-foot`, `.doc-empty`.
- Produces the form attribute `data-dirty="<element id>"`: the element with that id is un-hidden while the form's fields differ from their state at page load (Tasks 2 and 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LayoutPaletteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_layout_uses_the_graphite_and_blue_palette(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/vacancies')->assertOk()
            ->assertSee('--signal-fill: #2F6FEB', false)
            ->assertSee('--warn: #9A6700', false)
            ->assertDontSee('#FFB84D', false)
            ->assertSee('data-dirty', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LayoutPaletteTest`
Expected: FAIL, the response does not contain `--signal-fill: #2F6FEB`.

- [ ] **Step 3: Replace the tokens**

Replace the whole `:root { ... }` block with:

```css
        :root {
            --ink: #0E1116; --panel: #161B22; --raised: #1C222B; --raised-2: #232A35;
            --line: #262D37; --line-soft: #1E242D;
            --text: #E6EAF0; --muted: #9AA4B2; --faint: #7D8794;
            --signal: #4C8DFF; --signal-strong: #6FA3FF; --signal-dim: rgba(76,141,255,.14); --signal-line: rgba(76,141,255,.42);
            --signal-fill: #2F6FEB; --signal-fill-hover: #3B7BF5;
            --ok: #3FB950; --ok-dim: rgba(63,185,80,.15);
            --info: #A371F7; --info-dim: rgba(163,113,247,.15);
            --warn: #D29922; --warn-dim: rgba(210,153,34,.15);
            --danger: #F85149; --danger-dim: rgba(248,81,73,.14);
            --neutral: #8B949E; --neutral-dim: rgba(139,148,158,.15);
            --radius: 12px; --radius-sm: 8px;
            --sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
            --display: 'Space Grotesk', var(--sans);
            --mono: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace;
            --shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 8px 24px -12px rgba(0,0,0,.6);
            color-scheme: dark;
        }
        [data-theme="light"] {
            --ink: #F5F6F8; --panel: #FFFFFF; --raised: #F6F8FA; --raised-2: #EEF1F4;
            --line: #D9DEE4; --line-soft: #E6E9ED;
            --text: #1F2328; --muted: #57606A; --faint: #6E7781;
            --signal: #0969DA; --signal-strong: #0550AE; --signal-dim: rgba(9,105,218,.10); --signal-line: rgba(9,105,218,.35);
            --signal-fill: #0969DA; --signal-fill-hover: #0860C7;
            --ok: #1A7F37; --ok-dim: rgba(26,127,55,.11);
            --info: #8250DF; --info-dim: rgba(130,80,223,.11);
            --warn: #9A6700; --warn-dim: rgba(154,103,0,.11);
            --danger: #CF222E; --danger-dim: rgba(207,34,46,.09);
            --neutral: #6E7781; --neutral-dim: rgba(110,119,129,.12);
            --shadow: 0 1px 2px rgba(31,35,40,.04), 0 10px 26px -18px rgba(31,35,40,.22);
            color-scheme: light;
        }
```

Right after `* { box-sizing: border-box; }` add:

```css
        /* elements with their own display rule would otherwise ignore the hidden attribute */
        [hidden] { display: none !important; }
```

- [ ] **Step 4: Replace the button colors**

Replace the lines from `.btn-primary { ... }` through `[data-theme="light"] .btn-ok, [data-theme="light"] .btn-info, [data-theme="light"] .btn-danger { color: #fff; }` with:

```css
        /* #4C8DFF is too light for white text, so filled buttons use the darker fill tone */
        .btn-primary { background: var(--signal-fill); border-color: var(--signal-fill); color: #fff; }
        .btn-primary:hover { background: var(--signal-fill-hover); border-color: var(--signal-fill-hover); color: #fff; }
        .btn-ok { background: var(--ok); border-color: var(--ok); color: #06210F; }
        .btn-ok:hover { background: var(--ok); border-color: var(--ok); color: #06210F; filter: brightness(1.08); }
        .btn-info { background: #8957E5; border-color: #8957E5; color: #fff; }
        .btn-info:hover { background: #9A6CF0; border-color: #9A6CF0; color: #fff; }
        [data-theme="light"] .btn-info { background: #8250DF; border-color: #8250DF; }
        [data-theme="light"] .btn-info:hover { filter: brightness(1.08); }
        .btn-danger { background: #DA3633; border-color: #DA3633; color: #fff; }
        .btn-danger:hover { background: #DA3633; border-color: #DA3633; color: #fff; filter: brightness(1.08); }
        .btn-ok-line { background: var(--ok-dim); border-color: color-mix(in srgb, var(--ok) 45%, transparent); color: var(--ok); }
        .btn-ok-line:hover { background: var(--ok-dim); border-color: var(--ok); color: var(--ok); }
        [data-theme="light"] .btn-ok { color: #fff; }
```

- [ ] **Step 5: Badge, page width and scan pulse**

After `.badge.-failed, .badge.-error { ... }` add:

```css
        .badge.-warn { color: var(--warn); background: var(--warn-dim); }
```

After `.page.-wide { ... }` add:

```css
        .page.-wider { max-width: 1680px; }
```

In `.scanning::after` replace `rgba(255,184,77,.35)` with `rgba(76,141,255,.35)`.

- [ ] **Step 6: Add the redesign styles**

Insert before `@media (prefers-reduced-motion: reduce)`:

```css
        /* ---- keyword tags (resume card) ---- */
        .kw-box { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--raised); }
        .kw { display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 11px; border-radius: 999px; border: 1px solid var(--signal-line); background: var(--signal-dim); color: var(--signal); font: 500 13px var(--mono); }
        .kw button { width: 22px; height: 22px; border-radius: 50%; border: 0; background: transparent; color: inherit; cursor: pointer; display: grid; place-items: center; padding: 0; }
        .kw button:hover { background: color-mix(in srgb, var(--signal) 25%, transparent); }
        .kw-box input.kw-add { flex: 1; min-width: 140px; width: auto; border: 0; background: transparent; box-shadow: none; padding: 5px 4px; font: 13px var(--mono); }

        /* ---- tabs and view switch (documents panel) ---- */
        .tabs { display: inline-flex; background: var(--ink); border: 1px solid var(--line); border-radius: 10px; padding: 4px; gap: 2px; }
        .tab { display: inline-flex; align-items: center; gap: 8px; min-height: 36px; padding: 0 16px; border: 0; border-radius: 7px; background: transparent; color: var(--muted); font: 600 13.5px var(--sans); cursor: pointer; }
        .tab:hover { color: var(--text); }
        .tab.is-active { background: var(--raised-2); color: var(--text); }
        .tdot { width: 7px; height: 7px; border-radius: 50%; background: var(--faint); }
        .tdot.-ok { background: var(--ok); }
        .vswitch { display: inline-flex; border: 1px solid var(--line); border-radius: var(--radius-sm); overflow: hidden; }
        .vbtn { display: inline-flex; align-items: center; gap: 7px; min-height: 34px; padding: 0 13px; border: 0; background: transparent; color: var(--muted); font: 500 13px var(--sans); cursor: pointer; }
        .vbtn + .vbtn { border-left: 1px solid var(--line); }
        .vbtn:hover { color: var(--text); }
        .vbtn.is-active { background: var(--signal-dim); color: var(--signal); }

        /* ---- settings page ---- */
        .settings { display: grid; grid-template-columns: 220px minmax(0,1fr); gap: 28px; align-items: start; }
        .settings-nav { position: sticky; top: 24px; display: flex; flex-direction: column; gap: 2px; }
        .sub-a { display: block; padding: 8px 12px; border-radius: var(--radius-sm); color: var(--muted); font-size: 14px; }
        .sub-a:hover { color: var(--text); background: var(--raised); text-decoration: none; }
        .sub-a.is-active { color: var(--signal); background: var(--signal-dim); }
        .settings-cards { display: flex; flex-direction: column; gap: 20px; max-width: 1080px; min-width: 0; margin: 0; }
        .settings-cards .card { scroll-margin-top: 24px; }
        .savebar {
            position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            padding: 14px 20px; background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius) var(--radius) 0 0;
            box-shadow: 0 -8px 24px -16px rgba(0,0,0,.5);
        }
        .form-grid.-quad { grid-template-columns: repeat(4, minmax(0,1fr)); }
        .weight-track { display: block; height: 6px; border-radius: 999px; background: var(--raised-2); overflow: hidden; }
        .weight-track .fill { display: block; height: 100%; border-radius: 999px; background: var(--signal); }
        @media (max-width: 900px) {
            .settings { grid-template-columns: minmax(0,1fr); }
            .settings-nav { position: static; flex-direction: row; flex-wrap: wrap; }
            .form-grid.-quad { grid-template-columns: 1fr 1fr; }
        }

        /* ---- dashboard ---- */
        .dash-grid { display: grid; grid-template-columns: minmax(0,1.6fr) minmax(0,1fr); gap: 20px; align-items: start; margin-bottom: 20px; }
        .dash-side { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .run-row { display: grid; grid-template-columns: 52px 64px minmax(0,1fr) auto; gap: 10px; align-items: center; padding: 10px 20px; border-top: 1px solid var(--line-soft); font-size: 13px; }
        .run-row:first-child { border-top: 0; }
        @media (max-width: 1100px) { .dash-grid { grid-template-columns: minmax(0,1fr); } }

        /* ---- vacancy page ---- */
        .vacancy-grid { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 24px; align-items: start; }
        .vacancy-main { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .doc-panel { position: sticky; top: 20px; height: calc(100vh - 40px); display: flex; flex-direction: column; overflow: hidden; }
        .doc-pane { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .doc-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 14px; border-bottom: 1px solid var(--line-soft); }
        .doc-instructions { padding: 10px 14px; border-bottom: 1px solid var(--line-soft); background: var(--raised); }
        .doc-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 10px 14px; border-bottom: 1px solid var(--line-soft); }
        .doc-frame { flex: 1; min-height: 0; width: 100%; border: 0; background: #3B4049; }
        .doc-editor { flex: 1; min-height: 0; display: flex; flex-direction: column; margin: 0; }
        .doc-editor-area { flex: 1; min-height: 0; overflow: hidden; }
        .doc-foot { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-top: 1px solid var(--line); }
        .doc-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; padding: 40px; text-align: center; }
        @media (max-width: 1100px) {
            .vacancy-grid { grid-template-columns: minmax(0,1fr); }
            .doc-panel { position: static; height: auto; }
            .doc-frame, .doc-editor-area { flex: none; height: 80vh; }
        }

        /* the flex gap spaces these, the stacking margin would add to it */
        .dash-side > .card + .card, .vacancy-main > .card + .card, .settings-cards > .card + .card { margin-top: 0; }
```

- [ ] **Step 7: Add the unsaved-changes helper**

In the script block at the end of the layout, after the multiselect IIFE (before `</script>` that precedes `@yield('scripts')`), add:

```js
    // A form with data-dirty="<id>" un-hides that element while its fields differ from the loaded state.
    (function () {
        document.querySelectorAll('form[data-dirty]').forEach(function (form) {
            var flag = document.getElementById(form.getAttribute('data-dirty'));
            function snapshot() { return new URLSearchParams(new FormData(form)).toString(); }
            var initial = snapshot();
            function check() { if (flag) flag.hidden = snapshot() === initial; }
            form.addEventListener('input', check);
            form.addEventListener('change', check);
            // reset fires before the fields get their initial values back
            form.addEventListener('reset', function () { setTimeout(check, 0); });
        });
    })();
```

- [ ] **Step 8: Run tests and Pint**

Run: `php artisan test --filter=LayoutPaletteTest && php artisan test && vendor/bin/pint --test`
Expected: PASS, all 166 tests green, Pint clean.

- [ ] **Step 9: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/LayoutPaletteTest.php
git commit -F - <<'EOF'
style: graphite and blue palette with the shared styles of the redesign

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 2: Settings page

**Files:**
- Modify: `routes/web.php` (settings routes, lines 18-19)
- Modify: `app/Http/Controllers/SettingsController.php` (add `edit`, drop `search_keywords` from validation and from the writes)
- Create: `resources/views/settings/edit.blade.php`
- Modify: `resources/views/dashboard.blade.php` (delete the `{{-- Settings --}}` card, lines 146-342, change the subtitle)
- Modify: `resources/views/layouts/app.blade.php` (third nav item)
- Modify: `lang/ru.json`, `README.md`
- Test: `tests/Feature/SettingsPageTest.php`

**Interfaces:**
- Consumes: Task 1 classes `.settings`, `.settings-nav`, `.sub-a`, `.settings-cards`, `.savebar`, `.form-grid.-quad`, `.weight-track`, the `data-dirty` helper.
- Produces: route `settings.edit` (`GET /settings` → `SettingsController@edit`, view `settings.edit` with `$settings = Setting::all_settings()`). `settings.update` no longer reads or writes `search_keywords`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SettingsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_settings_page_holds_the_settings_form(): void
    {
        Setting::set('search_keywords', ['PHP', 'Laravel']);

        $this->get('/settings')->assertOk()
            ->assertSee('action="' . route('settings.update') . '"', false)
            ->assertSee('name="cron_expression"', false)
            ->assertSee('name="telegram_chat_id"', false)
            ->assertSee('form="telegram-test"', false)
            // keywords are shown, but edited on the dashboard only
            ->assertSee('Laravel')
            ->assertDontSee('name="search_keywords"', false);
    }

    public function test_the_dashboard_no_longer_holds_the_settings_form(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('name="cron_expression"', false)
            ->assertSee('href="' . route('settings.edit') . '"', false);
    }

    public function test_saving_settings_keeps_the_search_keywords(): void
    {
        Setting::set('search_keywords', ['PHP', 'Laravel']);

        $this->from('/settings')->post('/settings', [
            'cron_expression' => '0 */6 * * *',
            'min_score' => 70,
            'score_weights' => ['skills' => 40, 'stack' => 25, 'seniority' => 20, 'location' => 15],
            'cover_letter_language' => 'en',
            'company_research_ttl_days' => 30,
            'linkedin_batch_size' => 2,
            'linkedin_batch_pause' => 15,
            // a stale form field must not overwrite the keywords edited on the dashboard
            'search_keywords' => 'Go',
        ])->assertRedirect('/settings')->assertSessionHas('status');

        $this->assertSame(['PHP', 'Laravel'], Setting::get('search_keywords'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SettingsPageTest`
Expected: FAIL, `GET /settings` answers 405 (only POST exists), the dashboard still contains `name="cron_expression"`, and the keywords become `['Go']`.

- [ ] **Step 3: Route and controller**

In `routes/web.php` add above the `settings.update` route:

```php
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
```

In `SettingsController` add before `update`:

```php
    public function edit()
    {
        return view('settings.edit', ['settings' => Setting::all_settings()]);
    }
```

In `update` delete the validation line `'search_keywords' => ['nullable', 'string'],`, and replace the write `Setting::set('search_keywords', $csv($data['search_keywords'] ?? null));` with this comment:

```php
        // search_keywords are not in this form: they are edited with the resume on the dashboard.
```

- [ ] **Step 4: Create the settings view**

Create `resources/views/settings/edit.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'JobChecker — ' . __('Settings'))
@section('page-class', '-wide')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Pipeline') }}</div>
            <h1>{{ __('Settings') }}</h1>
            <div class="sub">{{ __('Schedule, sources, filters, scoring and notifications.') }}</div>
        </div>
    </div>

    @php($sections = [
        's-schedule' => __('Schedule'),
        's-sources' => __('Sources'),
        's-filters' => __('Keywords and filters'),
        's-scoring' => __('Scoring and generation'),
        's-weights' => __('Scoring criteria weights'),
        's-params' => __('Source parameters'),
        's-company' => __('Company research'),
        's-telegram' => 'Telegram',
    ])
    @php($locationLabels = \App\Services\Sources\LocationCatalog::labels())
    @php($pickedLocations = array_values(array_intersect($settings['locations'], array_keys($locationLabels))))
    @php($customLocations = array_values(array_diff($settings['locations'], array_keys($locationLabels))))
    @php($weights = \App\Services\VacancyScorer::weights($settings))
    @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($weights))

    <div class="settings">
        <nav class="settings-nav" aria-label="{{ __('Settings sections') }}">
            @foreach ($sections as $id => $label)
                <a href="#{{ $id }}" @class(['sub-a', 'is-active' => $loop->first])>{{ $label }}</a>
            @endforeach
        </nav>

        <form method="post" action="{{ route('settings.update') }}" class="settings-cards" data-dirty="settings-dirty">
            @csrf

            <section id="s-schedule" class="card">
                <div class="card-head"><h3>{{ __('Schedule') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Cron expression') }}</span>
                        <input type="text" name="cron_expression" value="{{ old('cron_expression', $settings['cron_expression']) }}" required>
                        <span class="help">{!! __(':hourly every hour, :six every 6 hours, :daily daily at 9:00', ['hourly' => '<code>0 * * * *</code>', 'six' => '<code>0 */6 * * *</code>', 'daily' => '<code>0 9 * * *</code>']) !!}</span>
                    </div>
                    <fieldset class="fieldset">
                        <legend>{{ __('Autorun') }}</legend>
                        <label class="check"><input type="checkbox" name="schedule_enabled" value="1" @checked($settings['schedule_enabled'])>{{ __('Enable schedule') }}</label>
                        <div class="help" style="margin-top:8px">{{ __('Add to crontab once:') }}<br><code>* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code></div>
                    </fieldset>
                </div>
            </section>

            <section id="s-sources" class="card">
                <div class="card-head"><h3>{{ __('Sources') }}</h3></div>
                <div class="card-body chip-row">
                    @foreach (['dou' => 'dou.ua', 'djinni' => 'djinni.co', 'justjoin' => 'justjoin.it', 'pracuj' => 'it.pracuj.pl', 'jobico' => 'jobico.io', 'jooble' => 'jooble', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed'] as $key => $label)
                        <label class="chip"><input type="checkbox" name="sources[{{ $key }}]" value="1" @checked($settings['sources'][$key] ?? false)><span class="dot"></span>{{ $label }}</label>
                    @endforeach
                </div>
            </section>

            <section id="s-filters" class="card">
                <div class="card-head"><h3>{{ __('Keywords and filters') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Search keywords') }}</span>
                        <div class="chip-row" style="gap:6px;padding:9px 11px;border:1px dashed var(--line);border-radius:var(--radius-sm)">
                            @forelse ($settings['search_keywords'] as $keyword)
                                <span class="tag">{{ $keyword }}</span>
                            @empty
                                <span class="faint" style="font-size:13px">{{ __('No keywords yet.') }}</span>
                            @endforelse
                        </div>
                        <span class="help">{{ __('Taken from the resume.') }} <a href="{{ route('dashboard') }}">{{ __('Edit on the dashboard') }}</a></span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Locations') }} <span class="faint">(LinkedIn / Indeed)</span></span>
                        <div class="multiselect" data-multiselect>
                            <button type="button" class="ms-toggle" aria-expanded="false" aria-haspopup="true">
                                <span class="ms-summary"></span><span class="ms-caret">▼</span>
                            </button>
                            <div class="ms-panel chip-row">
                                @foreach ($locationLabels as $value => $label)
                                    <label class="chip"><input type="checkbox" name="locations[]" value="{{ $value }}" @checked(in_array($value, $pickedLocations, true))><span class="dot"></span>{{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        <span class="help">{{ __('Each selected location is searched separately.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Other locations') }} <span class="faint">{{ __('(comma-separated)') }}</span></span>
                        <input type="text" name="locations_custom" value="{{ implode(', ', $customLocations) }}">
                        <span class="help">{!! __('Anything missing from the list. For Indeed add the country code after a colon, :example, otherwise the location is searched on LinkedIn only.', ['example' => '<code>Tbilisi:GE</code>']) !!}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Required words') }}</span>
                        <input type="text" name="include_keywords" value="{{ implode(', ', $settings['include_keywords']) }}">
                        <span class="help">{{ __('A vacancy is kept only if it contains at least one of them.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Stop words') }}</span>
                        <input type="text" name="exclude_keywords" value="{{ implode(', ', $settings['exclude_keywords']) }}">
                        <span class="help">{{ __('Vacancies containing these words are dropped.') }}</span>
                    </div>
                </div>
            </section>

            <section id="s-scoring" class="card">
                <div class="card-head"><h3>{{ __('Scoring and generation') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <span class="lab">{{ __('Min. score for documents') }}</span>
                        <input type="number" name="min_score" min="0" max="100" value="{{ $settings['min_score'] }}" required style="max-width:140px">
                    </div>
                    <label class="check" style="align-self:center"><input type="checkbox" name="remote_only" value="1" @checked($settings['remote_only'])>{{ __('Remote only') }}</label>
                    <div class="field">
                        <span class="lab">{{ __('Cover letter language') }}</span>
                        <select name="cover_letter_language">
                            @foreach (\App\Services\DocumentGenerator::languageLabels() as $code => $label)
                                <option value="{{ $code }}" @selected(($settings['cover_letter_language'] ?? 'en') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="help">{{ __('The resume is always in English.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Known languages') }}</span>
                        <input type="text" name="known_languages" value="{{ implode(', ', $settings['known_languages']) }}">
                        <span class="help">{{ __('Comma-separated. Vacancies written in other languages are rejected after scoring. If the role requires an unknown language, the score is lowered.') }}</span>
                    </div>
                </div>
            </section>

            <section id="s-weights" class="card">
                <div class="card-head"><h3>{{ __('Scoring criteria weights') }}</h3></div>
                <div class="card-body">
                    <div class="form-grid -quad" data-weights>
                        @foreach (\App\Services\VacancyScorer::labels() as $key => $label)
                            <div class="field">
                                <span class="lab">{{ $label }}</span>
                                <input type="number" name="score_weights[{{ $key }}]" min="0" max="100" value="{{ $weights[$key] }}" required>
                                <span class="weight-track"><span class="fill" style="width:{{ round($shares[$key] * 100) }}%"></span></span>
                                <span class="help mono" data-share>{{ round($shares[$key] * 100) }}%</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="faint" style="font-size:12px;line-height:1.5;margin-top:12px">
                        {{ __('The model rates each criterion 0-10, the final score is their weighted sum.') }}
                        {{ __('Weights are normalized automatically, they do not have to add up to 100.') }}
                    </div>
                </div>
            </section>

            <section id="s-params" class="card">
                <div class="card-head"><h3>{{ __('Source parameters') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field"><span class="lab">{{ __('DOU category') }}</span><input type="text" name="dou_category" value="{{ $settings['dou_category'] }}"></div>
                    <div class="field"><span class="lab">Primary keyword Djinni</span><input type="text" name="djinni_primary_keyword" value="{{ $settings['djinni_primary_keyword'] }}"></div>
                    <div class="field"><span class="lab">{{ __('justjoin.it category') }} <span class="faint">{{ __('(number, 3 = PHP)') }}</span></span><input type="number" name="justjoin_category" value="{{ $settings['justjoin_category'] }}"></div>
                    <div class="field">
                        <span class="lab">{{ __('it.pracuj.pl categories') }}</span>
                        <div class="multiselect" data-multiselect data-empty="{{ __('Both IT sections') }}">
                            <button type="button" class="ms-toggle" aria-expanded="false" aria-haspopup="true">
                                <span class="ms-summary"></span><span class="ms-caret">▼</span>
                            </button>
                            <div class="ms-panel chip-row">
                                @foreach (\App\Services\Sources\PracujSource::categoryLabels() as $code => $label)
                                    <label class="chip"><input type="checkbox" name="pracuj_categories[]" value="{{ $code }}" @checked(in_array((string) $code, array_map('strval', $settings['pracuj_categories'] ?? []), true))><span class="dot"></span>{{ $label }}</label>
                                @endforeach
                            </div>
                        </div>
                        <span class="help">{{ __('Each selected category is searched separately. With nothing selected, both IT sections are searched in full.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('jooble API keys') }} <span class="faint">{{ __('(comma-separated)') }}</span></span>
                        <input type="text" name="jooble_keys" value="{{ collect($settings['jooble_keys'] ?? [])->map(fn ($key, $country) => $country . ':' . $key)->implode(', ') }}" autocomplete="off">
                        <span class="help">{!! __('Country site code and key separated by a colon: :example. A key is issued at :url and works only on its own site. Every country with a key is searched, locations do not affect this source.', ['example' => '<code>' . e(__('de:key, pl:key')) . '</code>', 'url' => '<code>&lt;' . e(__('code')) . '&gt;.jooble.org/api/about</code>']) !!}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Indeed API key') }}</span>
                        <input type="password" name="indeed_api_key" value="{{ $settings['indeed_api_key'] }}" autocomplete="off">
                        <span class="help">{!! __('The key of the Indeed mobile app, published by the :project project. If Indeed rotates it, take the current one from there.', ['project' => '<code>github.com/speedyapply/JobSpy</code>']) !!}</span>
                    </div>
                    <div class="field"><span class="lab">{{ __('LinkedIn: locations per batch') }}</span><input type="number" name="linkedin_batch_size" min="1" max="10" value="{{ $settings['linkedin_batch_size'] }}" required></div>
                    <div class="field">
                        <span class="lab">{{ __('LinkedIn: pause between batches, sec') }}</span>
                        <input type="number" name="linkedin_batch_pause" min="0" max="300" value="{{ $settings['linkedin_batch_pause'] }}" required>
                        <span class="help">{{ __('Guards against 429 on the guest endpoint.') }}</span>
                    </div>
                </div>
            </section>

            <section id="s-company" class="card">
                <div class="card-head"><h3>{{ __('Company research') }}</h3></div>
                <div class="card-body form-grid">
                    <div class="field">
                        <label class="check"><input type="checkbox" name="company_research_enabled" value="1" @checked($settings['company_research_enabled'])>{{ __('Automatically research companies of matching vacancies') }}</label>
                        <span class="help">{{ __('Looks up employee reviews (Glassdoor, Indeed, DOU and others) via web search. Up to 5 companies per run, 1-3 minutes per company. You can also research a company by hand from the vacancy page.') }}</span>
                    </div>
                    <div class="field">
                        <span class="lab">{{ __('Cache freshness, days') }}</span>
                        <input type="number" name="company_research_ttl_days" min="1" max="365" value="{{ $settings['company_research_ttl_days'] }}" required style="max-width:140px">
                        <span class="help">{{ __('A company is not researched again while its result is younger than this.') }}</span>
                    </div>
                </div>
            </section>

            <section id="s-telegram" class="card">
                <div class="card-head"><h3>{{ __('Telegram notifications') }}</h3></div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                    <label class="check"><input type="checkbox" name="telegram_enabled" value="1" @checked($settings['telegram_enabled'])>{{ __('Send matched vacancies to Telegram') }}</label>
                    <div class="form-grid">
                        <div class="field">
                            <span class="lab">Bot token</span>
                            <input type="password" name="telegram_bot_token" value="{{ $settings['telegram_bot_token'] }}" autocomplete="off">
                            <span class="help">{!! __('Get it from :bot', ['bot' => '<code>@BotFather</code>']) !!}</span>
                        </div>
                        <div class="field">
                            <span class="lab">Chat ID</span>
                            <input type="text" name="telegram_chat_id" value="{{ $settings['telegram_chat_id'] }}">
                            <span class="help">{!! __('Ask :bot, send the bot /start first', ['bot' => '<code>@userinfobot</code>']) !!}</span>
                        </div>
                    </div>
                    <div class="stack">
                        {{-- Belongs to the separate form below: forms cannot nest. --}}
                        <button type="submit" form="telegram-test" class="btn btn-sm">{{ __('Send a test message to Telegram') }}</button>
                        <span class="faint" style="font-size:12px">{{ __('Uses the saved token and chat ID.') }}</span>
                    </div>
                </div>
            </section>

            <div class="savebar">
                <button type="submit" class="btn btn-primary">{{ __('Save settings') }}</button>
                <button type="reset" class="btn btn-ghost">{{ __('Cancel') }}</button>
                <span id="settings-dirty" style="color:var(--warn);font-size:12.5px" hidden>{{ __('There are unsaved changes') }}</span>
            </div>
        </form>
    </div>

    <form id="telegram-test" method="post" action="{{ route('settings.telegram-test') }}">@csrf</form>
@endsection

@section('scripts')
<script>
    (function () {
        var form = document.querySelector('.settings-cards');
        var weights = document.querySelector('[data-weights]');
        var links = document.querySelectorAll('.settings-nav .sub-a');

        function updateShares() {
            var inputs = weights.querySelectorAll('input');
            var values = Array.prototype.map.call(inputs, function (i) { return Math.max(0, parseInt(i.value, 10) || 0); });
            var sum = values.reduce(function (a, b) { return a + b; }, 0);
            inputs.forEach(function (input, n) {
                var share = sum ? Math.round(values[n] / sum * 100) : 0;
                var field = input.closest('.field');
                field.querySelector('.fill').style.width = share + '%';
                field.querySelector('[data-share]').textContent = share + '%';
            });
        }
        weights.addEventListener('input', updateShares);

        // Reset restores the fields, but the dropdown summaries and the share bars only
        // redraw on their own events.
        form.addEventListener('reset', function () {
            setTimeout(function () {
                form.querySelectorAll('[data-multiselect]').forEach(function (root) { root.dispatchEvent(new Event('change')); });
                updateShares();
            }, 0);
        });

        // Highlight the section currently at the top of the viewport.
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    links.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id); });
                });
            }, { rootMargin: '-10% 0px -80% 0px' });
            form.querySelectorAll('section[id]').forEach(function (s) { observer.observe(s); });
        }
    })();
</script>
@endsection
```

- [ ] **Step 5: Dashboard and navigation**

In `resources/views/dashboard.blade.php` delete the whole block from `{{-- Settings --}}` through the closing `</div>` of that card (the line before `{{-- Recent runs --}}`). Replace `{{ __('Hunt status, scan launch and pipeline settings.') }}` with `{{ __('Hunt status, resume and scan launch.') }}`.

In `resources/views/layouts/app.blade.php`, inside `<nav class="nav">`, after the Vacancies link add:

```blade
            <a href="{{ route('settings.edit') }}" @class(['is-active' => request()->routeIs('settings.*')])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
                {{ __('Settings') }}
            </a>
```

- [ ] **Step 6: Translations**

```bash
php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"Settings":"Настройки","Pipeline":"Пайплайн","Schedule, sources, filters, scoring and notifications.":"Расписание, источники, фильтры, скоринг и уведомления.","Settings sections":"Разделы настроек","No keywords yet.":"Ключевых слов пока нет.","Taken from the resume.":"Берутся из резюме.","Edit on the dashboard":"Изменить на Панели","Cancel":"Отменить","There are unsaved changes":"Есть несохранённые изменения","Hunt status, resume and scan launch.":"Состояние охоты, резюме и запуск сканирования."}'
```

Then check the keys this task orphaned and remove the unused ones:

```bash
for k in "Pipeline settings" "Filled in from the resume automatically, editable. Comma-separated." "Hunt status, scan launch and pipeline settings."; do echo "== $k"; grep -rnF "'$k'" app resources; done
```

For every key with no hits:

```bash
php -r '$f = "lang/ru.json"; $d = array_diff_key(json_decode(file_get_contents($f), true), array_flip(json_decode($argv[1], true))); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '["Pipeline settings","Filled in from the resume automatically, editable. Comma-separated.","Hunt status, scan launch and pipeline settings."]'
```

- [ ] **Step 7: README**

In `README.md`:
- Usage item 2: `2. **Set the filters** on the Settings page: required keywords and stop words, ...` (rest of the sentence unchanged).
- Usage item 3: replace `are entered in the same settings form and stored in the database` with `are entered on the same Settings page and stored in the database`.
- Scoring: replace `(the weights are set on the dashboard and normalised automatically)` with `(the weights are set on the Settings page and normalised automatically)`.
- Telegram: replace `Enable Telegram on the dashboard` with `Enable Telegram on the Settings page`.
- Sources table, it.pracuj.pl row: replace `categories picked on the dashboard` with `categories picked in the settings`.

- [ ] **Step 8: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass, including the three new ones and `SourceKeysSettingTest`.

- [ ] **Step 9: Commit**

```bash
git add routes/web.php app/Http/Controllers/SettingsController.php resources/views/settings/edit.blade.php resources/views/dashboard.blade.php resources/views/layouts/app.blade.php lang/ru.json README.md tests/Feature/SettingsPageTest.php
git commit -F - <<'EOF'
feat: pipeline settings move to their own page

Search keywords leave the settings form, so saving settings no longer
overwrites the keywords that come with the resume.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 3: Editable resume endpoints

**Files:**
- Modify: `routes/web.php` (after `resume.store`)
- Modify: `app/Http/Controllers/ResumeController.php`
- Modify: `lang/ru.json`
- Test: `tests/Feature/ResumeEditTest.php`

**Interfaces:**
- Produces routes: `resume.update` (`PUT /resume`, fields `text`, `keywords[]`, redirects back with `status` or `error`), `resume.restore-text` (`POST /resume/restore-text`, JSON `{"text": "..."}` or `{"message": "..."}` with 404/422), `resume.file` (`GET /resume/file`, the stored PDF inline, 404 when missing).
- Produces: `ResumeController::extractText(string $file): string` (private), shared by `store` and `restoreText`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ResumeEditTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ResumeEditTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // The controllers build paths with storage_path(), so a temp storage root keeps
        // the real storage/ out of reach.
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function resume(?string $pdfHtml = null): Resume
    {
        if ($pdfHtml !== null) {
            File::ensureDirectoryExists(storage_path('app/private/resumes'));
            File::put(storage_path('app/private/resumes/cv.pdf'), Pdf::loadHTML($pdfHtml)->output());
        }

        return Resume::create([
            'original_name' => 'cv.pdf',
            'path' => 'resumes/cv.pdf',
            'text' => 'old text',
            'keywords' => ['PHP'],
            'is_active' => true,
        ]);
    }

    public function test_saving_updates_the_text_keywords_and_search_keywords(): void
    {
        $resume = $this->resume();

        $this->from('/')->put('/resume', [
            'text' => 'Ten years of Go',
            'keywords' => [' Go ', 'go', '', 'Rust'],
        ])->assertRedirect('/')->assertSessionHas('status');

        $resume->refresh();
        $this->assertSame('Ten years of Go', $resume->text);
        $this->assertSame(['Go', 'Rust'], $resume->keywords);
        $this->assertSame(['Go', 'Rust'], Setting::get('search_keywords'));
    }

    public function test_removing_every_keyword_clears_the_search_keywords(): void
    {
        $this->resume();
        Setting::set('search_keywords', ['PHP']);

        $this->from('/')->put('/resume', ['text' => 'Ten years of Go'])->assertSessionHas('status');

        $this->assertSame([], Setting::get('search_keywords'));
    }

    public function test_saving_without_a_resume_reports_an_error(): void
    {
        Setting::set('search_keywords', ['PHP']);

        $this->from('/')->put('/resume', ['text' => 'Ten years of Go', 'keywords' => ['Go']])
            ->assertRedirect('/')->assertSessionHas('error');

        $this->assertSame(['PHP'], Setting::get('search_keywords'));
    }

    public function test_the_text_and_keywords_are_validated(): void
    {
        $this->resume();

        $this->from('/')->put('/resume', ['text' => ''])->assertSessionHasErrors('text');
        $this->from('/')->put('/resume', ['text' => str_repeat('a', 50001)])->assertSessionHasErrors('text');
        $this->from('/')->put('/resume', ['text' => 'ok', 'keywords' => array_fill(0, 21, 'Go')])->assertSessionHasErrors('keywords');
        $this->from('/')->put('/resume', ['text' => 'ok', 'keywords' => [str_repeat('a', 61)]])->assertSessionHasErrors('keywords.0');
    }

    public function test_restore_text_returns_the_text_of_the_stored_pdf_without_saving_it(): void
    {
        $resume = $this->resume('<p>Senior PHP Developer</p><p>Laravel</p>');

        $response = $this->postJson('/resume/restore-text')->assertOk();

        $this->assertStringContainsString('Senior PHP Developer', $response->json('text'));
        $this->assertStringContainsString('Laravel', $response->json('text'));
        $resume->refresh();
        $this->assertSame('old text', $resume->text);
        $this->assertSame(['PHP'], $resume->keywords);
    }

    public function test_restore_text_without_the_pdf_file_answers_with_a_json_error(): void
    {
        $this->resume();

        $this->postJson('/resume/restore-text')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_the_original_pdf_is_served_and_a_missing_one_is_not_found(): void
    {
        $this->get('/resume/file')->assertNotFound();

        $this->resume();
        $this->get('/resume/file')->assertNotFound();

        $this->resume('<p>CV</p>');
        $this->get('/resume/file')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ResumeEditTest`
Expected: FAIL, the routes do not exist (405 for `PUT /resume`, 404/405 for the others).

- [ ] **Step 3: Routes**

In `routes/web.php` after the `resume.store` line add:

```php
    Route::put('/resume', [ResumeController::class, 'update'])->name('resume.update');
    Route::post('/resume/restore-text', [ResumeController::class, 'restoreText'])->name('resume.restore-text');
    Route::get('/resume/file', [ResumeController::class, 'file'])->name('resume.file');
```

- [ ] **Step 4: Controller**

In `ResumeController::store` replace

```php
        try {
            $text = (new Parser)->parseFile($file->getRealPath())->getText();
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not extract text from the PDF: :error', ['error' => $e->getMessage()]));
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', $text));
```

with

```php
        try {
            $text = $this->extractText($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not extract text from the PDF: :error', ['error' => $e->getMessage()]));
        }
```

Add after `store`:

```php
    public function update(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            // nullable: an empty tag arrives as null after ConvertEmptyStringsToNull
            'keywords.*' => ['nullable', 'string', 'max:60'],
        ]);

        $resume = Resume::active();
        if (! $resume) {
            return back()->with('error', __('Upload a resume first.'));
        }

        // The tags are the search keywords, so "Go" and " go " must not become two searches.
        $keywords = collect($data['keywords'] ?? [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter(fn (string $keyword) => $keyword !== '')
            ->unique(fn (string $keyword) => mb_strtolower($keyword))
            ->values()
            ->all();

        $resume->update(['text' => $data['text'], 'keywords' => $keywords]);
        Setting::set('search_keywords', $keywords);

        return back()->with('status', __('Resume saved.'));
    }

    /** Text of the stored PDF for the editor; nothing is saved until the form is. */
    public function restoreText()
    {
        $file = $this->activePdf();
        if (! $file) {
            return response()->json(['message' => __('The original PDF was not found.')], 404);
        }

        try {
            $text = $this->extractText($file);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('Could not extract text from the PDF: :error', ['error' => $e->getMessage()])], 422);
        }
        if ($text === '') {
            return response()->json(['message' => __('The PDF has no extractable text (it may be a scan).')], 422);
        }

        return response()->json(['text' => $text]);
    }

    public function file()
    {
        $file = $this->activePdf();
        abort_unless($file, 404);

        return response()->file($file, ['Content-Type' => 'application/pdf']);
    }

    private function activePdf(): ?string
    {
        $resume = Resume::active();
        $file = $resume ? storage_path("app/private/{$resume->path}") : null;

        return $file && is_file($file) ? $file : null;
    }

    /** One normalization for an upload and for restoring the text from the stored file. */
    private function extractText(string $file): string
    {
        return trim(preg_replace('/[ \t]+/', ' ', (new Parser)->parseFile($file)->getText()));
    }
```

- [ ] **Step 5: Translations**

```bash
php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"Resume saved.":"Резюме сохранено.","The original PDF was not found.":"Исходный PDF не найден."}'
```

- [ ] **Step 6: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/ResumeController.php lang/ru.json tests/Feature/ResumeEditTest.php
git commit -F - <<'EOF'
feat: edit the resume text and keywords, restore the text from the PDF

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 4: Dashboard with the resume editor

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php` (pass `keywords` instead of `settings`)
- Rewrite: `resources/views/dashboard.blade.php`
- Modify: `lang/ru.json`, `README.md`
- Test: `tests/Feature/ResumeEditTest.php` (two more tests)

**Interfaces:**
- Consumes: Task 3 routes `resume.update`, `resume.restore-text`, `resume.file`; Task 1 classes `.dash-grid`, `.dash-side`, `.run-row`, `.kw-box`, `.kw`, `.kw-add`, `.badge.-warn` and the `data-dirty` helper.
- Produces: view variable `$keywords` (`Setting::get('search_keywords')`, `string[]`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ResumeEditTest.php`:

```php
    public function test_the_dashboard_edits_the_resume_text_and_keywords(): void
    {
        $resume = $this->resume();
        $resume->update(['text' => 'Senior PHP developer']);
        Setting::set('search_keywords', ['PHP', 'Laravel']);

        $this->get('/')->assertOk()
            ->assertSee('action="' . route('resume.update') . '"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('Senior PHP developer')
            ->assertSee('name="keywords[]" value="Laravel"', false)
            ->assertSee(route('resume.file'), false)
            ->assertSee(route('resume.restore-text'), false);
    }

    public function test_the_dashboard_without_a_resume_offers_the_upload(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('name="resume"', false)
            ->assertDontSee('name="text"', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ResumeEditTest`
Expected: FAIL on `test_the_dashboard_edits_the_resume_text_and_keywords` (no `resume.update` form yet).

- [ ] **Step 3: Controller**

In `DashboardController::index` replace `'settings' => Setting::all_settings(),` with:

```php
            'keywords' => Setting::get('search_keywords'),
```

- [ ] **Step 4: Rewrite the dashboard view**

Replace `resources/views/dashboard.blade.php` with:

```blade
@extends('layouts.app')

@section('title', 'JobChecker — ' . __('dashboard'))
@section('page-class', '-wide')

@section('content')
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Command center') }}</div>
            <h1>{{ __('Dashboard') }}</h1>
            <div class="sub">{{ __('Hunt status, resume and scan launch.') }}</div>
        </div>
        <a href="{{ route('vacancies.index', ['status' => 'matched']) }}" class="btn btn-ghost">
            {{ __('View matches') }}
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>

    {{-- Hunt status strip --}}
    <div class="grid grid-4" style="margin-bottom:20px">
        <div class="stat">
            <div class="l">{{ __('Vacancies in database') }}</div>
            <div class="n">{{ $counts['total'] }}</div>
        </div>
        <div class="stat -accent">
            <div class="l">{{ __('Matching') }}</div>
            <div class="n">{{ $counts['matched'] }}</div>
        </div>
        <div class="stat -ok">
            <div class="l">{{ __('With documents') }}</div>
            <div class="n">{{ $counts['done'] }}</div>
        </div>
        <div class="stat">
            <div class="l">{{ __('Applied') }}</div>
            <div class="n">{{ $globalCounts['applied'] }}</div>
        </div>
    </div>

    <div class="dash-grid">
        {{-- Resume: the text and keywords here are what scoring and generation use --}}
        <div class="card">
            <div class="card-head">
                <div class="stack" style="gap:10px">
                    <h3>{{ __('Resume') }}</h3>
                    <span class="badge -warn" id="resume-dirty" hidden>{{ __('unsaved edits') }}</span>
                </div>
                @if ($resume)
                    <span class="hint">{{ __(':count chars', ['count' => mb_strlen((string) $resume->text)]) }} · {{ trans_choice(':count keyword|:count keywords', count($keywords)) }}</span>
                @endif
            </div>
            <div class="card-body">
                @if ($resume)
                    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px 14px;background:var(--raised);border:1px solid var(--line-soft);border-radius:10px">
                        <span style="width:38px;height:38px;border-radius:9px;background:var(--danger-dim);color:var(--danger);display:grid;place-items:center;flex:none">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                        <div style="min-width:0;flex:1">
                            <div style="font-weight:600;word-break:break-word">{{ $resume->original_name }}</div>
                            <div class="faint" style="font-size:12.5px">{{ __('uploaded :date', ['date' => $resume->created_at->format('d.m.Y H:i')]) }} · {{ __('the CV design is taken from this file') }}</div>
                        </div>
                        <a href="{{ route('resume.file') }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">{{ __('Open PDF') }}</a>
                        <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data">
                            @csrf
                            <label class="btn btn-sm" title="{{ __('After upload Claude extracts your stack keywords (up to a minute).') }}">
                                {{ __('Upload a new PDF') }}
                                <input type="file" name="resume" accept="application/pdf" hidden onchange="this.form.submit()">
                            </label>
                        </form>
                    </div>

                    <form method="post" action="{{ route('resume.update') }}" data-dirty="resume-dirty" style="display:flex;flex-direction:column;gap:20px;margin-top:20px">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                                <label class="lab" for="kw-add">{{ __('Keywords') }}</label>
                                <span class="help">{{ __('Used as search keywords on every source') }}</span>
                            </div>
                            <div class="kw-box" data-keywords>
                                @foreach (old('keywords', $keywords) as $keyword)
                                    <span class="kw">{{ $keyword }}<input type="hidden" name="keywords[]" value="{{ $keyword }}"><button type="button" aria-label="{{ __('Remove :keyword', ['keyword' => $keyword]) }}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></span>
                                @endforeach
                                <input type="text" id="kw-add" class="kw-add" maxlength="60" placeholder="{{ __('add and press Enter') }}">
                            </div>
                        </div>

                        <div class="field">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                                <label class="lab" for="resume-text">{{ __('Resume text') }}</label>
                                <button type="button" class="btn btn-sm btn-ghost" data-restore-text="{{ route('resume.restore-text') }}">{{ __('Restore text from PDF') }}</button>
                            </div>
                            <textarea id="resume-text" name="text" required maxlength="50000" style="height:420px;resize:vertical;font-size:13.5px;line-height:1.65">{{ old('text', $resume->text) }}</textarea>
                            <span class="help">{{ __('Claude scores vacancies and writes the CV and cover letter from this text. Edits do not change the uploaded PDF.') }}</span>
                        </div>

                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--line-soft)">
                            <button type="submit" class="btn btn-primary">{{ __('Save resume') }}</button>
                            <button type="reset" class="btn btn-ghost">{{ __('Discard changes') }}</button>
                            <span class="faint" style="margin-left:auto;font-size:12.5px">{{ __('New vacancies are scored against the saved text') }}</span>
                        </div>
                    </form>
                @else
                    <div class="empty" style="padding:20px 0 24px">
                        <div style="font-weight:600;color:var(--text);margin-bottom:4px">{{ __('No resume uploaded yet') }}</div>
                        <div class="faint" style="font-size:13px">{{ __('Upload a PDF, Claude will extract your stack and fill in the keywords.') }}</div>
                    </div>
                    <form method="post" action="{{ route('resume.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="field">
                            <input type="file" name="resume" accept="application/pdf" required>
                        </div>
                        <div class="stack" style="margin-top:12px">
                            <button type="submit" class="btn btn-primary">{{ __('Upload PDF') }}</button>
                            <span class="faint" style="font-size:12px;max-width:260px">{{ __('After upload Claude extracts your stack keywords (up to a minute).') }}</span>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="dash-side">
            <div class="card">
                <div class="card-head">
                    <h3>{{ __('Scanning') }}</h3>
                    <span class="badge -neutral" id="run-badge" style="display:none"></span>
                </div>
                <div class="card-body">
                    <p class="muted" style="margin-top:0;font-size:14px">
                        {{ __('Crawls the selected sources and scores vacancies against your resume. Documents are generated only by hand from the vacancy page.') }}
                    </p>
                    <div style="display:flex;gap:10px">
                        <form method="post" action="{{ route('run.start') }}" style="flex:1">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-block" id="start-btn" @unless($resume) disabled data-noresume @endunless>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                {{ __('Start search') }}
                            </button>
                        </form>
                        <form method="post" action="{{ route('run.stop') }}" data-run-stop style="display:none;flex:1">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-block" title="{{ __('The run stops after the current step, vacancies already scored are kept.') }}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                                {{ __('Stop search') }}
                            </button>
                        </form>
                    </div>
                    @unless($resume)<div class="faint" style="font-size:12.5px;text-align:center;margin-top:8px">{{ __('Upload a resume first.') }}</div>@endunless
                    <div id="run-status" class="mono" style="font-size:13px;color:var(--muted);margin-top:14px"></div>
                    <pre class="log" id="run-log" style="display:none;margin-top:10px;height:200px;white-space:pre-wrap"></pre>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3>{{ __('Recent runs') }}</h3></div>
                <div>
                    @forelse ($runs as $run)
                        <div class="run-row">
                            <a href="{{ route('runs.show', $run) }}" class="mono" style="font-weight:600">#{{ $run->id }}</a>
                            <span><span class="tag">{{ $run->trigger }}</span></span>
                            <span class="mono faint" style="font-size:12.5px">{{ $run->started_at?->setTimezone('Europe/Berlin')->format('d.m H:i') }}</span>
                            <span class="badge -{{ $run->status }}">{{ $run->status }}</span>
                        </div>
                    @empty
                        <div class="empty">{{ __('No runs yet. Press “:button”.', ['button' => __('Start search')]) }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Per-source breakdown: same rows twice, once by volume and once by what actually matched --}}
    <div class="grid grid-2">
        @foreach ([
            [__('Vacancies by source'), 'total', 'var(--info)', __('total in database'), []],
            [__('Matches by source'), 'matched', 'var(--signal)', __('status matched'), ['status' => 'matched']],
        ] as [$heading, $field, $color, $hint, $query])
            @php($max = max(1, (int) $bySource->max($field)))
            <div class="card">
                <div class="card-head">
                    <h3>{{ $heading }}</h3>
                    <span class="hint">{{ $hint }}</span>
                </div>
                <div class="card-body">
                    @forelse ($bySource as $row)
                        <div class="src-row">
                            <a href="{{ route('vacancies.index', $query + ['source' => [$row->source]]) }}" style="text-decoration:none">
                                <span class="tag">{{ $row->source }}</span>
                            </a>
                            <span class="track"><span class="fill" style="width:{{ round($row->$field / $max * 100) }}%;background:{{ $color }}"></span></span>
                            <span class="sv">{{ $row->$field }}</span>
                        </div>
                    @empty
                        <div class="empty">{{ __('No vacancies yet.') }}</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
@endsection

@section('scripts')
<script>
    (function () {
        var statusEl = document.getElementById('run-status');
        var logEl = document.getElementById('run-log');
        var badge = document.getElementById('run-badge');
        window.__jcOnRun = function (run, running, wasRunning) {
            if (badge) {
                badge.style.display = 'inline-flex';
                badge.className = 'badge -' + run.status;
                badge.textContent = run.status;
            }
            if (running || wasRunning) {
                statusEl.innerHTML = @json(__('Run #:id · :started')).replace(':id', run.id).replace(':started', run.started_at || '');
                logEl.style.display = 'block';
                logEl.textContent = run.log || '';
                logEl.scrollTop = logEl.scrollHeight;
            }
        };
    })();

    // Keyword tags keep hidden keywords[] inputs, so the form posts them like any other field.
    (function () {
        var box = document.querySelector('[data-keywords]');
        if (!box) return;
        var form = box.closest('form');
        var add = box.querySelector('.kw-add');
        var max = 20;
        var removeLabel = @json(__('Remove :keyword'));
        var cross = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

        function values() {
            return Array.prototype.map.call(box.querySelectorAll('input[name="keywords[]"]'), function (i) { return i.value; });
        }
        var initial = values();

        function tag(value) {
            var span = document.createElement('span');
            span.className = 'kw';
            span.textContent = value;
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'keywords[]';
            hidden.value = value;
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-label', removeLabel.replace(':keyword', value));
            button.innerHTML = cross;
            span.appendChild(hidden);
            span.appendChild(button);
            box.insertBefore(span, add);
        }

        function commit() {
            var value = add.value.trim();
            add.value = '';
            var taken = values().map(function (v) { return v.toLowerCase(); });
            if (value === '' || taken.indexOf(value.toLowerCase()) !== -1 || taken.length >= max) return;
            tag(value);
            form.dispatchEvent(new Event('change'));
        }

        add.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
        });
        box.addEventListener('click', function (e) {
            var button = e.target.closest('.kw button');
            if (!button) return;
            button.parentNode.remove();
            form.dispatchEvent(new Event('change'));
            add.focus();
        });
        // A word typed but not confirmed with Enter would otherwise be lost on save.
        form.addEventListener('submit', commit);
        // Form reset does not know about the generated tags, so they are rebuilt here.
        form.addEventListener('reset', function () {
            box.querySelectorAll('.kw').forEach(function (el) { el.remove(); });
            initial.forEach(tag);
        });
    })();

    // Puts the text of the stored PDF into the textarea; it is saved with the form.
    (function () {
        var button = document.querySelector('[data-restore-text]');
        if (!button) return;
        var area = document.getElementById('resume-text');
        button.addEventListener('click', function () {
            button.disabled = true;
            fetch(button.getAttribute('data-restore-text'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
            }).then(function (r) {
                return r.json().then(function (body) { return { ok: r.ok, body: body }; });
            }).then(function (res) {
                if (!res.ok) throw new Error(res.body.message || '');
                area.value = res.body.text;
                area.dispatchEvent(new Event('input', { bubbles: true }));
            }).catch(function (e) {
                alert(e.message || @json(__('Could not restore the text.')));
            }).finally(function () {
                button.disabled = false;
            });
        });
    })();
</script>
@endsection
```

- [ ] **Step 5: Translations**

```bash
php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"unsaved edits":"есть правки",":count keyword|:count keywords":":count ключевое слово|:count ключевых слова|:count ключевых слов","the CV design is taken from this file":"оформление CV берётся из этого файла","Open PDF":"Открыть PDF","Upload a new PDF":"Загрузить новый PDF","Keywords":"Ключевые слова","Used as search keywords on every source":"используются как ключевые слова поиска на всех источниках","Remove :keyword":"Убрать :keyword","add and press Enter":"добавить и Enter","Resume text":"Текст резюме","Restore text from PDF":"Вернуть текст из PDF","Claude scores vacancies and writes the CV and cover letter from this text. Edits do not change the uploaded PDF.":"По этому тексту Claude оценивает вакансии и пишет CV и cover letter. Правки не меняют загруженный PDF.","Save resume":"Сохранить резюме","Discard changes":"Отменить правки","New vacancies are scored against the saved text":"Новые вакансии оцениваются по сохранённому тексту","Could not restore the text.":"Не удалось вернуть текст."}'
```

Check the keys the old dashboard used and remove those with no hits:

```bash
for k in "Extracted text" "no keywords extracted" "Type" "Started at" "Statistics"; do echo "== $k"; grep -rnF "'$k'" app resources; done
```

Remove only the keys that printed no matches, with the removal command from Global Constraints.

- [ ] **Step 6: README**

Replace Usage item 1 with:

```markdown
1. **Upload your resume** (PDF) on the dashboard. Claude extracts your stack keywords. The keywords and the resume text can then be edited right on the dashboard: the keywords drive the search on every source, the text is what vacancies are scored against and what the documents are written from. The PDF only sets the design of the generated CV. Vacancies scored before an edit keep their score.
```

- [ ] **Step 7: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php lang/ru.json README.md tests/Feature/ResumeEditTest.php
git commit -F - <<'EOF'
feat: dashboard edits the resume text and keywords in place

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 5: Documents take their content from the resume text

**Files:**
- Modify: `app/Services/DocumentGenerator.php` (`generate`, from the `$workDir` block to the `claude->run` call)
- Test: `tests/Feature/DocumentGeneratorPromptTest.php`

**Interfaces:**
- Consumes: `ClaudeCli::run(string $prompt, ?int $timeout = null, array $allowedTools = [], ?string $workDir = null): string`.
- Produces: prompt that always contains a `CANDIDATE RESUME:` section with `$resume->text`; the PDF is copied and `Read` allowed only when a CV is generated and the PDF exists.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DocumentGeneratorPromptTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\Vacancy;
use App\Services\ClaudeCli;
use App\Services\DocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentGeneratorPromptTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $prompt = '';

    /** @var string[] */
    private array $tools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function generate(string $what, bool $withPdf): void
    {
        if ($withPdf) {
            File::ensureDirectoryExists(storage_path('app/private/resumes'));
            File::put(storage_path('app/private/resumes/cv.pdf'), '%PDF-1.4 stub');
        }
        $resume = Resume::create([
            'original_name' => 'cv.pdf',
            'path' => 'resumes/cv.pdf',
            'text' => 'EDITED: ten years of Go',
            'keywords' => [],
            'is_active' => true,
        ]);
        $vacancy = Vacancy::create([
            'source' => 'dou', 'external_id' => 'ext-1', 'title' => 'Go Developer',
            'company' => 'Acme', 'url' => 'https://example.test/1', 'status' => 'matched',
        ]);

        $claude = $this->createMock(ClaudeCli::class);
        $claude->expects($this->once())->method('run')->willReturnCallback(
            function (string $prompt, ?int $timeout = null, array $allowedTools = []) {
                $this->prompt = $prompt;
                $this->tools = $allowedTools;

                return "===RESUME_HTML===\n<html><body>cv</body></html>\n===COVER_LETTER===\nDear team";
            },
        );

        (new DocumentGenerator($claude))->generate($resume, $vacancy, $what);
    }

    public function test_the_cv_takes_its_content_from_the_text_and_only_its_design_from_the_pdf(): void
    {
        $this->generate('resume', withPdf: true);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringContainsString('original-resume.pdf', $this->prompt);
        $this->assertStringContainsString('ONLY as a design template', $this->prompt);
        $this->assertSame(['Read'], $this->tools);
    }

    public function test_without_a_pdf_the_cv_prompt_still_carries_the_text(): void
    {
        $this->generate('resume', withPdf: false);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringNotContainsString('original-resume.pdf', $this->prompt);
        $this->assertSame([], $this->tools);
    }

    public function test_a_cover_letter_alone_reads_the_text_and_not_the_pdf(): void
    {
        $this->generate('cover_letter', withPdf: true);

        $this->assertStringContainsString("CANDIDATE RESUME:\nEDITED: ten years of Go", $this->prompt);
        $this->assertStringNotContainsString('original-resume.pdf', $this->prompt);
        $this->assertSame([], $this->tools);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DocumentGeneratorPromptTest`
Expected: FAIL. With a PDF the CV prompt has no `CANDIDATE RESUME:` section, and the cover-letter-only prompt mentions `original-resume.pdf` with `['Read']` tools.

- [ ] **Step 3: Rewrite the prompt assembly**

In `DocumentGenerator::generate` replace everything from the comment `// Give claude the original PDF so it can replicate...` down to and including the `try { $text = $this->claude->run(...); } finally { ... }` block with:

```php
        // The original PDF only shows claude how the CV should look. The facts come from the
        // resume text, which the user may have edited since the upload, so a cover letter alone
        // does not need the PDF at all.
        $workDir = storage_path("app/claude-work/vacancy-{$vacancy->id}");
        File::ensureDirectoryExists($workDir);
        $originalPdf = storage_path("app/private/{$resume->path}");
        $usePdf = $wantResume && is_file($originalPdf);
        if ($usePdf) {
            File::copy($originalPdf, "{$workDir}/original-resume.pdf");
        }

        $tasks = [];
        $format = [];
        if ($wantResume) {
            $tasks[] = $usePdf
                ? '- The file original-resume.pdf in the current directory is the candidate\'s original resume. Use it ONLY '
                  . 'as a design template: study its visual design (layout, fonts, colors, spacing, section order, dividers), '
                  . 'but take NO content from it, it may be outdated. Produce a tailored version of the CANDIDATE RESUME above '
                  . 'as a COMPLETE standalone HTML document that replicates the original design as closely as possible. Follow '
                  . 'its section order and overall look; only adapt wording and emphasis to this vacancy. Do not invent facts '
                  . "that are not in the CANDIDATE RESUME.\n"
                  . '  HTML constraints (it will be rendered to PDF by dompdf): one self-contained file with an inline '
                  . '<style> block; NO external resources, images, web fonts or JavaScript; NO flexbox or CSS grid — use '
                  . "simple block elements and tables for multi-column areas; fonts limited to 'DejaVu Sans', 'DejaVu Serif' "
                  . "or 'DejaVu Sans Mono'; set @page margins to roughly match the original."
                : '- Produce a tailored version of the CANDIDATE RESUME above as a COMPLETE standalone HTML document with a '
                  . 'clean, professional single-column design (self-contained, inline <style>, no external resources, no '
                  . 'flexbox/grid, DejaVu fonts only). Do not invent facts that are not in the CANDIDATE RESUME.';
            $format[] = "===RESUME_HTML===\n<complete html document>";
        }
        if ($wantCover) {
            $coverLangInstruction = $coverLanguage === 'auto'
                ? 'written in the same language as the vacancy description'
                : 'written in ' . self::LANGUAGES[$coverLanguage];
            $tasks[] = "- Write a concise, specific cover letter in Markdown (max ~300 words) for this vacancy, {$coverLangInstruction}. "
                . 'Take the candidate\'s background only from the CANDIDATE RESUME above and do not invent facts.';
            if ($extraInstructions !== null && trim($extraInstructions) !== '') {
                $tasks[] = '- Additional instructions from the candidate for the cover letter, follow them: ' . trim($extraInstructions);
            }
            $format[] = "===COVER_LETTER===\n<cover letter markdown>";
        }

        $prompt = 'You are helping a candidate apply for a job. The resume must be in English; '
            . "the cover letter language is specified in its task.\n\n"
            . "VACANCY:\n"
            . "Title: {$vacancy->title}\nCompany: {$vacancy->company}\nLocation: {$vacancy->location}\n"
            . "Description:\n" . mb_substr(strip_tags((string) $vacancy->description), 0, 6000) . "\n\n"
            . "CANDIDATE RESUME:\n" . $resume->text . "\n\n"
            . "TASKS:\n" . implode("\n", $tasks) . "\n\n"
            . "Respond in EXACTLY this format with these delimiters and nothing else:\n"
            . implode("\n", $format);

        try {
            $text = $this->claude->run($prompt, allowedTools: $usePdf ? ['Read'] : [], workDir: $workDir);
        } finally {
            File::deleteDirectory($workDir);
        }
```

The output parsing below this block stays unchanged.

- [ ] **Step 4: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DocumentGenerator.php tests/Feature/DocumentGeneratorPromptTest.php
git commit -F - <<'EOF'
feat: generated documents take their content from the resume text

The uploaded PDF now serves only as the design template of the CV, so
edits to the resume text reach the CV and the cover letter.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 6: Save edited documents and show their PDF inline

**Files:**
- Modify: `routes/web.php` (two routes after `vacancies.download`)
- Modify: `app/Http/Controllers/VacancyController.php` (`download` refactor, new `pdf`, `updateDocument`, private helpers; add `use Illuminate\Support\Facades\File;`)
- Modify: `lang/ru.json`
- Test: `tests/Feature/VacancyDocumentsTest.php`

**Interfaces:**
- Produces routes: `vacancies.pdf` (`GET /vacancies/{vacancy}/pdf/{doc}`, `doc` in `resume,cover_letter`, `application/pdf` inline), `vacancies.documents.update` (`PUT /vacancies/{vacancy}/documents/{doc}`, field `html`, redirects to `vacancies.show` with `#cv` / `#cover`).
- Produces private helpers on `VacancyController`, used by Task 7:
  - `documentFile(Vacancy $vacancy, string $doc): ?string`, absolute path or null.
  - `documentHtml(Vacancy $vacancy, string $doc, string $full): string`, the document as a full HTML page (Markdown rendered into `pdf.document`).
  - `wrapDocument(Vacancy $vacancy, string $doc, string $body): string`.
  - `renderPdf(Vacancy $vacancy, string $doc, string $full): \Barryvdh\DomPDF\PDF`.
  - `replaceBody(string $document, string $body): ?string`.
  - `sanitizeHtml(string $html): string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/VacancyDocumentsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VacancyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // Documents live in storage/app/private/output/{id}, and test ids collide with real ones.
        $this->storage = sys_get_temp_dir() . '/jobchecker-test-' . uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @param array<string, string> $files file name in output/{id} => content */
    private function vacancyWith(array $files): Vacancy
    {
        $vacancy = Vacancy::create([
            'source' => 'dou', 'external_id' => 'ext-1', 'title' => 'PHP Developer',
            'company' => 'Acme', 'url' => 'https://example.test/1', 'status' => 'matched',
        ]);
        File::ensureDirectoryExists(storage_path("app/private/output/{$vacancy->id}"));
        foreach ($files as $name => $content) {
            File::put(storage_path("app/private/output/{$vacancy->id}/{$name}"), $content);
            $column = str_starts_with($name, 'resume') ? 'resume_path' : 'cover_letter_path';
            $vacancy->{$column} = "output/{$vacancy->id}/{$name}";
        }
        $vacancy->save();

        return $vacancy;
    }

    private function stored(Vacancy $vacancy, string $name): string
    {
        return File::get(storage_path("app/private/output/{$vacancy->id}/{$name}"));
    }

    public function test_saving_the_cv_replaces_its_body_keeps_its_style_and_strips_scripts(): void
    {
        $vacancy = $this->vacancyWith([
            'resume.html' => '<!doctype html><html><head><style>h1{color:#c00}</style></head><body class="cv"><h1>Old</h1></body></html>',
        ]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), [
            'html' => '<h1 onclick="x()">Опыт · Doświadczenie</h1><SCRIPT>alert(1)</SCRIPT><p><a href="javascript:alert(1)">link</a></p><iframe src="https://e.x"></iframe>',
        ])->assertRedirect(route('vacancies.show', $vacancy) . '#cv')->assertSessionHas('status');

        $html = $this->stored($vacancy, 'resume.html');
        $this->assertStringContainsString('<style>h1{color:#c00}</style>', $html);
        $this->assertStringContainsString('<body class="cv"><h1>Опыт · Doświadczenie</h1><p><a>link</a></p></body>', $html);
        $this->assertStringNotContainsStringIgnoringCase('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringNotContainsString('Old', $html);
    }

    public function test_saving_a_markdown_cover_letter_turns_it_into_html(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.md' => "Dear team\n"]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'cover_letter']), [
            'html' => '<p>Dear Acme team, dziękuję</p>',
        ])->assertRedirect(route('vacancies.show', $vacancy) . '#cover');

        $vacancy->refresh();
        $this->assertSame("output/{$vacancy->id}/cover_letter.html", $vacancy->cover_letter_path);
        $this->assertFileDoesNotExist(storage_path("app/private/output/{$vacancy->id}/cover_letter.md"));
        $html = $this->stored($vacancy, 'cover_letter.html');
        $this->assertStringContainsString('<body><p>Dear Acme team, dziękuję</p></body>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function test_a_legacy_markdown_cv_is_saved_as_html(): void
    {
        $vacancy = $this->vacancyWith(['resume.md' => "# Jane Doe\n"]);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<h1>Jane Roe</h1>']);

        $vacancy->refresh();
        $this->assertSame("output/{$vacancy->id}/resume.html", $vacancy->resume_path);
        $this->assertFileDoesNotExist(storage_path("app/private/output/{$vacancy->id}/resume.md"));
        $this->assertStringContainsString('<body><h1>Jane Roe</h1></body>', $this->stored($vacancy, 'resume.html'));
    }

    public function test_a_stored_cv_without_a_body_element_is_wrapped_in_the_template(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<h1>Old</h1>']);

        $this->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => '<h1>New</h1>']);

        $html = $this->stored($vacancy, 'resume.html');
        $this->assertStringContainsString('<body><h1>New</h1></body>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function test_saving_needs_html_and_an_existing_document(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<html><body>x</body></html>']);

        $this->from(route('vacancies.show', $vacancy))
            ->put(route('vacancies.documents.update', [$vacancy, 'resume']), ['html' => ''])
            ->assertSessionHasErrors('html');
        $this->put(route('vacancies.documents.update', [$vacancy, 'cover_letter']), ['html' => '<p>x</p>'])
            ->assertNotFound();
    }

    public function test_the_pdf_is_shown_inline_and_a_missing_document_is_not_found(): void
    {
        $vacancy = $this->vacancyWith(['resume.html' => '<html><body><h1>Jane Doe</h1></body></html>']);

        $response = $this->get(route('vacancies.pdf', [$vacancy, 'resume']))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));

        $this->get(route('vacancies.pdf', [$vacancy, 'cover_letter']))->assertNotFound();
    }

    public function test_an_html_cover_letter_downloads_as_html_and_as_pdf(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.html' => '<html><body><p>Dear team</p></body></html>']);

        $this->get(route('vacancies.download', [$vacancy, 'cover_letter']))
            ->assertDownload("vacancy-{$vacancy->id}-cover_letter.html");
        $this->get(route('vacancies.download', [$vacancy, 'cover_letter', 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VacancyDocumentsTest`
Expected: FAIL, routes `vacancies.documents.update` and `vacancies.pdf` are not defined.

- [ ] **Step 3: Routes**

In `routes/web.php` after the `vacancies.download` route add:

```php
    Route::get('/vacancies/{vacancy}/pdf/{doc}', [VacancyController::class, 'pdf'])
        ->whereIn('doc', ['resume', 'cover_letter'])
        ->name('vacancies.pdf');
    Route::put('/vacancies/{vacancy}/documents/{doc}', [VacancyController::class, 'updateDocument'])
        ->whereIn('doc', ['resume', 'cover_letter'])
        ->name('vacancies.documents.update');
```

- [ ] **Step 4: Controller**

Add `use Illuminate\Support\Facades\File;` to the imports of `VacancyController`.

Replace the whole `download` method with:

```php
    public function download(Vacancy $vacancy, string $doc, string $format = 'md')
    {
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        if ($format !== 'pdf') {
            $ext = pathinfo($full, PATHINFO_EXTENSION);

            return response()->download($full, "vacancy-{$vacancy->id}-{$doc}.{$ext}");
        }

        return $this->renderPdf($vacancy, $doc, $full)->download("vacancy-{$vacancy->id}-{$doc}.pdf");
    }

    /** The same PDF as the download, opened by the browser's own viewer. */
    public function pdf(Vacancy $vacancy, string $doc)
    {
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        return $this->renderPdf($vacancy, $doc, $full)->stream("vacancy-{$vacancy->id}-{$doc}.pdf");
    }

    public function updateDocument(Request $request, Vacancy $vacancy, string $doc)
    {
        $data = $request->validate(['html' => ['required', 'string', 'max:200000']]);
        $full = $this->documentFile($vacancy, $doc);
        abort_unless($full, 404);

        // The edited body goes back into the stored page, so its <head> and <style> survive.
        // Markdown has no page yet and gets the template its PDF was rendered with.
        $body = $this->sanitizeHtml($data['html']);
        $html = $this->replaceBody($this->documentHtml($vacancy, $doc, $full), $body)
            ?? $this->wrapDocument($vacancy, $doc, $body);

        $column = $doc === 'resume' ? 'resume_path' : 'cover_letter_path';
        $name = $doc === 'resume' ? 'resume.html' : 'cover_letter.html';
        $target = dirname($full) . "/{$name}";
        File::put($target, $html);
        if ($target !== $full) {
            File::delete($full);
        }
        $vacancy->update([$column => dirname($vacancy->{$column}) . "/{$name}"]);

        return redirect(route('vacancies.show', $vacancy) . ($doc === 'resume' ? '#cv' : '#cover'))
            ->with('status', __('Document saved.'));
    }

    /** Absolute path of a generated document, or null when there is none on disk. */
    private function documentFile(Vacancy $vacancy, string $doc): ?string
    {
        $path = $doc === 'resume' ? $vacancy->resume_path : $vacancy->cover_letter_path;
        $full = $path ? storage_path("app/private/{$path}") : null;

        return $full && is_file($full) ? $full : null;
    }

    /** The document as a full HTML page: stored HTML as is, Markdown inside the PDF template. */
    private function documentHtml(Vacancy $vacancy, string $doc, string $full): string
    {
        $content = file_get_contents($full);

        return str_ends_with($full, '.html')
            ? $content
            : $this->wrapDocument($vacancy, $doc, Str::markdown($content, ['html_input' => 'strip']));
    }

    private function wrapDocument(Vacancy $vacancy, string $doc, string $body): string
    {
        return view('pdf.document', [
            'title' => ($doc === 'resume' ? 'Resume — ' : 'Cover letter — ') . $vacancy->title,
            'html' => $body,
        ])->render();
    }

    private function renderPdf(Vacancy $vacancy, string $doc, string $full): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadHTML($this->documentHtml($vacancy, $doc, $full))->setPaper('a4');
    }

    /** $document with $body inside its <body>, or null when it has no body element. */
    private function replaceBody(string $document, string $body): ?string
    {
        $result = preg_replace_callback(
            '/(<body\b[^>]*>).*(<\/body>)/is',
            fn (array $m) => $m[1] . $body . $m[2],
            $document,
            1,
            $count,
        );

        return $count === 1 ? $result : null;
    }

    /**
     * Drops what could run code from editor HTML. There is one user, but the HTML is
     * rendered by dompdf and opened in the browser from this origin.
     */
    private function sanitizeHtml(string $html): string
    {
        $dom = new \DOMDocument;
        // The XML declaration makes libxml read the fragment as UTF-8 instead of Latin-1.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//iframe|//object|//embed')) as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//@*')) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $scriptUrl = in_array($name, ['href', 'src'], true) && preg_match('/^\s*javascript:/i', $attribute->nodeValue);
            if (str_starts_with($name, 'on') || $scriptUrl) {
                $attribute->ownerElement->removeAttribute($attribute->nodeName);
            }
        }

        $clean = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }

        return $clean;
    }
```

`previewResume` and its route stay until Task 7, which removes them together with the view that uses them.

- [ ] **Step 5: Translations**

```bash
php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"Document saved.":"Документ сохранён."}'
```

- [ ] **Step 6: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/VacancyController.php lang/ru.json tests/Feature/VacancyDocumentsTest.php
git commit -F - <<'EOF'
feat: save hand-edited documents and show their PDF inline

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 7: Vacancy page with the documents panel

**Files:**
- Modify: `app/Http/Controllers/VacancyController.php` (`show`, new private `editorParts`, delete `previewResume`)
- Modify: `routes/web.php` (delete the `vacancies.preview-resume` route)
- Rewrite: `resources/views/vacancies/show.blade.php`
- Create: `resources/views/partials/doc-pane.blade.php`
- Modify: `lang/ru.json`, `README.md`
- Test: `tests/Feature/VacancyDocumentsTest.php` (four more tests)

**Interfaces:**
- Consumes: Task 6 helpers `documentFile`, `documentHtml`; routes `vacancies.pdf`, `vacancies.documents.update`, `vacancies.download`, `vacancies.generate`; Task 1 classes `.page.-wider`, `.vacancy-grid`, `.vacancy-main`, `.doc-*`, `.tabs`, `.tab`, `.tdot`, `.vswitch`, `.vbtn`, `.btn-ok-line`.
- Produces: view variable `$docs` = `['resume' => ?array, 'cover_letter' => ?array]`, each array `['body' => string, 'style' => string, 'ext' => 'html'|'md']`. Replaces `resumeIsHtml`, `resumeMd`, `coverMd`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/VacancyDocumentsTest.php`:

```php
    public function test_a_vacancy_without_documents_offers_to_generate_them(): void
    {
        $vacancy = $this->vacancyWith([]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee('href="https://example.test/1"', false)
            ->assertSee(route('vacancies.generate', [$vacancy, 'resume']) . '#cv', false)
            ->assertSee(route('vacancies.generate', [$vacancy, 'cover_letter']) . '#cover', false)
            ->assertDontSee(route('vacancies.pdf', [$vacancy, 'resume']), false)
            ->assertDontSee('data-confirm', false);
    }

    public function test_a_vacancy_with_a_cv_shows_its_pdf_and_editor(): void
    {
        $vacancy = $this->vacancyWith([
            'resume.html' => '<html><head><style>h1{color:#c00}</style></head><body><h1>Jane Doe</h1></body></html>',
        ]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee(route('vacancies.pdf', [$vacancy, 'resume']), false)
            ->assertSee(route('vacancies.documents.update', [$vacancy, 'resume']), false)
            ->assertSee('&lt;h1&gt;Jane Doe&lt;/h1&gt;', false)
            ->assertSee('h1{color:#c00}', false)
            // regenerating would overwrite manual edits, so it asks first
            ->assertSee('data-confirm', false);
    }

    public function test_an_html_cover_letter_is_edited_as_html(): void
    {
        $vacancy = $this->vacancyWith(['cover_letter.html' => '<html><body><p>Dear Acme team</p></body></html>']);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee(route('vacancies.pdf', [$vacancy, 'cover_letter']), false)
            ->assertSee('&lt;p&gt;Dear Acme team&lt;/p&gt;', false);
    }

    public function test_a_legacy_markdown_cv_opens_in_the_editor_as_html(): void
    {
        $vacancy = $this->vacancyWith(['resume.md' => "# Jane Doe\n"]);

        $this->get(route('vacancies.show', $vacancy))->assertOk()
            ->assertSee('&lt;h1&gt;Jane Doe&lt;/h1&gt;', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VacancyDocumentsTest`
Expected: FAIL on the four new tests (no `#cv` actions, no `vacancies.pdf` iframe, no editor source).

- [ ] **Step 3: Controller**

Replace `VacancyController::show` with:

```php
    public function show(Vacancy $vacancy)
    {
        $docs = [];
        foreach (['resume', 'cover_letter'] as $doc) {
            $full = $this->documentFile($vacancy, $doc);
            $docs[$doc] = $full
                ? $this->editorParts($this->documentHtml($vacancy, $doc, $full)) + ['ext' => pathinfo($full, PATHINFO_EXTENSION)]
                : null;
        }
        $company = Company::forName($vacancy->company);

        return view('vacancies.show', [
            'vacancy' => $vacancy,
            'docs' => $docs,
            'generating' => Cache::get("vacancy-generating:{$vacancy->id}"),
            'generationError' => Cache::pull("vacancy-generating-error:{$vacancy->id}"),
            'hasResume' => Resume::active() !== null,
            'company' => $company,
            'companyResearching' => $company && Cache::has("company-researching:{$company->id}"),
        ]);
    }
```

Add next to the other private helpers:

```php
    /**
     * What the editor edits (the body) and the styles it shows it with, so the
     * document looks in the editor the way it looks in the PDF.
     *
     * @return array{body: string, style: string}
     */
    private function editorParts(string $html): array
    {
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles);
        $body = preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $m)
            ? $m[1]
            : preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        return ['body' => trim($body), 'style' => implode("\n", $styles[1])];
    }
```

Delete the `previewResume` method. In `routes/web.php` delete the `vacancies.preview-resume` route (two lines). Confirm nothing else uses it: `grep -rn "preview-resume\|previewResume" app resources routes tests` prints nothing.

- [ ] **Step 4: Create the documents pane partial**

Create `resources/views/partials/doc-pane.blade.php`:

```blade
{{-- One tab of the documents panel. Expects $doc ('resume' | 'cover_letter') plus the
     $vacancy, $docs, $generating and $hasResume of vacancies.show. --}}
@php
    $hash = $doc === 'resume' ? 'cv' : 'cover';
    $parts = $docs[$doc];
    $isCover = $doc === 'cover_letter';
    $busy = in_array($generating, [$doc, 'both'], true);
    $formId = "generate-{$hash}";
    // The fragment survives the redirect back, so the page reopens on this tab.
    $generateUrl = route('vacancies.generate', [$vacancy, $doc]) . "#{$hash}";
    $languages = \App\Services\DocumentGenerator::languageLabels();
    $defaultLanguage = \App\Models\Setting::get('cover_letter_language');
@endphp
<div class="doc-pane" data-pane="{{ $hash }}" @if ($hash !== 'cv') hidden @endif>
    <div class="doc-head">
        <div class="tabs" role="tablist">
            <button type="button" role="tab" @class(['tab', 'is-active' => $hash === 'cv']) data-tab="cv" aria-selected="{{ $hash === 'cv' ? 'true' : 'false' }}"><span @class(['tdot', '-ok' => $docs['resume']])></span>CV</button>
            <button type="button" role="tab" @class(['tab', 'is-active' => $hash === 'cover']) data-tab="cover" aria-selected="{{ $hash === 'cover' ? 'true' : 'false' }}"><span @class(['tdot', '-ok' => $docs['cover_letter']])></span>Cover letter</button>
        </div>
        @if ($parts)
            <form id="{{ $formId }}" method="post" action="{{ $generateUrl }}" class="stack" style="gap:8px"
                  data-confirm="{{ __('Regenerating will overwrite your manual edits. Continue?') }}">
                @csrf
                @if ($isCover)
                    <select name="lang" aria-label="{{ __('Cover letter language') }}" style="width:auto;padding:6px 10px;font-size:13px">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}" @selected($defaultLanguage === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-sm btn-info" data-toggle-instructions>{{ __('Instructions') }}</button>
                @endif
                <button type="submit" class="btn btn-sm btn-info" @disabled(! $hasResume || $generating)
                        @unless ($hasResume) title="{{ __('Upload a base resume on the dashboard to generate documents.') }}" @endunless>
                    {{ $isCover ? __('Regenerate') : __('Regenerate CV') }}
                </button>
            </form>
        @endif
    </div>

    @if ($parts && $isCover)
        <div class="doc-instructions" data-instructions hidden>
            <textarea name="extra_instructions" form="{{ $formId }}" rows="2" maxlength="2000" aria-label="{{ __('Extra instructions') }}" style="font-size:13px"
                      placeholder="{{ __('Extra instructions (optional): what to emphasize, what to leave out…') }}">{{ old('extra_instructions') }}</textarea>
        </div>
    @endif

    @if ($busy)
        <div class="doc-empty">
            <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                <span>{{ __('Generating :what… the page will refresh by itself.', ['what' => $isCover ? 'cover letter' : __('resume')]) }}</span>
            </div>
        </div>
    @elseif ($parts)
        <div class="doc-bar">
            <div class="vswitch">
                <button type="button" class="vbtn is-active" data-mode="pdf">{{ __('PDF preview') }}</button>
                <button type="button" class="vbtn" data-mode="edit">{{ __('Editor') }}</button>
            </div>
            <div class="stack" style="gap:8px">
                <a href="{{ route('vacancies.download', [$vacancy, $doc]) }}" class="btn btn-sm btn-ghost">{{ __('Source (:ext)', ['ext' => '.' . $parts['ext']]) }}</a>
                <a href="{{ route('vacancies.download', [$vacancy, $doc, 'pdf']) }}" class="btn btn-sm btn-primary">{{ __('Download PDF') }}</a>
            </div>
        </div>
        <iframe class="doc-frame" data-view="pdf" src="{{ route('vacancies.pdf', [$vacancy, $doc]) }}" title="{{ $isCover ? 'Cover letter' : 'CV' }} PDF" loading="lazy"></iframe>
        <form method="post" action="{{ route('vacancies.documents.update', [$vacancy, $doc]) }}" class="doc-editor" data-view="edit" data-editor hidden>
            @csrf
            @method('PUT')
            <textarea name="html" hidden data-editor-source>{{ $parts['body'] }}</textarea>
            <textarea hidden data-editor-style>{{ $parts['style'] }}</textarea>
            <div class="doc-editor-area"><textarea data-editor-target></textarea></div>
            <div class="doc-foot">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" data-mode="pdf">{{ __('Cancel') }}</button>
                <span class="faint" style="margin-left:auto;font-size:12px">{{ __('The PDF is rebuilt after saving') }}</span>
            </div>
        </form>
    @else
        <div class="doc-empty">
            <span style="width:52px;height:52px;border-radius:14px;background:var(--info-dim);color:var(--info);display:grid;place-items:center">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M9 13h6M9 17h4"/></svg>
            </span>
            <div>
                <div style="font-weight:600;font-size:16px">{{ $isCover ? __('Cover letter not created yet') : __('CV not created yet') }}</div>
                <div class="help" style="margin-top:4px;font-size:13px">
                    {{ $isCover
                        ? __('Claude will write a letter for this vacancy from your resume text, it takes a minute or two.')
                        : __('Claude will tailor your resume to this vacancy in the design of your PDF, it takes a minute or two.') }}
                </div>
            </div>
            <form method="post" action="{{ $generateUrl }}" style="display:flex;flex-direction:column;gap:10px;width:100%;max-width:420px;text-align:left">
                @csrf
                @if ($isCover)
                    <label class="lab" for="cover-lang">{{ __('Cover letter language') }}</label>
                    <select id="cover-lang" name="lang">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}" @selected($defaultLanguage === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="lab" for="cover-instructions">{{ __('Extra instructions') }}</label>
                    <textarea id="cover-instructions" name="extra_instructions" rows="3" maxlength="2000" style="font-size:13px"
                              placeholder="{{ __('Extra instructions (optional): what to emphasize, what to leave out…') }}">{{ old('extra_instructions') }}</textarea>
                @endif
                <button type="submit" class="btn btn-info" @disabled(! $hasResume || $generating)>
                    {{ $isCover ? __('Generate cover letter') : __('Generate CV') }}
                </button>
            </form>
            @unless ($hasResume)
                <div class="faint" style="font-size:12.5px">{{ __('Upload a base resume on the dashboard to generate documents.') }}</div>
            @endunless
        </div>
    @endif
</div>
```

- [ ] **Step 5: Rewrite the vacancy view**

Replace `resources/views/vacancies/show.blade.php` with:

```blade
@extends('layouts.app')

@section('title', $vacancy->title)
@section('page-class', '-wide -wider')

@section('content')
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
        <a href="{{ route('vacancies.index') }}" class="muted" style="font-size:13px">← {{ __('back to vacancies') }}</a>
        <span class="mono faint" style="font-size:12px">#{{ $vacancy->id }}</span>
    </div>

    @if ($generationError)
        <div class="flash -err">{{ __('The last generation failed: :error', ['error' => $generationError]) }}</div>
    @endif

    <div class="vacancy-grid">
        <div class="vacancy-main">
            <section class="card">
                <div style="padding:22px 22px 18px;display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
                    <div style="min-width:0;flex:1">
                        <div class="stack" style="gap:8px;margin-bottom:10px">
                            <span class="badge -{{ $vacancy->status }}">{{ $vacancy->status }}</span>
                            <span class="tag">{{ $vacancy->source }}</span>
                            @if ($vacancy->bumped_at)
                                <span class="badge -bumped" title="{{ __('The source republished this vacancy, it was scored again') }}">↑ {{ __('bumped :time', ['time' => $vacancy->bumped_at->format('d.m.Y H:i')]) }}</span>
                            @endif
                            @if ($vacancy->applied_at)
                                <span class="badge -solid">✓ {{ __('applied :date', ['date' => $vacancy->applied_at->format('d.m.Y')]) }}</span>
                            @endif
                            @if ($vacancy->muted_at)
                                <span class="badge -neutral" title="{{ __('This vacancy is not sent to Telegram, nor are its copies from other sources') }}">🔕 {{ __('muted in Telegram') }}</span>
                            @endif
                        </div>
                        <h1 style="font-size:25px;line-height:1.25">{{ $vacancy->title }}</h1>
                        <div class="stack" style="gap:8px;margin-top:10px;color:var(--muted);font-size:14px">
                            <span style="color:var(--text);font-weight:600">{{ $vacancy->company ?? '—' }}</span>
                            <span class="faint">·</span>
                            <span>{{ $vacancy->location ?? '—' }}</span>
                            @if ($vacancy->salary)<span class="faint">·</span><span class="mono" style="color:var(--ok);font-size:13.5px">{{ $vacancy->salary }}</span>@endif
                        </div>
                    </div>
                    @if ($vacancy->score !== null)
                        @php($s = (int) $vacancy->score)
                        @php($scoreColor = $s >= 80 ? 'var(--ok)' : ($s >= 60 ? 'var(--signal)' : ($s >= 40 ? 'var(--info)' : 'var(--neutral)')))
                        <div style="flex:none;text-align:center;padding:10px 16px;border:1px solid var(--line-soft);border-radius:10px;background:var(--raised)">
                            <div class="eyebrow" style="margin-bottom:4px">{{ __('Match') }}</div>
                            <div class="mono" style="font-size:34px;font-weight:700;line-height:1;color:{{ $scoreColor }}">{{ $s }}</div>
                            <span style="display:block;width:110px;height:6px;margin-top:10px;border-radius:999px;background:var(--raised-2);overflow:hidden">
                                <span style="display:block;height:100%;width:{{ max(4, min(100, $s)) }}%;background:{{ $scoreColor }}"></span>
                            </span>
                        </div>
                    @endif
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:14px 22px;border-top:1px solid var(--line-soft)">
                    <a href="{{ $vacancy->url }}" target="_blank" rel="noopener" class="btn btn-primary" style="min-height:46px;padding:12px 20px;font-size:15px">
                        {{ __('Open on the site') }}
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M7 7h10v10"/></svg>
                    </a>
                    <form method="post" action="{{ route('vacancies.applied', $vacancy) }}">
                        @csrf
                        <button type="submit" class="btn {{ $vacancy->applied_at ? 'btn-ghost' : 'btn-ok-line' }}">
                            {{ $vacancy->applied_at ? __('Clear the applied mark') : '✓ ' . __('Mark as applied') }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('vacancies.muted', $vacancy) }}" style="margin-left:auto">
                        @csrf
                        <button type="submit" class="btn btn-ghost" title="{{ __('The vacancy and its copies from other sources will not be sent to Telegram') }}">
                            {{ $vacancy->muted_at ? '🔔 ' . __('Send to Telegram') : '🔕 ' . __('Do not send to Telegram') }}
                        </button>
                    </form>
                </div>
            </section>

            @php($bd = $vacancy->score_breakdown ?? [])
            @php($lang = $vacancy->analysis['language'] ?? null)
            @if ($vacancy->score_reason || is_array($bd['criteria'] ?? null) || ($vacancy->analysis['summary'] ?? null) || $lang)
                <section class="card">
                    <div class="card-head">
                        <h3>{{ __('Scoring') }}</h3>
                        @if ($bd['rechecked'] ?? false)
                            <span class="hint">{{ __('rechecked: :scores → final :score', ['scores' => implode(' / ', $bd['run_scores'] ?? []), 'score' => $vacancy->score]) }}</span>
                        @endif
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                        @if ($vacancy->score_reason)
                            <div style="padding:14px 16px;border-radius:10px;background:var(--signal-dim);border:1px solid var(--signal-line);font-size:14px;line-height:1.6">
                                {{ $vacancy->score_reason }}
                            </div>
                        @endif

                        @if (is_array($bd['criteria'] ?? null))
                            @php($shares = app(\App\Services\VacancyScorer::class)->normalizeWeights($bd['weights'] ?? []))
                            <div class="rubric" style="margin-top:0">
                                @foreach (\App\Services\VacancyScorer::labels() as $key => $label)
                                    @php($c = $bd['criteria'][$key] ?? ['score' => 0, 'matched' => [], 'missing' => []])
                                    @php($n = max(0, min(10, (int) ($c['score'] ?? 0))))
                                    @php($color = $n >= 8 ? 'var(--ok)' : ($n >= 6 ? 'var(--signal)' : ($n >= 4 ? 'var(--info)' : 'var(--neutral)')))
                                    <div class="rubric-row">
                                        <span class="rl">{{ $label }}<small>{{ __('weight :percent%', ['percent' => round($shares[$key] * 100)]) }}</small></span>
                                        <span class="rv" style="color:{{ $color }}">{{ $n }}/10</span>
                                        <span class="track"><span class="fill" style="width:{{ $n * 10 }}%;background:{{ $color }}"></span></span>
                                        @if (! empty($c['matched']) || ! empty($c['missing']))
                                            <span class="rubric-ev">
                                                @foreach ($c['matched'] ?? [] as $item)
                                                    <span class="-yes">{{ $item }}</span>
                                                @endforeach
                                                @foreach ($c['missing'] ?? [] as $item)
                                                    <span>{{ __('missing: :item', ['item' => $item]) }}</span>
                                                @endforeach
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @php($notes = [])
                            @if (($bd['language_fit'] ?? null) === 'critical')
                                @php($notes[] = __('critical language barrier: score capped at 40'))
                            @elseif (($bd['language_fit'] ?? null) === 'warning')
                                @php($notes[] = __('language barrier: −15 from the weighted sum (:base)', ['base' => $bd['base_score'] ?? '?']))
                            @endif
                            @if ($bd['foreign_language'] ?? null)
                                @php($notes[] = __('rejected: the vacancy language :language is not among the known languages', ['language' => $bd['foreign_language']]))
                            @endif
                            @if ($notes)
                                <div class="faint mono" style="font-size:12px">{{ implode(' · ', $notes) }}</div>
                            @endif
                        @endif

                        @if ($vacancy->analysis['summary'] ?? null)
                            <div style="padding:14px 16px;border-radius:10px;background:var(--raised);border:1px solid var(--line-soft);font-size:14px;line-height:1.6;color:var(--muted)">
                                {{ $vacancy->analysis['summary'] }}
                            </div>
                        @endif

                        @if ($lang)
                            <div class="stack" style="flex-wrap:wrap">
                                @if ($lang['vacancy_language'] ?? null)
                                    <span class="tag">{{ __('Vacancy language: :language', ['language' => $lang['vacancy_language']]) }}</span>
                                @endif
                                @if (! empty($lang['required_languages']))
                                    <span class="tag">{{ __('Required: :languages', ['languages' => implode(', ', $lang['required_languages'])]) }}</span>
                                @endif
                            </div>
                            @if (in_array($lang['language_fit'] ?? null, ['warning', 'critical'], true) && ($lang['note'] ?? ''))
                                @php($critical = $lang['language_fit'] === 'critical')
                                <div style="padding:12px 16px;border-radius:8px;font-size:13.5px;line-height:1.55;border:1px solid {{ $critical ? 'var(--danger)' : 'var(--warn)' }};background:{{ $critical ? 'var(--danger-dim)' : 'var(--warn-dim)' }};color:{{ $critical ? 'var(--danger)' : 'var(--warn)' }}">
                                    {{ $critical ? '‼️' : '⚠️' }} {{ $lang['note'] }}
                                </div>
                            @endif
                        @endif
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card-head"><h3>{{ __('Vacancy description') }}</h3></div>
                <div class="card-body">
                    <div class="markdown-box" style="background:none;border:0;padding:0">{!! Str::of((string) $vacancy->description)->stripTags('<p><br><ul><ol><li><b><strong><i><em><h1><h2><h3><h4><a>') !!}</div>
                </div>
            </section>

            <section class="card">
                <div class="card-head">
                    <h3>{{ __('About the company') }}</h3>
                    @if ($company?->researched_at)
                        <span class="hint">{{ __('updated :time', ['time' => $company->researched_at->format('d.m.Y H:i')]) }}</span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($company?->last_error && ! $companyResearching)
                        <div class="flash -err">{{ __('The last research failed: :error', ['error' => $company->last_error]) }}</div>
                    @endif

                    @if ($companyResearching)
                        <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                            <span>{{ __('Researching the company (1-3 minutes)… the page will refresh by itself.') }}</span>
                        </div>
                        @unless ($generating)
                            <script>setInterval(function(){location.reload();}, 8000);</script>
                        @endunless
                    @elseif ($company?->research)
                        @php($r = $company->research)
                        @if ($r['company_info'] ?? '')
                            <p style="margin-top:0;font-size:14px;line-height:1.6">{{ $r['company_info'] }}</p>
                        @endif
                        @if (($r['found'] ?? false) === false)
                            <div class="faint" style="font-size:13px;margin-bottom:14px">{{ __('No reliable information about the company was found.') }}</div>
                        @endif

                        @php($aspectMeta = [
                            'turnover' => ['label' => __('Turnover'), 'values' => ['low' => [__('low'), '-ok'], 'medium' => [__('medium'), '-neutral'], 'high' => [__('high'), '-failed']]],
                            'work_life_balance' => ['label' => 'Work-life balance', 'values' => ['good' => [__('good'), '-ok'], 'mixed' => [__('mixed'), '-neutral'], 'poor' => [__('poor'), '-failed']]],
                            'ceo' => ['label' => __('Attitude to management'), 'values' => ['positive' => [__('positive'), '-ok'], 'mixed' => [__('ambivalent'), '-neutral'], 'negative' => [__('negative'), '-failed']]],
                            'compensation' => ['label' => __('Compensation'), 'values' => ['above_market' => [__('above market'), '-ok'], 'market' => [__('at market'), '-neutral'], 'below_market' => [__('below market'), '-failed']]],
                        ])
                        <div style="display:grid;gap:10px;margin-bottom:16px">
                            @foreach ($aspectMeta as $key => $meta)
                                @php($aspect = $r['aspects'][$key] ?? [])
                                @php([$valueLabel, $badgeClass] = $meta['values'][$aspect['assessment'] ?? 'unknown'] ?? [__('unknown'), '-neutral'])
                                <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
                                    <span style="font-weight:600;font-size:13.5px;min-width:190px">{{ $meta['label'] }}</span>
                                    <span class="badge {{ $badgeClass }}">{{ $valueLabel }}@if ($key === 'ceo' && ($aspect['approval_percent'] ?? null) !== null) · {{ $aspect['approval_percent'] }}%@endif</span>
                                    @if ($aspect['note'] ?? '')
                                        <span class="muted" style="font-size:13px;flex:1;min-width:220px">{{ $aspect['note'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if (! empty($r['sources']))
                            <details class="box" style="margin-bottom:16px">
                                <summary>{{ __('Sources (:count)', ['count' => count($r['sources'])]) }}</summary>
                                <div class="details-body">
                                    @foreach ($r['sources'] as $source)
                                        <div style="margin-bottom:14px">
                                            <div class="stack" style="flex-wrap:wrap">
                                                <span style="font-weight:600;font-size:13.5px">{{ $source['name'] ?? '' }}</span>
                                                @if (($source['rating'] ?? null) !== null)<span class="mono" style="color:var(--signal);font-size:13px">{{ $source['rating'] }}/5</span>@endif
                                                @if (($source['reviews_count'] ?? null) !== null)<span class="faint" style="font-size:12.5px">{{ trans_choice(':count review|:count reviews', $source['reviews_count']) }}</span>@endif
                                                @if ($source['url'] ?? null)<a href="{{ $source['url'] }}" target="_blank" rel="noopener" style="font-size:12.5px">{{ __('open') }} ↗</a>@endif
                                            </div>
                                            @if (! empty($source['key_points']))
                                                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;line-height:1.6;color:var(--muted)">
                                                    @foreach ($source['key_points'] as $point)
                                                        <li>{{ $point }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if ($r['verdict'] ?? '')
                            <div style="padding:14px 16px;border-radius:10px;background:var(--signal-dim);border:1px solid var(--signal-line);font-size:14px;line-height:1.6;margin-bottom:16px">
                                {{ $r['verdict'] }}
                            </div>
                        @endif

                        <form method="post" action="{{ route('vacancies.research-company', $vacancy) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm">{{ __('Refresh research') }}</button>
                        </form>
                    @else
                        <form method="post" action="{{ route('vacancies.research-company', $vacancy) }}">
                            @csrf
                            <div class="stack">
                                <button type="submit" class="btn btn-sm" @unless($vacancy->company) disabled @endunless>{{ __('Research the company') }}</button>
                                <span class="faint" style="font-size:12px;max-width:380px">
                                    @if ($vacancy->company)
                                        {{ __('Looks for employee reviews on Glassdoor, Indeed, DOU and others. Takes 1-3 minutes.') }}
                                    @else
                                        {{ __('The vacancy has no company.') }}
                                    @endif
                                </span>
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        </div>

        <aside class="card doc-panel" aria-label="{{ __('Application documents') }}">
            @include('partials.doc-pane', ['doc' => 'resume'])
            @include('partials.doc-pane', ['doc' => 'cover_letter'])
        </aside>
    </div>

    @if ($generating)
        <script>setInterval(function(){location.reload();}, 8000);</script>
    @endif
@endsection

@section('scripts')
<script>
    (function () {
        var panes = document.querySelectorAll('[data-pane]');
        var JODIT_JS = 'https://cdn.jsdelivr.net/npm/jodit@4.17.1/es2021/jodit.fat.min.js';
        var JODIT_CSS = 'https://cdn.jsdelivr.net/npm/jodit@4.17.1/es2021/jodit.fat.min.css';
        var joditReady = null;

        // The active tab lives in the hash, so a reload after generation reopens it.
        function showTab(hash) {
            panes.forEach(function (pane) { pane.hidden = pane.getAttribute('data-pane') !== hash; });
            document.querySelectorAll('[data-tab]').forEach(function (tab) {
                var on = tab.getAttribute('data-tab') === hash;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        showTab(location.hash === '#cover' ? 'cover' : 'cv');
        document.querySelectorAll('[data-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var hash = tab.getAttribute('data-tab');
                history.replaceState(null, '', '#' + hash);
                showTab(hash);
            });
        });

        // Jodit is loaded only when the editor is first opened.
        function loadJodit() {
            if (!joditReady) {
                joditReady = new Promise(function (resolve, reject) {
                    var css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = JODIT_CSS;
                    document.head.appendChild(css);
                    var script = document.createElement('script');
                    script.src = JODIT_JS;
                    script.onload = function () { resolve(window.Jodit); };
                    script.onerror = function () { joditReady = null; reject(new Error('jodit')); };
                    document.head.appendChild(script);
                });
            }
            return joditReady;
        }

        function setMode(pane, mode) {
            pane.querySelectorAll('.vbtn').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-mode') === mode); });
            pane.querySelectorAll('[data-view]').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== mode; });
        }

        function openEditor(form) {
            if (form.__editor) return Promise.resolve(form.__editor);
            return loadJodit().then(function (Jodit) {
                var area = form.querySelector('.doc-editor-area');
                // The document's own styles go into the editor iframe, so it looks like the PDF page.
                var editor = Jodit.make(form.querySelector('[data-editor-target]'), {
                    iframe: true,
                    iframeStyle: 'html{background:#fff}body{margin:0;padding:28px 32px;background:#fff;color:#1a1a1a}\n'
                        + form.querySelector('[data-editor-style]').value,
                    height: area.clientHeight || 600,
                    toolbarAdaptive: false,
                    showCharsCounter: false,
                    showWordsCounter: false,
                    showXPathInStatusbar: false,
                    askBeforePasteHTML: false,
                    theme: document.documentElement.getAttribute('data-theme') === 'light' ? 'default' : 'dark',
                    buttons: ['bold', 'italic', 'underline', '|', 'paragraph', 'ul', 'ol', 'table', 'link', '|', 'undo', 'redo', 'source'],
                });
                editor.value = form.querySelector('[data-editor-source]').defaultValue;
                form.__editor = editor;
                return editor;
            });
        }

        panes.forEach(function (pane) {
            var form = pane.querySelector('[data-editor]');
            pane.addEventListener('click', function (e) {
                if (e.target.closest('[data-toggle-instructions]')) {
                    var box = pane.querySelector('[data-instructions]');
                    box.hidden = !box.hidden;
                    return;
                }
                var button = e.target.closest('[data-mode]');
                if (!button || !form) return;
                if (button.getAttribute('data-mode') === 'edit') {
                    // Shown first, so the editor can take the height of its area.
                    setMode(pane, 'edit');
                    openEditor(form).catch(function () {
                        setMode(pane, 'pdf');
                        alert(@json(__('Could not load the editor. Check the internet connection.')));
                    });
                } else {
                    // Cancel drops the unsaved edits, the next visit starts from the saved document.
                    if (form.__editor) form.__editor.value = form.querySelector('[data-editor-source]').defaultValue;
                    setMode(pane, 'pdf');
                }
            });
            if (form) {
                form.addEventListener('submit', function () {
                    form.querySelector('[data-editor-source]').value = form.__editor.value;
                });
            }
        });

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.getAttribute('data-confirm'))) e.preventDefault();
            });
        });
    })();
</script>
@endsection
```

- [ ] **Step 6: Translations**

```bash
php -r '$f = "lang/ru.json"; $d = array_merge(json_decode(file_get_contents($f), true), json_decode($argv[1], true)); ksort($d, SORT_STRING); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");' '{"Scoring":"Скоринг","Application documents":"Документы для отклика","Regenerating will overwrite your manual edits. Continue?":"Перегенерация затрёт ручные правки. Продолжить?","Instructions":"Инструкции","Regenerate":"Перегенерировать","Regenerate CV":"Перегенерировать CV","Generate CV":"Сгенерировать CV","Extra instructions":"Дополнительные инструкции","PDF preview":"Просмотр PDF","Editor":"Редактор","Download PDF":"Скачать PDF","Save":"Сохранить","The PDF is rebuilt after saving":"После сохранения PDF соберётся заново","CV not created yet":"CV ещё не создан","Cover letter not created yet":"Cover letter ещё не создан","Claude will write a letter for this vacancy from your resume text, it takes a minute or two.":"Claude напишет письмо под эту вакансию по тексту вашего резюме, это займёт минуту или две.","Claude will tailor your resume to this vacancy in the design of your PDF, it takes a minute or two.":"Claude адаптирует резюме под эту вакансию в оформлении вашего PDF, это займёт минуту или две.","Could not load the editor. Check the internet connection.":"Не удалось загрузить редактор. Проверьте подключение к интернету."}'
```

Check the keys the old vacancy page used and remove those with no hits:

```bash
for k in "Documents" "Tailored resume" "Generate resume" "Regenerate resume" "Regenerate cover letter" "Download :ext"; do echo "== $k"; grep -rnF "'$k'" app resources; done
```

Remove only the keys that printed no matches, with the removal command from Global Constraints.

- [ ] **Step 7: README**

Replace Usage item 5 with:

```markdown
5. **Review results** on the Vacancies page. On a posting's page you generate the resume and cover letter, with extra instructions or in another language, research the company, or mark the posting as applied. The documents open as a PDF preview next to the posting, and the editor lets you fix them by hand before downloading. Regenerating a document replaces those edits, so the page asks first.
```

- [ ] **Step 8: Run tests and Pint**

Run: `vendor/bin/pint && php artisan test`
Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/VacancyController.php routes/web.php resources/views/vacancies/show.blade.php resources/views/partials/doc-pane.blade.php lang/ru.json README.md tests/Feature/VacancyDocumentsTest.php
git commit -F - <<'EOF'
feat: vacancy page with a documents panel, PDF preview and editor

"Open on the site" becomes the main button of the page. The HTML
preview route is gone, the panel shows the rendered PDF instead.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 8: Check the redesign in the running app

**Files:** none unless a check fails (then fix in the file the failing check points to and commit with `fix: ...`).

- [ ] **Step 1: Full suite and style**

Run: `vendor/bin/pint --test && php artisan test`
Expected: Pint passes, all tests pass (165 at the start plus the new ones).

- [ ] **Step 2: Open the app**

The Docker web container serves the app at `http://127.0.0.1:8000` (`docker compose ps` shows `jobchecker-web-1`). Use the `run` skill or a browser, log in, and check in both themes (toggle in the sidebar) and at a window narrower than 1100px:

- Dashboard: resume card shows the file row, keyword tags, the textarea. Add a keyword with Enter, remove one, edit the text: the "unsaved edits" badge appears. "Discard changes" restores tags and text and hides the badge. Type a keyword without pressing Enter and save: it is stored. "Restore text from PDF" fills the textarea without saving. "Open PDF" opens the original in a new tab.
- Settings: the sidebar has the third item and it is active. Submenu links jump to their cards and the active link follows scrolling. Changing a field shows "There are unsaved changes" in the sticky bar, "Cancel" resets fields, dropdown summaries and weight bars. Weight bars and percentages update while typing. "Send a test message to Telegram" submits the test form, not the settings form. Saving returns to `/settings` and the keywords are unchanged.
- Vacancy page: "Open on the site" is the largest button. Without documents both tabs show the empty state with a generate button. With a CV: the PDF preview fills the panel, "Editor" loads Jodit (network tab shows the jsDelivr request only on first open), the editor shows the CV with its own styles on a white page, "Save" reloads the page on the CV tab with "Document saved." and the rebuilt PDF. "Cancel" returns to the preview and the next "Editor" shows the saved version. Regenerating an existing document asks for confirmation. After clicking "Generate" on the cover letter tab the reloaded page opens on that tab (`#cover`).
- Palette: no amber left anywhere (scan pulse on a running search, sidebar status, badges).

- [ ] **Step 3: Commit fixes, if any**

```bash
git add <changed files>
git commit -F - <<'EOF'
fix: <what the check found>

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```
