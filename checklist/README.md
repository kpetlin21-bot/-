# Чек-лист уборки (PWA)

- **Приложение:** https://api.cleansyst.ru/checklist/
- **История:** https://api.cleansyst.ru/checklist/history.html?jk=Астрид
- **API:** `api/checklist/save.php`, `list.php`, `get.php`

## Деплой

```bash
bash deploy-checklist.sh
```

SSH-ключ: `~/.ssh/ihc_deploy_key` или `~/.ssh/ihc_cursor_deploy_key`.

## Интеграция в дашборд ЖК

```html
<div id="checklist-recent"></div>
<script src="/checklist/dashboard-checklist-block.js"></script>
<script>
  ChecklistDashboardBlock.mount('#checklist-recent', {
    jk: 'astrid',
    jkQuery: 'Астрид',
    jkLabel: 'ЖК Астрид'
  });
</script>
```

`jkQuery` — подстрока для фильтра `jk_name` в API (например «Астрид» для astrid.html).
