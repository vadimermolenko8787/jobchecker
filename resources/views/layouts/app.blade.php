<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'JobChecker')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0B0E14; --panel: #12161F; --raised: #171C26; --raised-2: #1D2430;
            --line: #232A36; --line-soft: #1B212C;
            --text: #E7ECF3; --muted: #93A0B0; --faint: #6B7686;
            --signal: #FFB84D; --signal-strong: #FFC66B; --signal-dim: rgba(255,184,77,.12); --signal-line: rgba(255,184,77,.38);
            --ok: #4ADE80; --ok-dim: rgba(74,222,128,.13);
            --info: #5AA2FF; --info-dim: rgba(90,162,255,.14);
            --danger: #FB6A63; --danger-dim: rgba(251,106,99,.14);
            --neutral: #7C899A; --neutral-dim: rgba(124,137,154,.14);
            --radius: 12px; --radius-sm: 8px;
            --sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
            --display: 'Space Grotesk', var(--sans);
            --mono: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace;
            --shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 8px 24px -12px rgba(0,0,0,.6);
            color-scheme: dark;
        }
        [data-theme="light"] {
            --ink: #F4F6F2; --panel: #FFFFFF; --raised: #FFFFFF; --raised-2: #F5F7F3;
            --line: #E3E7DF; --line-soft: #ECEFE9;
            --text: #131922; --muted: #5A6673; --faint: #8B95A1;
            --signal: #B4720B; --signal-strong: #935C05; --signal-dim: rgba(180,114,11,.10); --signal-line: rgba(180,114,11,.34);
            --ok: #16884B; --ok-dim: rgba(22,136,75,.12);
            --info: #2B6FD6; --info-dim: rgba(43,111,214,.11);
            --danger: #C63A34; --danger-dim: rgba(198,58,52,.10);
            --neutral: #64707E; --neutral-dim: rgba(100,112,126,.12);
            --shadow: 0 1px 2px rgba(16,24,40,.04), 0 12px 28px -18px rgba(16,24,40,.24);
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--ink); color: var(--text); font-family: var(--sans);
            font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased;
            font-feature-settings: 'cv11', 'ss01';
        }
        a { color: var(--signal); text-decoration: none; }
        a:hover { text-decoration: underline; text-underline-offset: 2px; }
        h1,h2,h3,h4 { font-family: var(--display); font-weight: 600; letter-spacing: -.01em; margin: 0; }
        code { font-family: var(--mono); font-size: .85em; background: var(--raised-2); padding: .1em .4em; border-radius: 5px; color: var(--text); }

        /* ---- shell ---- */
        .app { display: grid; grid-template-columns: 248px minmax(0,1fr); min-height: 100vh; }
        .sidebar {
            position: sticky; top: 0; align-self: start; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--line);
            display: flex; flex-direction: column; padding: 22px 16px; gap: 22px;
        }
        .brand { display: flex; align-items: center; gap: 10px; padding: 0 8px; }
        .brand-mark {
            width: 30px; height: 30px; border-radius: 9px; flex: none;
            background: var(--signal-dim); border: 1px solid var(--signal-line);
            display: grid; place-items: center; color: var(--signal);
        }
        .brand-name { font-family: var(--display); font-weight: 700; font-size: 17px; letter-spacing: -.02em; }
        .brand-name b { color: var(--signal); font-weight: 700; }
        .nav { display: flex; flex-direction: column; gap: 2px; }
        .nav a {
            display: flex; align-items: center; gap: 11px; padding: 9px 11px; border-radius: var(--radius-sm);
            color: var(--muted); font-weight: 500; font-size: 14.5px; text-decoration: none;
            transition: background .12s, color .12s;
        }
        .nav a svg { width: 18px; height: 18px; flex: none; }
        .nav a:hover { background: var(--raised); color: var(--text); text-decoration: none; }
        .nav a.is-active { background: var(--signal-dim); color: var(--signal); }
        .side-run { display: flex; flex-direction: column; gap: 8px; }
        .side-stats { margin-top: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .side-stat { background: var(--raised); border: 1px solid var(--line-soft); border-radius: 10px; padding: 9px 11px; }
        .side-stat .n { font-family: var(--mono); font-size: 18px; font-weight: 700; line-height: 1.1; letter-spacing: -.02em; }
        .side-stat .l { font-size: 11px; color: var(--faint); text-transform: uppercase; letter-spacing: .06em; margin-top: 3px; }
        .theme-toggle {
            background: none; border: 1px solid var(--line); color: var(--muted); border-radius: var(--radius-sm);
            padding: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font: inherit; font-size: 13px;
        }
        .theme-toggle:hover { color: var(--text); border-color: var(--faint); }
        .side-foot { display: flex; gap: 8px; }
        .side-foot > * { flex: 1; }
        .side-foot form { display: flex; }
        .side-foot form .theme-toggle { flex: 1; }
        .lang-switch { display: flex; border: 1px solid var(--line); border-radius: var(--radius-sm); overflow: hidden; }
        .lang-switch button {
            flex: 1; background: none; border: 0; color: var(--muted); cursor: pointer;
            font: inherit; font-family: var(--mono); font-size: 12px; padding: 8px 0;
        }
        .lang-switch button:hover { color: var(--text); }
        .lang-switch button.is-active { background: var(--signal-dim); color: var(--signal); cursor: default; }

        /* guests see only the login card, without the sidebar */
        .app.-guest { grid-template-columns: minmax(0,1fr); }
        .login { max-width: 380px; margin: 12vh auto 0; }
        .login .brand { justify-content: center; margin-bottom: 22px; }

        .main { min-width: 0; }
        .page { max-width: 1080px; margin: 0 auto; padding: 34px 40px 80px; }
        /* opt-in for table-first pages: 8 columns do not fit the reading-width default */
        .page.-wide { max-width: 1560px; padding-left: 28px; padding-right: 28px; }
        .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .page-head h1 { font-size: 26px; }
        .page-head .sub { color: var(--muted); font-size: 14px; margin-top: 4px; }
        .eyebrow { font-family: var(--mono); font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--faint); margin-bottom: 7px; }

        .topbar { display: none; }

        /* ---- cards ---- */
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); }
        .card + .card { margin-top: 20px; }
        /* Side by side the grid gap already spaces them, and the stacking margin would
           push the second card down and leave it that much shorter than the first. */
        .grid > .card + .card { margin-top: 0; }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid var(--line-soft); }
        .card-head h3 { font-size: 15px; letter-spacing: .01em; }
        .card-head .hint { color: var(--faint); font-size: 12.5px; font-family: var(--mono); }
        .card-body { padding: 20px; }
        .grid { display: grid; gap: 20px; }
        .grid-2 { grid-template-columns: 1fr 1fr; }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 900px) { .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr 1fr; } }

        /* ---- stat tiles ---- */
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px 18px; position: relative; overflow: hidden; }
        .stat .l { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
        .stat .n { font-family: var(--mono); font-size: 30px; font-weight: 700; letter-spacing: -.03em; margin-top: 6px; line-height: 1; }
        .stat .n small { font-size: 14px; color: var(--faint); font-weight: 500; }
        .stat.-accent .n { color: var(--signal); }
        .stat.-ok .n { color: var(--ok); }
        .stat .spark { position: absolute; right: 14px; top: 14px; color: var(--faint); opacity: .5; }

        /* ---- buttons ---- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            font-family: var(--sans); font-weight: 600; font-size: 14px; line-height: 1;
            padding: 10px 16px; border-radius: var(--radius-sm); border: 1px solid var(--line);
            background: var(--raised); color: var(--text); cursor: pointer; transition: all .13s; text-decoration: none;
        }
        .btn:hover { border-color: var(--faint); background: var(--raised-2); text-decoration: none; }
        .btn:active { transform: translateY(1px); }
        .btn:disabled { opacity: .45; cursor: not-allowed; }
        .btn-primary { background: var(--signal); border-color: var(--signal); color: #1a1205; }
        .btn-primary:hover { background: var(--signal-strong); border-color: var(--signal-strong); color: #1a1205; }
        [data-theme="light"] .btn-primary { color: #fff; }
        .btn-ok { background: var(--ok); border-color: var(--ok); color: #06210F; }
        .btn-ok:hover { background: var(--ok); border-color: var(--ok); color: #06210F; filter: brightness(1.08); }
        .btn-info { background: var(--info); border-color: var(--info); color: #06182E; }
        .btn-info:hover { background: var(--info); border-color: var(--info); color: #06182E; filter: brightness(1.08); }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #2A0708; }
        .btn-danger:hover { background: var(--danger); border-color: var(--danger); color: #2A0708; filter: brightness(1.08); }
        [data-theme="light"] .btn-ok, [data-theme="light"] .btn-info, [data-theme="light"] .btn-danger { color: #fff; }
        .btn-block { width: 100%; }
        .btn-sm { padding: 6px 11px; font-size: 12.5px; font-weight: 500; border-radius: 7px; }
        .btn-ghost { background: transparent; }
        .btn:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible {
            outline: 2px solid var(--signal); outline-offset: 2px;
        }

        /* ---- forms ---- */
        .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 4px; }
        .field > .lab { font-size: 13px; font-weight: 600; color: var(--text); }
        .field .help { font-size: 12px; color: var(--faint); line-height: 1.5; }
        input[type=text], input[type=email], input[type=number], input[type=password], input[type=date], select, textarea {
            width: 100%; font: inherit; font-size: 14px; color: var(--text);
            background: var(--raised); border: 1px solid var(--line); border-radius: var(--radius-sm);
            padding: 9px 11px; transition: border-color .12s, box-shadow .12s;
        }
        input:hover, select:hover, textarea:hover { border-color: var(--faint); }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--signal); box-shadow: 0 0 0 3px var(--signal-dim); }
        input::placeholder { color: var(--faint); }
        input[type=file] {
            font-size: 13px; color: var(--muted); width: 100%;
        }
        input[type=file]::file-selector-button {
            font: inherit; font-weight: 600; font-size: 13px; margin-right: 12px;
            background: var(--raised-2); color: var(--text); border: 1px solid var(--line);
            padding: 8px 14px; border-radius: 7px; cursor: pointer;
        }
        input[type=file]::file-selector-button:hover { border-color: var(--faint); }
        .fieldset { border: 1px solid var(--line-soft); border-radius: var(--radius-sm); padding: 15px 16px; margin: 0; }
        .fieldset legend { font-size: 12px; font-family: var(--mono); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); padding: 0 6px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-grid.-tri { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 760px) { .form-grid, .form-grid.-tri { grid-template-columns: 1fr; } }
        .section-label { font-family: var(--mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--faint); margin: 26px 0 12px; display: flex; align-items: center; gap: 10px; }
        .section-label::after { content: ""; flex: 1; height: 1px; background: var(--line-soft); }
        .section-label:first-child { margin-top: 0; }

        /* checkbox rows / toggle chips */
        .check { display: flex; align-items: center; gap: 9px; font-size: 14px; cursor: pointer; user-select: none; }
        .check input { width: 17px; height: 17px; accent-color: var(--signal); flex: none; }
        .chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            display: inline-flex; align-items: center; gap: 8px; padding: 8px 13px; border-radius: 999px;
            border: 1px solid var(--line); background: var(--raised); font-size: 13.5px; font-weight: 500; cursor: pointer; transition: all .12s;
        }
        .chip input { position: absolute; opacity: 0; width: 0; height: 0; }
        .chip .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); transition: all .12s; }
        .chip:hover { border-color: var(--faint); }
        .chip:has(input:checked) { border-color: var(--signal-line); background: var(--signal-dim); color: var(--signal); }
        .chip:has(input:checked) .dot { background: var(--signal); box-shadow: 0 0 0 3px var(--signal-dim); }

        /* multiselect dropdown — without JS it stays a plain chip list */
        .multiselect { position: relative; }
        .multiselect .ms-toggle {
            display: none; width: 100%; font: inherit; font-size: 14px; text-align: left; cursor: pointer;
            color: var(--text); background: var(--raised); border: 1px solid var(--line); border-radius: var(--radius-sm);
            padding: 9px 11px; align-items: center; gap: 10px; transition: border-color .12s, box-shadow .12s;
        }
        .multiselect.-js .ms-toggle { display: flex; }
        .multiselect .ms-toggle:hover { border-color: var(--faint); }
        .multiselect .ms-toggle:focus-visible { outline: none; border-color: var(--signal); box-shadow: 0 0 0 3px var(--signal-dim); }
        .multiselect .ms-summary { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .multiselect .ms-summary.-empty { color: var(--faint); }
        .multiselect .ms-caret { color: var(--faint); font-size: 11px; flex: none; transition: transform .12s; }
        .multiselect.-open .ms-caret { transform: rotate(180deg); }
        .multiselect.-js .ms-panel {
            display: none; position: absolute; z-index: 30; top: calc(100% + 6px); left: 0; right: 0;
            max-height: 280px; overflow-y: auto; padding: 12px;
            background: var(--raised); border: 1px solid var(--line); border-radius: var(--radius-sm);
            box-shadow: 0 12px 32px rgba(0, 0, 0, .28);
        }
        .multiselect.-js.-open .ms-panel { display: flex; }
        /* same dropdown inside a table filter cell: compact, and freed from the uppercase
           th inherits. -fixed because .table-wrap scrolls on x, which per spec turns its
           overflow-y into auto too, so an absolute panel would be clipped by the table. */
        .multiselect.-sm .ms-toggle { min-width: 104px; padding: 6px 9px; font-size: 13px; text-transform: none; letter-spacing: normal; }
        .multiselect.-sm .ms-panel { width: 210px; gap: 6px; padding: 10px; }
        .multiselect.-sm .ms-panel .chip { padding: 5px 10px; gap: 6px; font-size: 12.5px; text-transform: none; letter-spacing: normal; }
        .multiselect.-fixed.-js .ms-panel { position: fixed; right: auto; }

        /* ---- segmented filter ---- */
        .segment { display: inline-flex; background: var(--panel); border: 1px solid var(--line); border-radius: 999px; padding: 4px; gap: 2px; }
        .segment a { padding: 6px 15px; border-radius: 999px; font-size: 13px; font-weight: 600; color: var(--muted); text-decoration: none; transition: all .12s; }
        .segment a:hover { color: var(--text); text-decoration: none; }
        .segment a.is-active { background: var(--signal-dim); color: var(--signal); }

        /* ---- badges ---- */
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; font-family: var(--mono); letter-spacing: .02em; white-space: nowrap; border: 1px solid transparent; }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge.-matched, .badge.-ok, .badge.-done { color: var(--ok); background: var(--ok-dim); }
        .badge.-new { color: var(--info); background: var(--info-dim); }
        .badge.-running, .badge.-bumped { color: var(--signal); background: var(--signal-dim); }
        .badge.-running::before { animation: pulse 1.1s ease-in-out infinite; }
        .badge.-rejected, .badge.-neutral, .badge.-skipped, .badge.-cancelled { color: var(--neutral); background: var(--neutral-dim); }
        .badge.-failed, .badge.-error { color: var(--danger); background: var(--danger-dim); }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
        .badge.-solid { color: #1a1205; background: var(--ok); border-color: var(--ok); }
        .badge.-solid::before { background: #1a1205; }

        .tag { font-family: var(--mono); font-size: 12px; color: var(--muted); background: var(--raised-2); border: 1px solid var(--line-soft); padding: 2px 8px; border-radius: 6px; }

        /* ---- match meter (signature) ---- */
        .meter { display: inline-flex; align-items: center; gap: 10px; }
        .meter .val { font-family: var(--mono); font-weight: 700; font-size: 15px; letter-spacing: -.02em; min-width: 24px; text-align: right; }
        .meter .track { display: block; width: 54px; height: 6px; border-radius: 999px; background: var(--raised-2); overflow: hidden; position: relative; }
        .meter .fill { display: block; height: 100%; border-radius: 999px; min-width: 3px; }
        .meter.-lg .track { width: 160px; height: 8px; }
        .meter.-lg .val { font-size: 22px; min-width: 34px; }
        .meter.-none .val { color: var(--faint); }

        /* ---- per-source breakdown ---- */
        .src-row { display: grid; grid-template-columns: 88px 1fr 46px; gap: 12px; align-items: center; }
        .src-row + .src-row { margin-top: 12px; }
        .src-row .track { display: block; height: 6px; border-radius: 999px; background: var(--raised-2); overflow: hidden; }
        .src-row .fill { display: block; height: 100%; border-radius: 999px; min-width: 3px; }
        .src-row .sv { font-family: var(--mono); font-size: 13px; font-weight: 700; text-align: right; }

        /* ---- score rubric breakdown ---- */
        .rubric { display: grid; gap: 12px; margin-top: 18px; }
        .rubric-row { display: grid; grid-template-columns: 150px 44px 1fr; gap: 12px; align-items: center; }
        .rubric-row .rl { font-size: 13.5px; font-weight: 600; }
        .rubric-row .rl small { display: block; font-family: var(--mono); font-size: 11px; font-weight: 400; color: var(--faint); letter-spacing: .04em; }
        .rubric-row .rv { font-family: var(--mono); font-size: 13px; font-weight: 700; text-align: right; }
        .rubric-row .track { display: block; height: 6px; border-radius: 999px; background: var(--raised-2); overflow: hidden; }
        .rubric-row .fill { display: block; height: 100%; border-radius: 999px; min-width: 3px; }
        .rubric-ev { display: flex; flex-wrap: wrap; gap: 6px; grid-column: 2 / -1; margin-top: -4px; }
        .rubric-ev span {
            font-size: 12px; line-height: 1.4; padding: 3px 8px; border-radius: 6px;
            border: 1px solid var(--line-soft); background: var(--raised-2); color: var(--muted);
        }
        .rubric-ev span.-yes { border-color: var(--ok); background: var(--ok-dim); color: var(--ok); }
        @media (max-width: 640px) {
            .rubric-row { grid-template-columns: 1fr 44px; }
            .rubric-row .track { grid-column: 1 / -1; }
            .rubric-ev { grid-column: 1 / -1; }
        }

        /* ---- tables ---- */
        .table-wrap { overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--line); }
        table.data { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.data thead th {
            position: sticky; top: 0; background: var(--panel); z-index: 1;
            text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em;
            color: var(--faint); padding: 12px 16px; border-bottom: 1px solid var(--line); white-space: nowrap;
        }
        table.data thead th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        table.data thead th a:hover { color: var(--text); text-decoration: none; }
        table.data tbody td { padding: 13px 16px; border-bottom: 1px solid var(--line-soft); vertical-align: middle; }
        table.data tbody tr:last-child td { border-bottom: none; }
        table.data tbody tr { transition: background .1s; }
        table.data tbody tr:hover { background: var(--raised); }
        table.data tbody tr.-new { animation: rowin .5s ease; }
        table.data tbody tr.-new td:first-child { box-shadow: inset 3px 0 0 var(--signal); }
        @keyframes rowin { from { background: var(--signal-dim); } to { background: transparent; } }
        /* inline filter row inside thead. The offset keeps it below the label row rather than on top
           of it; note the sticky above is currently inert, since .table-wrap is its own scrollport. */
        table.data.-filterable thead tr:first-child th { height: 40px; }
        table.data.-filterable thead tr.-filters th { top: 40px; padding: 8px 12px; border-bottom: 1px solid var(--line); }
        /* input.f-in, not .f-in: it has to outrank the input[type=…] rule above */
        /* size=1 on the text inputs keeps their intrinsic width from widening the columns */
        input.f-in {
            padding: 6px 9px; font-size: 13px; font-weight: 400; min-width: 0;
            text-transform: none; letter-spacing: normal; color: var(--text);
        }
        input.f-in::placeholder { text-transform: none; letter-spacing: normal; font-weight: 400; }
        .f-dates { display: flex; flex-direction: column; gap: 5px; }
        .f-dates input.f-in { font-size: 12px; padding: 5px 4px 5px 7px; font-family: var(--mono); }

        .v-title { font-weight: 600; color: var(--text); }
        .v-title:hover { color: var(--signal); text-decoration: none; }
        .muted { color: var(--muted); }
        .faint { color: var(--faint); }
        .mono { font-family: var(--mono); }
        .nowrap { white-space: nowrap; }

        /* ---- logs / pre ---- */
        pre.log { font-family: var(--mono); font-size: 12.5px; line-height: 1.6; background: var(--ink); border: 1px solid var(--line-soft); border-radius: var(--radius-sm); padding: 14px 16px; overflow: auto; max-height: 320px; margin: 0; color: var(--muted); }
        [data-theme="light"] pre.log { background: var(--raised-2); }

        /* ---- details ---- */
        details.box { border: 1px solid var(--line-soft); border-radius: var(--radius-sm); margin-top: 10px; }
        details.box > summary { cursor: pointer; padding: 11px 15px; font-weight: 500; font-size: 13.5px; list-style: none; display: flex; align-items: center; gap: 8px; color: var(--muted); }
        details.box > summary:hover { color: var(--text); }
        details.box > summary::-webkit-details-marker { display: none; }
        details.box > summary::before { content: "›"; font-family: var(--mono); transition: transform .15s; display: inline-block; color: var(--faint); }
        details.box[open] > summary::before { transform: rotate(90deg); }
        details.box > .details-body { padding: 0 15px 15px; }

        .markdown-box { background: var(--raised); border: 1px solid var(--line-soft); border-radius: var(--radius-sm); padding: 16px 20px; overflow-x: auto; line-height: 1.65; }
        .markdown-box h1, .markdown-box h2, .markdown-box h3 { margin: 1em 0 .4em; }
        .markdown-box ul, .markdown-box ol { padding-left: 1.3em; }
        .markdown-box a { color: var(--signal); }

        /* ---- flash ---- */
        .flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: 14px; margin-bottom: 20px; border: 1px solid; }
        .flash.-ok { background: var(--ok-dim); border-color: var(--ok); color: var(--ok); }
        .flash.-err { background: var(--danger-dim); border-color: var(--danger); color: var(--danger); }

        .empty { text-align: center; padding: 40px 20px; color: var(--muted); }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .stack { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        /* scan pulse on live run */
        .scanning { position: relative; overflow: hidden; }
        .scanning::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,184,77,.35), transparent);
            transform: translateX(-100%); animation: scan 1.6s ease-in-out infinite;
        }
        @keyframes scan { to { transform: translateX(100%); } }

        .pagination { display: flex; gap: 4px; margin-top: 20px; list-style: none; padding: 0; flex-wrap: wrap; align-items: center; justify-content: center; }
        .pagination a, .pagination span { display: inline-flex; min-width: 34px; height: 34px; align-items: center; justify-content: center; padding: 0 10px; border-radius: 8px; font-size: 13px; color: var(--muted); border: 1px solid transparent; }
        .pagination a:hover { background: var(--raised); text-decoration: none; color: var(--text); }
        .pagination [aria-current] span { background: var(--signal-dim); color: var(--signal); }

        @media (prefers-reduced-motion: reduce) { *, *::after { animation: none !important; transition: none !important; } }

        /* ---- responsive shell ---- */
        @media (max-width: 820px) {
            .app { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; flex-direction: row; align-items: center; flex-wrap: wrap; padding: 12px 16px; gap: 12px; border-right: none; border-bottom: 1px solid var(--line); }
            .nav { flex-direction: row; }
            .side-run { flex-direction: row; margin-left: auto; }
            .side-stats { display: none; }
            .brand { padding: 0; }
            .page { padding: 22px 18px 60px; }
            .page-head { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<div @class(['app', '-guest' => auth()->guest()])>
    @auth
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <span class="brand-name">Job<b>Checker</b></span>
        </div>

        <nav class="nav">
            <a href="{{ route('dashboard') }}" @class(['is-active' => request()->routeIs('dashboard')])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
                {{ __('Dashboard') }}
            </a>
            <a href="{{ route('vacancies.index') }}" @class(['is-active' => request()->routeIs('vacancies.*')])>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M3 12h18M3 17h12"/></svg>
                {{ __('Vacancies') }}
            </a>
        </nav>

        <div class="side-run">
            <form method="post" action="{{ route('run.start') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-block" id="side-run-btn" @unless($sidebarResume) disabled data-noresume @endunless>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                    {{ __('Start search') }}
                </button>
            </form>
            <form method="post" action="{{ route('run.stop') }}" data-run-stop style="display:none;margin-top:8px">
                @csrf
                <button type="submit" class="btn btn-danger btn-block">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                    {{ __('Stop') }}
                </button>
            </form>
            <div id="side-run-status" class="mono" style="font-size:12px;color:var(--faint);text-align:center;min-height:16px">
                @if($sidebarLatestRun)
                    {{ __('last run #:id · :status', ['id' => $sidebarLatestRun->id, 'status' => $sidebarLatestRun->status]) }}
                @elseif(!$sidebarResume)
                    {{ __('upload a resume first') }}
                @endif
            </div>
        </div>

        <div class="side-stats" id="side-stats">
            @php $sc = $globalCounts ?? null; @endphp
            <div class="side-stat"><div class="n">{{ $sc['total'] ?? '—' }}</div><div class="l">{{ __('in database') }}</div></div>
            <div class="side-stat"><div class="n" style="color:var(--signal)">{{ $sc['matched'] ?? '—' }}</div><div class="l">matched</div></div>
            <div class="side-stat"><div class="n" style="color:var(--ok)">{{ $sc['done'] ?? '—' }}</div><div class="l">docs</div></div>
            <div class="side-stat"><div class="n">{{ $sc['applied'] ?? '—' }}</div><div class="l">{{ __('applied') }}</div></div>
        </div>

        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="{{ __('Toggle theme') }}">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            <span>{{ __('Theme') }}</span>
        </button>

        <div class="side-foot">
            <form method="post" action="{{ route('locale.update') }}" class="lang-switch" aria-label="{{ __('Language') }}">
                @csrf
                @foreach (\App\Models\Setting::LOCALES as $code)
                    <button type="submit" name="locale" value="{{ $code }}" @class(['is-active' => app()->getLocale() === $code]) @disabled(app()->getLocale() === $code)>{{ strtoupper($code) }}</button>
                @endforeach
            </form>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="theme-toggle">{{ __('Log out') }}</button>
            </form>
        </div>
    </aside>
    @endauth

    <main class="main">
        <div class="page @yield('page-class')">
            @if (session('status'))
                <div class="flash -ok">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="flash -err">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash -err">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script>
    (function () {
        var key = 'jc-theme';
        var root = document.documentElement;
        var saved = localStorage.getItem(key);
        if (saved) root.setAttribute('data-theme', saved);
        else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) root.setAttribute('data-theme', 'light');
        var btn = document.getElementById('theme-toggle');
        if (btn) btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            root.setAttribute('data-theme', next); localStorage.setItem(key, next);
        });
    })();

    @auth
    (function () {
        var statusEl = document.getElementById('side-run-status');
        var btn = document.getElementById('side-run-btn');
        var stopForms = document.querySelectorAll('[data-run-stop]');
        var url = @json(route('runs.latest'));
        var wasRunning = false;
        window.__jcOnRun = window.__jcOnRun || function () {};
        function poll() {
            // Asking for JSON makes an expired session answer 401 instead of redirecting,
            // which would also save this endpoint as the page to return to after login.
            fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) {
                if (r.status === 401) { location.reload(); throw new Error('unauthenticated'); }
                return r.json();
            }).then(function (run) {
                if (!run) return;
                var running = run.status === 'running';
                if (statusEl) {
                    statusEl.textContent = running
                        ? @json(__('run #:id in progress…')).replace(':id', run.id)
                        : @json(__('last run #:id · :status')).replace(':id', run.id).replace(':status', run.status);
                    statusEl.style.color = running ? 'var(--signal)' : 'var(--faint)';
                }
                if (btn && !btn.hasAttribute('data-noresume')) btn.disabled = running;
                stopForms.forEach(function (form) { form.style.display = running ? '' : 'none'; });
                window.__jcOnRun(run, running, wasRunning);
                if (wasRunning && !running) location.reload();
                wasRunning = running;
            }).catch(function () {});
        }
        poll(); setInterval(poll, 3000);
    })();
    @endauth

    // Turns a chip list into a dropdown; without this it degrades to a plain chip list.
    (function () {
        document.querySelectorAll('[data-multiselect]').forEach(function (root) {
            var toggle = root.querySelector('.ms-toggle');
            var panel = root.querySelector('.ms-panel');
            var summary = root.querySelector('.ms-summary');
            var boxes = Array.prototype.slice.call(root.querySelectorAll('input[type=checkbox]'));

            function render() {
                var picked = boxes.filter(function (b) { return b.checked; })
                    .map(function (b) { return b.parentNode.textContent.trim(); });
                summary.classList.toggle('-empty', picked.length === 0);
                if (!picked.length) {
                    summary.textContent = root.getAttribute('data-empty') || @json(__('Nothing selected'));
                } else if (picked.length > 3) {
                    summary.textContent = picked.slice(0, 3).join(', ') + ' +' + (picked.length - 3);
                } else {
                    summary.textContent = picked.join(', ');
                }
                // The summary ellipsizes in a narrow column, so keep the full list on hover.
                toggle.title = picked.join(', ');
            }

            // A fixed panel is positioned by hand; it is opened first, so it has a width by now.
            function place() {
                if (!root.classList.contains('-fixed')) return;
                var rect = toggle.getBoundingClientRect();
                panel.style.top = (rect.bottom + 6) + 'px';
                panel.style.left = Math.max(8, Math.min(rect.left, document.documentElement.clientWidth - panel.offsetWidth - 8)) + 'px';
            }

            function close() {
                root.classList.remove('-open');
                toggle.setAttribute('aria-expanded', 'false');
            }

            toggle.addEventListener('click', function () {
                var open = root.classList.toggle('-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) place();
            });
            root.addEventListener('change', render);
            document.addEventListener('click', function (e) {
                if (!root.contains(e.target)) close();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') close();
            });
            // A fixed panel does not follow its toggle on its own. Capture phase, because
            // scrolling inside .table-wrap does not bubble; scrolling the list itself must
            // not move it.
            window.addEventListener('scroll', function (e) {
                if (root.classList.contains('-open') && !panel.contains(e.target)) place();
            }, true);
            window.addEventListener('resize', function () {
                if (root.classList.contains('-open')) place();
            });

            root.classList.add('-js');
            render();
        });
    })();
</script>
@yield('scripts')
</body>
</html>
