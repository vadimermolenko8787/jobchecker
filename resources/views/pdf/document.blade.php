<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 2cm 2.2cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #1a1a1a; line-height: 1.45; }
        h1 { font-size: 17pt; margin: 0 0 4pt; color: #111; }
        h2 { font-size: 12.5pt; margin: 14pt 0 5pt; padding-bottom: 3pt; border-bottom: 1px solid #999; color: #111; }
        h3 { font-size: 11pt; margin: 10pt 0 3pt; color: #111; }
        p { margin: 5pt 0; }
        ul, ol { margin: 4pt 0 6pt; padding-left: 16pt; }
        li { margin: 2pt 0; }
        a { color: #1a56db; text-decoration: none; }
        strong { color: #111; }
        hr { border: none; border-top: 1px solid #ccc; margin: 10pt 0; }
        blockquote { margin: 6pt 0; padding-left: 10pt; border-left: 2px solid #ccc; color: #444; }
        code { font-family: DejaVu Sans Mono, monospace; font-size: 9pt; }
    </style>
</head>
<body>{!! $html !!}</body>
</html>
