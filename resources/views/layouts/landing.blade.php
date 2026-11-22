{{--
    Public marketing pages (the landing page). Light and fast: Bootstrap, the
    icon font and landing.min.css only; none of the app shell or its scripts.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SFMS · POS & back office for fast-food shops' }}</title>
    <meta name="description" content="SFMS is a point of sale and back office for fast-food shops: dine-in, takeaway and delivery orders, kitchen tickets, stock by recipe, expenses, reports and a day close that balances to the rupee.">
    <meta property="og:title" content="SFMS · POS & back office for fast-food shops">
    <meta property="og:description" content="Take every order, close every day, know every rupee. Try the live demo in your browser.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ asset('assets/images/landing/og.jpg') }}">
    <meta name="theme-color" content="#0b1220">
    <link rel="shortcut icon" href="{{ asset('assets/images/Favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/landing.min.css') }}">
</head>
<body class="lp">
    {{ $slot }}

    <script>
        // Fade sections in as they scroll into view (skipped when motion is reduced).
        (function () {
            var items = document.querySelectorAll('[data-reveal]');
            if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                items.forEach(function (el) { el.classList.add('is-visible'); });
                return;
            }
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); }
                });
            }, { rootMargin: '0px 0px -10% 0px' });
            items.forEach(function (el) { io.observe(el); });
        })();
    </script>
</body>
</html>
