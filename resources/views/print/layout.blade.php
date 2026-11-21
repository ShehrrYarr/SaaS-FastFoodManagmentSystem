{{--
    80mm thermal paper layout. Printed from a hidden iframe by the POS, or
    opened in a tab for a reprint (shows a Print button on screen).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Print' }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }

        * { box-sizing: border-box; }

        html { background: #e9ecef; }

        body {
            width: 80mm;
            margin: 1rem auto;
            padding: 4mm 4mm 6mm;
            background: #fff;
            color: #000;
            font: 12px/1.35 ui-monospace, Consolas, "Courier New", monospace;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        @media print {
            html { background: #fff; }
            body { margin: 0; box-shadow: none; width: 80mm; }
            .no-print { display: none !important; }
        }

        h1, h2, p { margin: 0; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .big { font-size: 16px; font-weight: 700; }
        .huge { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .small { font-size: 10.5px; }
        .muted { color: #333; }
        .upper { text-transform: uppercase; }
        .rule { border: 0; border-top: 1px dashed #000; margin: 2.5mm 0; }
        .rule-solid { border: 0; border-top: 2px solid #000; margin: 2.5mm 0; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > :first-child { min-width: 0; overflow-wrap: anywhere; }
        .row > :last-child { flex-shrink: 0; text-align: right; }
        .indent { padding-left: 4mm; }
        .box { border: 2px solid #000; padding: 1.5mm 2mm; margin: 2mm 0; }
        .strike { text-decoration: line-through; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0.4mm 0; }

        .toolbar {
            position: fixed; top: 1rem; right: 1rem;
            display: flex; gap: .5rem;
            font: 14px system-ui, sans-serif;
        }
        .toolbar button, .toolbar a {
            border: 0; border-radius: .5rem; padding: .55rem 1rem; cursor: pointer;
            background: #0d6efd; color: #fff; text-decoration: none; font: inherit;
        }
        .toolbar a { background: #6c757d; }
        @media (max-width: 600px) {
            body { margin: 4rem auto 1rem; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="javascript:window.close()">Close</a>
    </div>

    @yield('content')
</body>
</html>
