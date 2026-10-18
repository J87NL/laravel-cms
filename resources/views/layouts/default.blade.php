<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
</head>
<body>
    <x-cms::menu key="main" />

    <main>
        @yield('content')
    </main>

    <x-cms::menu key="footer" />
</body>
</html>
