<!--
  SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Аналитика</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,system-ui,sans-serif;background:#080d19;color:#eaf0ff}*{box-sizing:border-box}body{margin:0;padding:28px}.shell{max-width:1500px;margin:auto}.head{display:flex;justify-content:space-between;gap:20px;align-items:end}.muted{color:#91a0bd}.filters{display:flex;gap:8px}input,button{background:#151d30;color:#fff;border:1px solid #2c3854;border-radius:9px;padding:10px 12px}button{background:#6257f5;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}.card,.panel{background:#11192a;border:1px solid #26314b;border-radius:14px;padding:18px}.value{font-size:28px;font-weight:750;margin-top:8px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.panel{min-height:240px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #26314b}th{color:#91a0bd}.bar{height:8px;border-radius:6px;background:#6257f5}.wide{grid-column:1/-1}@media(max-width:900px){.cards,.grid{grid-template-columns:1fr}.head{align-items:start;flex-direction:column}}
    </style>
</head>
<body><main class="shell">
    <div class="head"><div><h1>Аналитика продукта</h1><div class="muted">Поведение, маркетинг, качество и выручка в одном контуре</div></div><div class="filters"><input id="from" type="date"><input id="to" type="date"><button id="reload">Обновить</button></div></div>
    <div id="status" class="muted"></div><section id="cards" class="cards"></section>
    <section class="grid">
        <article class="panel wide"><h2>Динамика</h2><div id="timeline"></div></article>
        <article class="panel"><h2>События</h2><div id="events"></div></article>
        <article class="panel"><h2>Страницы</h2><div id="pages"></div></article>
        <article class="panel"><h2>Источники</h2><div id="sources"></div></article>
        <article class="panel"><h2>Каналы</h2><div id="mediums"></div></article>
        <article class="panel"><h2>Кампании</h2><div id="campaigns"></div></article>
        <article class="panel"><h2>UTM term</h2><div id="terms"></div></article>
        <article class="panel"><h2>UTM content</h2><div id="contents"></div></article>
        <article class="panel"><h2>Ecommerce</h2><div id="ecommerce"></div></article>
        <article class="panel"><h2>Ошибки</h2><div id="errors"></div></article>
        <article class="panel"><h2>Производительность</h2><div id="performance"></div></article>
        <article class="panel wide"><h2>Воронки</h2><div id="funnels"></div></article>
        <article class="panel wide"><h2>Цели</h2><div id="goals"></div></article>
        <article class="panel wide"><h2>Реплеи сессий</h2><div id="replays"></div></article>
        <article class="panel wide"><h2>Тепловые карты</h2><div id="heatmaps"></div></article>
    </section>
</main><script>
const endpoint=@json(route('personal-analytics.admin.report',['report'=>'dashboard']));
const esc=v=>String(v??'—').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const table=(rows,columns)=>`<table><thead><tr>${columns.map(c=>`<th>${c[1]}</th>`).join('')}</tr></thead><tbody>${(rows||[]).map(r=>`<tr>${columns.map(c=>`<td>${esc(r[c[0]])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
const today=new Date(),past=new Date(Date.now()-29*864e5);to.value=today.toISOString().slice(0,10);from.value=past.toISOString().slice(0,10);
async function load(){status.textContent='Загрузка…';try{const q=new URLSearchParams({from:from.value,to:to.value});const response=await fetch(`${endpoint}?${q}`,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error(`HTTP ${response.status}`);const d=await response.json();status.textContent='';const labels={visitors:'Посетители',sessions:'Сессии',pageviews:'Просмотры',events:'События',conversions:'Конверсии',revenue:'Выручка',engagementMs:'Вовлечённость, мс',bounceRate:'Отказы, %'};cards.innerHTML=Object.entries(d.overview).map(([k,v])=>`<article class="card"><span class="muted">${labels[k]||k}</span><div class="value">${esc(v)}</div></article>`).join('');timeline.innerHTML=table(d.timeline,[['day','Дата'],['visitors','Посетители'],['sessions','Сессии'],['events','События']]);for(const key of ['events','pages','sources','mediums','campaigns','terms','contents'])document.getElementById(key).innerHTML=table(d[key],[['value','Значение'],['visitors','Посетители'],['events','События'],['conversions','Конверсии']]);ecommerce.innerHTML=table(d.ecommerce,[['name','Действие'],['events','События'],['visitors','Посетители'],['revenue','Выручка']]);errors.innerHTML=table(d.errors,[['name','Ошибка'],['events','Количество'],['visitors','Посетители']]);performance.innerHTML=table(d.performance,[['name','Метрика'],['events','Измерения'],['average','Среднее']]);funnels.innerHTML=(d.funnels||[]).map(f=>`<h3>${esc(f.name)}</h3>${table(f.steps,[['step','#'],['name','Шаг'],['visitors','Посетители']])}`).join('')||'<span class="muted">Воронки ещё не настроены</span>';goals.innerHTML=table(d.goals,[['key','Ключ'],['name','Цель'],['visitors','Посетители'],['conversions','Конверсии'],['value','Ценность']]);replays.innerHTML=table(d.replays,[['id','Replay ID'],['entry_path','Вход'],['exit_path','Выход'],['events_count','События'],['bytes','Байт'],['started_at','Начало']]);heatmaps.innerHTML=table(d.heatmaps,[['key','Ключ'],['path','Страница'],['device_type','Устройство'],['viewport_width','Ширина'],['events','Точки']]);}catch(e){status.textContent=e.message}}
reload.addEventListener('click',load);load();
</script></body></html>
