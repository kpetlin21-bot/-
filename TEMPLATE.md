# Эталон оперативного дашборда

## Что это
Оперативный дашборд руководителя клининга.
Источник данных: ThroneBaron API через proxy.php + кэш MySQL (api_cache).

## Эталонный объект
- ЖК: Новое Колпино
- URL: https://api.cleansyst.ru/
- project_id: 2
- slug: novoe-kolpino
- Git-тег: dashboard-template-v1
- Коммит: c5bc520

## Состав UI
- KPI (6 плашек): % выполнения, всего задач, выполнено, 
  запланировано, в работе, пропущено — все кликабельные
- График выполнения по часам
- Карта объекта — светофор по домам (кликабельно)
- Детализация по дому (МОП + ПДТ)
- Рейтинг домов
- ПДТ — придомовая территория
- Смены сегодня (уборщицы + дворники)
- Блок «Задачи на день» (демо)
- Переключатель периодов: Сегодня / Вчера / Неделя / Месяц

## Зависимости
- index.html — фронтенд (1254 строки)
- proxy.php — API-прокси к ThroneBaron (1541 строка)
- cache_db.php — подключение к MySQL
- sync.php — синхронизация (fast: 5 мин, full: 1 час)
- db.php — credentials MySQL (закрыт .htaccess)
- MySQL: p837136_dashbrd → таблица api_cache

## API эндпоинты (proxy.php?action=...)
- dashboard — KPI сводка
- task_breakdown — детализация задач по домам
- house_breakdown — карта домов светофор
- house_detail — детализация по конкретному дому
- shifts — смены сотрудников
- projects — список всех ЖК

## Деплой
- Сервер: p837136@p837136.ftp.ihc.ru
- Путь: ~/www/api.cleansyst.ru/
- SSH-ключ: ~/.ssh/ihc_cursor_deploy_key
- Скрипт: deploy.sh

## Чеклист — как сделать дашборд для нового ЖК

1. Взять index.html из тега dashboard-template-v1
2. Заменить название ЖК в <title> и <h1>
3. Передать project_id и slug через URL: /?slug=astrid
4. proxy.php уже принимает ?slug= (ветка cursor/mysql-api-cache-7eb0)
5. Убедиться что sync.php синхронизирует этот project_id
   (сейчас в $projects все 12 активных ЖК — уже готово)
6. Проверить дашборд: https://api.cleansyst.ru/?slug=astrid

## Активные ЖК (12 объектов)
| project_id | slug | Название | Город |
|---|---|---|---|
| 1 | astrid | ЖК Астрид | Санкт-Петербург |
| 2 | novoe-kolpino | ЖК Новое Колпино | Санкт-Петербург |
| 4 | kurortny | ЖК Курортный | Санкт-Петербург |
| 6 | kosmonavtov-11 | ЖК Космонавтов 11 | Екатеринбург |
| 7 | iset-park | ЖК Исеть парк | Екатеринбург |
| 9 | utes | ЖК Утес | Екатеринбург |
| 10 | aston-dvizhenie | ЖК Астон.Движение | Екатеринбург |
| 11 | aston-reforma | ЖК Астон.Реформа | Екатеринбург |
| 12 | noksa-park | ЖК Нокса парк | Казань |
| 13 | tvoya-privilegiya | ЖК Твоя Привилегия | Екатеринбург |
| 14 | mily-dom | ЖК Дом Милый дом | Екатеринбург |
| 15 | river-park | ЖК River Park | Екатеринбург |

## Карта объекта (Leaflet + OpenStreetMap)

### Технология
- Leaflet 1.9 + тайлы CartoDB Voyager
- Контуры зданий из Overpass API (way[building])
- Прокси: /api/overpass.php (User-Agent: cleansyst-dashboard/1.0)
- Сопоставление TB ↔ OSM через normalizeHouseNum()
  (конвертирует "1к3" → "1/3")

### Цвета светофора
- pct >= 100: #C8E6A0 / border #639922 (зелёный)
- pct >= 80:  #FAEEDA / border #BA7517 (жёлтый)
- pct < 80:   #FCEBEB / border #E24B4A (красный)
- нет данных: #D4D0C8 / border #B8B4AC (серый)

### Embed-режим (?embed=1)
- Скрывает шапку страницы
- Отключает перетаскивание и зум карты
- Передаёт клик на дом через postMessage → loadHouseDetail()

### Шаблон файла
templates/map-template.html — основа для новых карт.
Переменные для замены:
- {{ZK_NAME}} — название ЖК
- {{PROJECT_ID}} — project_id в ThroneBaron
- {{BBOX}} — "(юг,запад,север,восток)"
- {{CENTER}} — "[lat, lng]"
- {{SLUG}} — slug для ссылок

Git-тег: dashboard-template-v2

### Чеклист для новой карты
1. Скопировать templates/map-template.html → map-SLUG.html
2. Заменить все {{переменные}}
3. Проверить bbox через OpenStreetMap —
   найти ЖК на карте и снять координаты границ
4. Задеплоить на сервер
5. Встроить в дашборд:
   <iframe src="/map-SLUG.html?embed=1"
     style="width:100%;height:500px;border:none;border-radius:8px"
     loading="lazy">

## Что НЕ входит в этот шаблон
- home.html — главная навигационная страница (карта РФ + города)
- backoffice.html — бэк-офис (финансы, HR, отчёты)
- Страница «Информация по ЖК» — в разработке

## Следующий шаг (техдолг)
Параметризовать index.html по ?slug= чтобы один файл 
работал для всех 12 ЖК без дублирования кода.
Целевой URL: https://api.cleansyst.ru/?slug=astrid
