// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import Chart from 'chart.js/auto';
import rrwebPlayer from 'rrweb-player';
import 'rrweb-player/dist/style.css';
import './admin.css';

const config = JSON.parse(document.querySelector('#analytics-config').textContent);
const state = { view: 'overview', from: dateOffset(-29), to: dateOffset(0), token: sessionStorage.getItem('pa_admin_token') ?? '', charts: [] };
const content = document.querySelector('#content');
const title = document.querySelector('#view-title');
const subtitle = document.querySelector('#view-subtitle');
const loading = document.querySelector('#loading');
const modal = document.querySelector('#modal');
const modalBody = document.querySelector('#modal-body');
const views = {
  overview: ['Обзор', 'Главные показатели, динамика и состояние сбора'],
  realtime: ['Сейчас', 'Активность за последние пять минут'],
  events: ['События', 'Полный журнал всего, что было записано'],
  sessions: ['Сессии', 'Пути, вовлечённость и контекст визитов'],
  visitors: ['Посетители', 'Идентичности, технология, география и атрибуция'],
  acquisition: ['Привлечение', 'UTM, источники, каналы и кампании'],
  quality: ['Качество', 'Ошибки JavaScript, сеть и производительность'],
  replays: ['Реплеи', 'Воспроизведение реальных пользовательских сессий'],
  heatmaps: ['Тепловые карты', 'Клики, движения и глубина прокрутки'],
  conversions: ['Конверсии', 'Цели, воронки и ecommerce'],
};

function dateOffset(days) {
  const date = new Date(Date.now() + days * 864e5);
  return date.toISOString().slice(0, 10);
}

function escapeHtml(value) {
  return String(value ?? '—').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}

function formatNumber(value) {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value ?? 0));
}

function formatDate(value) {
  return value ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value)) : '—';
}

function bytes(value) {
  const number = Number(value ?? 0);
  if (number < 1024) return `${number} Б`;
  if (number < 1048576) return `${(number / 1024).toFixed(1)} КБ`;
  return `${(number / 1048576).toFixed(1)} МБ`;
}

async function api(report, params = {}) {
  const query = new URLSearchParams({ from: state.from, to: state.to, ...params });
  const headers = { Accept: 'application/json' };
  if (state.token) headers.Authorization = `Bearer ${state.token}`;
  const response = await fetch(`${config.apiBase}/${report}?${query}`, { headers });
  if (response.status === 401) {
    showTokenDialog();
    throw new Error('Требуется токен администратора');
  }
  if (!response.ok) throw new Error(`API вернул HTTP ${response.status}`);
  return response.json();
}

async function secureFetch(url) {
  const headers = { Accept: 'application/json' };
  if (state.token) headers.Authorization = `Bearer ${state.token}`;
  const response = await fetch(url, { headers });
  if (response.status === 401) showTokenDialog();
  if (!response.ok) throw new Error(`API вернул HTTP ${response.status}`);
  return response.json();
}

function metric(label, value, hint = '') {
  return `<article class="metric"><div class="metric-label">${escapeHtml(label)}</div><strong>${escapeHtml(value)}</strong><small>${escapeHtml(hint)}</small></article>`;
}

function empty(message) {
  return `<div class="empty"><span>∅</span><strong>${escapeHtml(message)}</strong></div>`;
}

function table(rows, columns, onClick = false) {
  if (!rows?.length) return empty('Данных за выбранный период нет');
  const head = columns.map((column) => `<th>${escapeHtml(column.label)}</th>`).join('');
  const body = rows.map((row, index) => `<tr${onClick ? ` data-row="${index}" class="clickable"` : ''}>${columns.map((column) => `<td>${column.render ? column.render(row[column.key], row) : escapeHtml(row[column.key])}</td>`).join('')}</tr>`).join('');
  return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

function section(titleText, body, wide = false, action = '') {
  return `<section class="panel${wide ? ' wide' : ''}"><header><h2>${escapeHtml(titleText)}</h2>${action}</header>${body}</section>`;
}

function destroyCharts() {
  state.charts.forEach((chart) => chart.destroy());
  state.charts = [];
}

function lineChart(id, labels, datasets) {
  const canvas = document.querySelector(`#${id}`);
  if (!canvas) return;
  state.charts.push(new Chart(canvas, {
    type: 'line', data: { labels, datasets },
    options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { labels: { color: '#9ba9c5' } } }, scales: { x: { ticks: { color: '#71809f' }, grid: { color: '#202a40' } }, y: { beginAtZero: true, ticks: { color: '#71809f' }, grid: { color: '#202a40' } } } },
  }));
}

function topList(rows, label = 'value') {
  const max = Math.max(...(rows ?? []).map((row) => Number(row.events)), 1);
  return rows?.length ? `<div class="rank-list">${rows.slice(0, 12).map((row) => `<div><span title="${escapeHtml(row[label])}">${escapeHtml(row[label])}</span><div class="rank"><i style="width:${Number(row.events) / max * 100}%"></i></div><strong>${formatNumber(row.events)}</strong></div>`).join('')}</div>` : empty('Нет агрегированных данных');
}

async function renderOverview() {
  const [data, health] = await Promise.all([api('dashboard'), api('health')]);
  const o = data.overview;
  content.innerHTML = `<div class="metrics">${metric('Посетители', formatNumber(o.visitors), `${o.sessions} сессий`)}${metric('Просмотры', formatNumber(o.pageviews), `${o.events} событий`)}${metric('Вовлечённость', `${Math.round(o.engagementMs / 1000)} с`, 'в среднем')}${metric('Отказы', `${formatNumber(o.bounceRate)}%`, 'сессий')}${metric('Конверсии', formatNumber(o.conversions), `выручка ${formatNumber(o.revenue)}`)}${metric('Реплеи', health.replays, `${health.replayChunks} чанков`)}</div>
    <div class="dashboard-grid">${section('Динамика', '<div class="chart"><canvas id="timeline-chart"></canvas></div>', true)}${section('Популярные события', topList(data.events))}${section('Страницы', topList(data.pages))}${section('Источники', topList(data.sources))}${section('Устройства', topList(data.devices))}${section('Состояние сбора', healthCards(health), true)}</div>`;
  lineChart('timeline-chart', data.timeline.map((row) => row.day), [{ label: 'События', data: data.timeline.map((row) => row.events), borderColor: '#7c6cff', backgroundColor: '#7c6cff22', fill: true, tension: .32 }, { label: 'Посетители', data: data.timeline.map((row) => row.visitors), borderColor: '#2ed6a1', tension: .32 }]);
}

function healthCards(health) {
  const ok = health.replays > 0;
  return `<div class="health-grid"><div><span class="dot ok"></span><strong>События</strong><small>${health.events}, последнее ${formatDate(health.latestEventAt)}</small></div><div><span class="dot ${ok ? 'ok' : 'warn'}"></span><strong>Replay pipeline</strong><small>${health.replays} реплеев / ${health.replayChunks} чанков</small></div><div><span class="dot ${health.latestRollupAt ? 'ok' : 'warn'}"></span><strong>Агрегация</strong><small>${formatDate(health.latestRollupAt)}</small></div><div><span class="dot ok"></span><strong>Инфраструктура</strong><small>${escapeHtml(health.queueConnection)}:${escapeHtml(health.queue)} · ${escapeHtml(health.storageDisk)}</small></div></div>`;
}

async function renderRealtime() {
  const data = await api('realtime');
  content.innerHTML = `<div class="metrics">${metric('Активные сейчас', data.activeVisitors)}${metric('Событий за 5 минут', data.events)}</div>${section('Текущие страницы', table(data.paths, [{ key: 'path', label: 'Путь' }, { key: 'events', label: 'События' }]), true)}`;
}

async function renderEvents(report = 'events') {
  content.innerHTML = `<div class="toolbar"><input id="event-search" placeholder="Поиск по имени, пути и JSON"><input id="event-category" placeholder="Категория"><input id="event-name" placeholder="Событие"><button id="event-apply">Найти</button></div><div id="event-results"></div>`;
  const load = async (page = 1) => {
    const result = await api(report, { page, search: document.querySelector('#event-search').value, category: document.querySelector('#event-category').value, name: document.querySelector('#event-name').value });
    const columns = [{ key: 'occurred_at', label: 'Время', render: formatDate }, { key: 'name', label: 'Событие' }, { key: 'category', label: 'Категория' }, { key: 'path', label: 'Страница' }, { key: 'visitor_id', label: 'Посетитель', render: (value) => `<code>${escapeHtml(String(value).slice(0, 8))}</code>` }];
    document.querySelector('#event-results').innerHTML = section(`${formatNumber(result.meta.total)} событий`, table(result.data, columns, true) + pagination(result.meta), true);
    bindRows(result.data);
    bindPagination(load);
  };
  document.querySelector('#event-apply').addEventListener('click', () => load());
  await load();
}

function bindRows(rows, handler = showJson) {
  document.querySelectorAll('[data-row]').forEach((element) => element.addEventListener('click', () => handler(rows[Number(element.dataset.row)])));
}

function pagination(meta) {
  return `<div class="pagination"><button data-page="${Math.max(1, meta.page - 1)}" ${meta.page <= 1 ? 'disabled' : ''}>←</button><span>${meta.page} / ${meta.lastPage}</span><button data-page="${Math.min(meta.lastPage, meta.page + 1)}" ${meta.page >= meta.lastPage ? 'disabled' : ''}>→</button></div>`;
}

function bindPagination(callback) {
  document.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => callback(Number(button.dataset.page))));
}

async function renderEntities(kind) {
  const isSessions = kind === 'sessions';
  const columns = isSessions
    ? [{ key: 'started_at', label: 'Начало', render: formatDate }, { key: 'entry_path', label: 'Вход' }, { key: 'exit_path', label: 'Выход' }, { key: 'pageviews', label: 'Просмотры' }, { key: 'events_count', label: 'События' }, { key: 'engagement_ms', label: 'Вовлечённость, мс' }]
    : [{ key: 'last_seen_at', label: 'Последняя активность', render: formatDate }, { key: 'id', label: 'Visitor ID', render: (value) => `<code>${escapeHtml(value)}</code>` }, { key: 'identified_user_id', label: 'User ID' }, { key: 'sessions_count', label: 'Сессии' }, { key: 'events_count', label: 'События' }];
  const load = async (page = 1) => {
    const result = await api(kind, { page });
    content.innerHTML = section(`${formatNumber(result.meta.total)} ${isSessions ? 'сессий' : 'посетителей'}`, table(result.data, columns, true) + pagination(result.meta), true);
    bindRows(result.data, isSessions ? showSession : showVisitor);
    bindPagination(load);
  };
  await load();
}

async function showSession(session) {
  openModal('<div class="modal-loading">Загружаем путь сессии…</div>');
  const events = await api('events', { session_id: session.id, per_page: 200 });
  modalBody.innerHTML = `<div class="detail-head"><h2>Сессия ${escapeHtml(session.id)}</h2><span>${formatDate(session.started_at)} — ${formatDate(session.last_seen_at)}</span></div><pre>${escapeHtml(JSON.stringify(normalizeJson(session), null, 2))}</pre><h2>Хронология</h2>${table(events.data, [{ key: 'occurred_at', label: 'Время', render: formatDate }, { key: 'name', label: 'Событие' }, { key: 'category', label: 'Категория' }, { key: 'path', label: 'Страница' }])}`;
}

async function showVisitor(visitor) {
  openModal('<div class="modal-loading">Загружаем историю посетителя…</div>');
  const sessions = await api('sessions', { visitor_id: visitor.id, per_page: 200 });
  modalBody.innerHTML = `<div class="detail-head"><h2>Посетитель ${escapeHtml(visitor.id)}</h2><span>${formatDate(visitor.first_seen_at)} — ${formatDate(visitor.last_seen_at)}</span></div><pre>${escapeHtml(JSON.stringify(normalizeJson(visitor), null, 2))}</pre><h2>Сессии</h2>${table(sessions.data, [{ key: 'started_at', label: 'Начало', render: formatDate }, { key: 'entry_path', label: 'Вход' }, { key: 'exit_path', label: 'Выход' }, { key: 'events_count', label: 'События' }])}`;
}

async function renderAcquisition() {
  const data = await api('dashboard');
  content.innerHTML = `<div class="dashboard-grid">${section('Источники', topList(data.sources))}${section('Каналы', topList(data.mediums))}${section('Кампании', topList(data.campaigns))}${section('UTM term', topList(data.terms))}${section('UTM content', topList(data.contents))}${section('Страны', topList(data.countries), true)}</div>`;
}

async function renderQuality() {
  const [errors, performance, dashboard] = await Promise.all([api('errors'), api('performance'), api('dashboard')]);
  content.innerHTML = `<div class="metrics">${metric('Ошибки', errors.meta.total)}${metric('Performance events', performance.meta.total)}</div><div class="dashboard-grid">${section('Типы ошибок', topList(dashboard.errors, 'name'))}${section('Метрики', topList(dashboard.performance, 'name'))}${section('Последние ошибки', table(errors.data, [{ key: 'occurred_at', label: 'Время', render: formatDate }, { key: 'name', label: 'Тип' }, { key: 'path', label: 'Страница' }], true), true)}${section('Последние измерения', table(performance.data, [{ key: 'occurred_at', label: 'Время', render: formatDate }, { key: 'name', label: 'Метрика' }, { key: 'path', label: 'Страница' }], true), true)}</div>`;
  const rows = document.querySelectorAll('[data-row]');
  rows.forEach((row, index) => row.addEventListener('click', () => showJson(index < errors.data.length ? errors.data[index] : performance.data[index - errors.data.length])));
}

async function renderReplays() {
  const rows = await api('replays');
  const body = rows.length ? `<div class="replay-grid">${rows.map((row) => `<article class="replay-card"><div class="replay-preview">▶</div><div><strong>${escapeHtml(row.entry_path ?? 'Сессия')}</strong><small>${formatDate(row.started_at)} · ${formatNumber(row.events_count)} событий · ${bytes(row.bytes)}</small></div><button data-replay="${escapeHtml(row.id)}">Смотреть</button></article>`).join('')}</div>` : empty('Replay-чанки пока не поступили. Откройте «Обзор → Состояние сбора»');
  content.innerHTML = section('Записанные сессии', body, true);
  document.querySelectorAll('[data-replay]').forEach((button) => button.addEventListener('click', () => playReplay(button.dataset.replay)));
}

async function playReplay(id) {
  openModal('<div class="modal-loading">Загружаем запись…</div>', true);
  try {
    const data = await secureFetch(`${config.replayBase}/${id}`);
    if (!data.events?.length) throw new Error('В записи нет rrweb-событий');
    modalBody.innerHTML = '<div id="replay-player"></div>';
    new rrwebPlayer({ target: document.querySelector('#replay-player'), props: { events: data.events, autoPlay: true, showController: true, width: Math.min(1200, window.innerWidth - 100), height: Math.min(720, window.innerHeight - 180) } });
  } catch (error) {
    modalBody.innerHTML = `<div class="error-box">${escapeHtml(error.message)}</div>`;
  }
}

async function renderHeatmaps() {
  const rows = await api('heatmaps');
  content.innerHTML = section('Карты страниц', rows.length ? `<div class="heatmap-list">${rows.map((row) => `<article><div><strong>${escapeHtml(row.key)}</strong><small>${escapeHtml(row.path)} · ${escapeHtml(row.device_type)} · ${formatNumber(row.events)} точек</small></div><button data-heatmap="${escapeHtml(row.key)}" data-device="${escapeHtml(row.device_type ?? 'desktop')}">Открыть</button></article>`).join('')}</div>` : empty('Снимки тепловых карт пока не получены'), true);
  document.querySelectorAll('[data-heatmap]').forEach((button) => button.addEventListener('click', () => showHeatmap(button.dataset.heatmap, button.dataset.device)));
}

async function showHeatmap(key, device) {
  openModal('<div class="modal-loading">Готовим тепловую карту…</div>', true);
  try {
    const [detail, snapshot] = await Promise.all([api('heatmap', { key, device }), secureFetch(`${config.heatmapBase}/${encodeURIComponent(key)}/${device}/snapshot`)]);
    modalBody.innerHTML = '<div class="heatmap-view"><div id="heatmap-snapshot"></div><canvas id="heatmap-canvas"></canvas></div>';
    if (snapshot.events?.length) new rrwebPlayer({ target: document.querySelector('#heatmap-snapshot'), props: { events: snapshot.events, autoPlay: false, showController: false, width: Math.min(1000, window.innerWidth - 120), height: 600 } });
    drawHeatmap(document.querySelector('#heatmap-canvas'), detail.points ?? []);
  } catch (error) {
    modalBody.innerHTML = `<div class="error-box">${escapeHtml(error.message)}</div>`;
  }
}

function drawHeatmap(canvas, points) {
  canvas.width = 1000; canvas.height = 600;
  const context = canvas.getContext('2d');
  const max = Math.max(...points.map((point) => Number(point.events)), 1);
  points.filter((point) => ['click', 'move'].includes(point.point_type)).forEach((point) => {
    const x = Number(point.x_bucket) * 10; const y = Number(point.y_bucket) * 10;
    const radius = 8 + Math.sqrt(Number(point.events) / max) * 34;
    const gradient = context.createRadialGradient(x, y, 0, x, y, radius);
    gradient.addColorStop(0, 'rgba(255,70,70,.9)'); gradient.addColorStop(.45, 'rgba(255,190,50,.5)'); gradient.addColorStop(1, 'rgba(80,90,255,0)');
    context.fillStyle = gradient; context.beginPath(); context.arc(x, y, radius, 0, Math.PI * 2); context.fill();
  });
}

async function renderConversions() {
  const data = await api('dashboard');
  content.innerHTML = `<div class="dashboard-grid">${section('Цели', table(data.goals, [{ key: 'name', label: 'Цель' }, { key: 'conversions', label: 'Конверсии' }, { key: 'visitors', label: 'Посетители' }, { key: 'value', label: 'Ценность' }]), true)}${section('Ecommerce', table(data.ecommerce, [{ key: 'name', label: 'Событие' }, { key: 'events', label: 'Количество' }, { key: 'visitors', label: 'Посетители' }, { key: 'revenue', label: 'Выручка' }]))}${section('Воронки', data.funnels?.map((funnel) => `<h3>${escapeHtml(funnel.name)}</h3>${table(funnel.steps, [{ key: 'step', label: '#' }, { key: 'name', label: 'Шаг' }, { key: 'visitors', label: 'Посетители' }])}`).join('') || empty('Воронки не настроены'))}</div>`;
}

function showJson(data) {
  openModal(`<div class="detail-head"><h2>${escapeHtml(data.name ?? data.id ?? 'Детали')}</h2><span>${formatDate(data.occurred_at ?? data.started_at ?? data.last_seen_at)}</span></div><pre>${escapeHtml(JSON.stringify(normalizeJson(data), null, 2))}</pre>`);
}

function normalizeJson(value) {
  if (Array.isArray(value)) return value.map(normalizeJson);
  if (!value || typeof value !== 'object') {
    if (typeof value === 'string' && (value.startsWith('{') || value.startsWith('['))) { try { return normalizeJson(JSON.parse(value)); } catch { return value; } }
    return value;
  }
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, normalizeJson(item)]));
}

function openModal(html, large = false) {
  modalBody.innerHTML = html;
  modal.querySelector('.modal-card').classList.toggle('modal-large', large);
  modal.showModal();
}

function showTokenDialog() {
  openModal('<div class="token-dialog"><div class="lock">◆</div><h2>Защищённая аналитика</h2><p>Введите отдельный административный токен. Публичный project token здесь не подходит.</p><input id="token-input" type="password" autocomplete="current-password" placeholder="Admin token"><button id="token-submit">Продолжить</button></div>');
  document.querySelector('#token-submit').addEventListener('click', async () => {
    state.token = document.querySelector('#token-input').value.trim();
    sessionStorage.setItem('pa_admin_token', state.token);
    modal.close();
    await render();
  });
}

async function render() {
  destroyCharts();
  const [heading, description] = views[state.view];
  title.textContent = heading; subtitle.textContent = description; loading.hidden = false; content.innerHTML = '';
  document.querySelectorAll('[data-view]').forEach((button) => button.classList.toggle('active', button.dataset.view === state.view));
  try {
    if (state.view === 'overview') await renderOverview();
    else if (state.view === 'realtime') await renderRealtime();
    else if (state.view === 'events') await renderEvents();
    else if (state.view === 'sessions' || state.view === 'visitors') await renderEntities(state.view);
    else if (state.view === 'acquisition') await renderAcquisition();
    else if (state.view === 'quality') await renderQuality();
    else if (state.view === 'replays') await renderReplays();
    else if (state.view === 'heatmaps') await renderHeatmaps();
    else if (state.view === 'conversions') await renderConversions();
  } catch (error) {
    if (!modal.open) content.innerHTML = `<div class="error-box"><strong>Не удалось загрузить данные</strong><span>${escapeHtml(error.message)}</span></div>`;
  } finally { loading.hidden = true; }
}

document.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => { state.view = button.dataset.view; history.replaceState(null, '', `#${state.view}`); render(); }));
document.querySelector('#date-from').value = state.from; document.querySelector('#date-to').value = state.to;
document.querySelector('#apply-range').addEventListener('click', () => { state.from = document.querySelector('#date-from').value; state.to = document.querySelector('#date-to').value; render(); });
document.querySelector('#modal-close').addEventListener('click', () => modal.close());
state.view = views[location.hash.slice(1)] ? location.hash.slice(1) : 'overview';
if (config.authMode === 'token' && !state.token) showTokenDialog(); else render();
