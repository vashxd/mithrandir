<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <title inertia>{{ config('mithrandir.nome') }}</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="description" content="Gestao de prazos, agenda e casos para advogados autonomos.">
    {{-- Acompanha --color-fundo nos dois temas. Um valor fixo escuro num app
         claro deixa a barra de status descolada do topo da tela. --}}
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f6f8fa">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#101318">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Mithrandir">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-180.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans antialiased">
    @inertia
</body>
</html>
