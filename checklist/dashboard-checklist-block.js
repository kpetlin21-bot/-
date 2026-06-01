/**
 * Блок «Последние проверки» для дашбордов ЖК.
 * Подключение в astrid.html и др.:
 *
 * <div id="checklist-recent"></div>
 * <script src="/checklist/dashboard-checklist-block.js"></script>
 * <script>ChecklistDashboardBlock.mount('#checklist-recent', { jk: 'astrid', jkLabel: 'ЖК Астрид' });</script>
 */
(function (global) {
  const API = '/checklist/api/checklist/list.php';

  function pctClass(p) {
    if (p >= 80) return 'ok';
    if (p >= 60) return 'warn';
    return 'crit';
  }

  function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    return d.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  async function mount(selector, opts) {
    const el = document.querySelector(selector);
    if (!el) return;
    const jk = opts.jk || '';
    const label = opts.jkLabel || jk;
    el.innerHTML =
      '<div class="card"><h3>Последние проверки</h3><p class="status">Загрузка…</p></div>';

    try {
      const jkQuery = opts.jkQuery || opts.jkLabel || jk;
      const res = await fetch(
        API + '?jk=' + encodeURIComponent(jkQuery) + '&limit=5'
      );
      const data = await res.json();
      const list = data.checklists || [];
      if (!list.length) {
        el.innerHTML =
          '<div class="card"><h3>Последние проверки</h3><p class="status">Пока нет чек-листов</p>' +
          '<p><a href="/checklist/index.html">Пройти проверку</a></p></div>';
        return;
      }
      let rows = '';
      list.forEach((c) => {
        const p = Math.round(c.score_total || 0);
        const cls = pctClass(p);
        rows +=
          '<tr class="clickable" data-id="' +
          c.id +
          '"><td>' +
          fmtDate(c.checked_at) +
          '</td><td>' +
          (c.manager_name || '—') +
          '</td><td><span class="pill ' +
          cls +
          '">' +
          p +
          '%</span></td><td><a href="/checklist/history.html?jk=' +
          encodeURIComponent(jk) +
          '&id=' +
          c.id +
          '">Подробнее</a></td></tr>';
      });
      el.innerHTML =
        '<div class="card"><h3>Последние проверки</h3>' +
        '<table><thead><tr><th>Дата</th><th>Менеджер</th><th>Итог</th><th></th></tr></thead><tbody>' +
        rows +
        '</tbody></table>' +
        '<p style="margin-top:10px"><a href="/checklist/history.html?jk=' +
        encodeURIComponent(jk) +
        '">Все чек-листы</a> · <a href="/checklist/index.html">Новая проверка</a></p></div>';
    } catch (e) {
      el.innerHTML =
        '<div class="card"><h3>Последние проверки</h3><p class="status">Ошибка загрузки</p></div>';
    }
  }

  global.ChecklistDashboardBlock = { mount };
})(typeof window !== 'undefined' ? window : globalThis);
