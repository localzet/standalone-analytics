<!--
  SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Personal Analytics</title>
    <link rel="stylesheet" href="{{ route('personal-analytics.admin.asset', ['asset' => 'admin.css']) }}">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">L</span><div><strong>Personal Analytics</strong><small>Localzet</small></div></div>
        <div class="sidebar-label">Аналитика</div>
        <nav class="nav">
            <button data-view="overview"><i>◈</i>Обзор</button>
            <button data-view="realtime"><i>●</i>Сейчас</button>
            <button data-view="events"><i>≡</i>События</button>
            <button data-view="sessions"><i>↝</i>Сессии</button>
            <button data-view="visitors"><i>◎</i>Посетители</button>
            <button data-view="acquisition"><i>↗</i>Привлечение</button>
            <button data-view="quality"><i>△</i>Качество</button>
            <button data-view="replays"><i>▶</i>Реплеи</button>
            <button data-view="heatmaps"><i>▦</i>Тепловые карты</button>
            <button data-view="conversions"><i>◇</i>Конверсии</button>
        </nav>
        <div class="sidebar-foot"><strong>Single project</strong><small>{{ config('personal-analytics.allowed_host') }}</small></div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div><h1 id="view-title">Аналитика</h1><div id="view-subtitle" class="subtitle"></div></div>
            <div class="range"><input id="date-from" type="date"><input id="date-to" type="date"><button id="apply-range">Применить</button></div>
        </header>
        <div id="loading" class="loading" hidden></div>
        <div id="content" class="content"></div>
    </main>
</div>
<dialog id="modal"><div class="modal-card"><button id="modal-close" class="modal-close" aria-label="Закрыть">×</button><div id="modal-body"></div></div></dialog>
@php
    $analyticsConfig = [
        'apiBase' => url(config('personal-analytics.admin_prefix').'/api'),
        'replayBase' => url(config('personal-analytics.admin_prefix').'/replays'),
        'heatmapBase' => url(config('personal-analytics.admin_prefix').'/heatmaps'),
        'authMode' => config('personal-analytics.admin_auth_mode'),
    ];
@endphp
<script id="analytics-config" type="application/json">@json($analyticsConfig)</script>
<script src="{{ route('personal-analytics.admin.asset', ['asset' => 'admin.js']) }}" defer></script>
</body>
</html>
