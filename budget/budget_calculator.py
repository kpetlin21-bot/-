#!/usr/bin/env python3
"""
Универсальный калькулятор бюджета нового объекта ЖК.
Структура основана на: ПланФакт (БДР) + Google Sheets (ОПиУ) + Опенбук (КП).
"""

from __future__ import annotations
from pathlib import Path
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation

# ═══════════════════════════════════════════════════════════════
# СТИЛИ
# ═══════════════════════════════════════════════════════════════
BG = {
    "input":    "FFF2CC",   # жёлтый — ввод данных
    "formula":  "EBF3FB",   # голубой — формулы (не трогать)
    "result":   "E2EFDA",   # зелёный — итоговые результаты
    "header":   "1F3864",   # тёмно-синий — заголовок
    "section":  "D6E4F0",   # светло-голубой — раздел
    "sub":      "F2F2F2",   # серый — подраздел
    "warn":     "FCE4D6",   # оранжевый — убыток/предупреждение
    "mop":      "DDEEFF",   # голубой — МОП
    "pt":       "D5F5E3",   # зелёный — ПТ
    "uds":      "FDEBD0",   # оранжевый — УДС
    "aup":      "EDE7F6",   # фиолетовый — АУП
    "white":    "FFFFFF",
}

def _f(color="000000", bold=False, size=10, italic=False):
    return Font(color=color, bold=bold, size=size, italic=italic)

def _fill(hex6):
    return PatternFill("solid", fgColor=hex6)

def _al(h="left", v="center", wrap=False):
    return Alignment(horizontal=h, vertical=v, wrap_text=wrap)

def _border(c="BFBFBF"):
    s = Side(style="thin", color=c)
    return Border(left=s, right=s, top=s, bottom=s)

def _thick_border():
    t = Side(style="medium", color="1F3864")
    s = Side(style="thin", color="BFBFBF")
    return Border(left=s, right=s, top=s, bottom=t)

def W(ws, col, width):
    ws.column_dimensions[get_column_letter(col) if isinstance(col, int) else col].width = width

def H(ws, row, height):
    ws.row_dimensions[row].height = height

def c(ws, row, col, value=None, bg=None, fg="000000", bold=False,
      fmt=None, h="left", size=10, italic=False, wrap=False, border=True):
    cc = ws.cell(row, col)
    if value is not None:
        cc.value = value
    cc.font = _f(fg, bold, size, italic)
    if bg:
        cc.fill = _fill(bg)
    cc.alignment = _al(h=h, wrap=wrap)
    if fmt:
        cc.number_format = fmt
    if border:
        cc.border = _border()
    return cc

def merge(ws, r, c1, c2, text="", bg=None, fg="000000", bold=False, size=10, h="left"):
    ws.merge_cells(start_row=r, start_column=c1, end_row=r, end_column=c2)
    cc = ws.cell(r, c1, text)
    cc.font = _f(fg, bold, size)
    if bg:
        cc.fill = _fill(bg)
    cc.alignment = _al(h=h)
    cc.border = _border()
    return cc

def section_row(ws, r, text, ncols, bg=BG["section"]):
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=ncols)
    cc = ws.cell(r, 1, text)
    cc.font = _f(bold=True, size=10)
    cc.fill = _fill(bg)
    cc.alignment = _al("left")
    cc.border = _border()
    for col in range(2, ncols+1):
        cc2 = ws.cell(r, col)
        cc2.fill = _fill(bg)
        cc2.border = _border()

NFMT = "#,##0"
PFMT = "0.0%"
RFMT = "#,##0 ₽"

# ═══════════════════════════════════════════════════════════════
# ЛИСТ 1: ПАРАМЕТРЫ ОБЪЕКТА
# ═══════════════════════════════════════════════════════════════
def build_params(wb: Workbook) -> None:
    ws = wb.create_sheet("📋 Параметры")
    for col, w in [(1,32),(2,22),(3,18),(4,22),(5,18)]:
        W(ws, col, w)

    # Заголовок
    H(ws, 1, 30)
    merge(ws,1,1,5, "🏢  КАЛЬКУЛЯТОР БЮДЖЕТА ЖК — Параметры объекта",
          bg=BG["header"], fg="FFFFFF", bold=True, size=13, h="center")

    R = 3
    def legend_row(row):
        items = [("Жёлтый", BG["input"], "Ввод данных (заполните)"),
                 ("Голубой", BG["formula"], "Формула (не трогайте)"),
                 ("Зелёный", BG["result"], "Итоговый результат")]
        for ci, (lbl, clr, desc) in enumerate(items, 1):
            c(ws, row, ci*2-1, lbl, bg=clr, h="center", size=9, bold=True)
            c(ws, row, ci*2, desc, bg=BG["white"], size=9)

    legend_row(R); R += 2

    # ── Блок 1: Основная информация ──────────────────────────
    section_row(ws, R, "А.  ОСНОВНАЯ ИНФОРМАЦИЯ ОБ ОБЪЕКТЕ", 5); R += 1
    params_a = [
        ("Название ЖК", "Новый объект", None),
        ("Город / регион", "Санкт-Петербург", None),
        ("Количество корпусов", 4, NFMT),
        ("Общая площадь МОП, м²", 12000, NFMT),
        ("Площадь территории, м²", 8000, NFMT),
        ("Площадь УДС, м²", 5000, NFMT),
        ("Количество квартир", 500, NFMT),
        ("Этажность (средняя)", 17, NFMT),
        ("Дата начала контракта", "01.07.2026", None),
        ("Срок контракта, лет", 1, NFMT),
    ]
    for label, default, fmt in params_a:
        c(ws, R, 1, label, bg=BG["sub"])
        cc = c(ws, R, 2, default, bg=BG["input"], h="right" if fmt else "left", fmt=fmt)
        for col in range(3,6): c(ws, R, col, bg=BG["white"])
        R += 1

    R += 1
    # ── Блок 2: Направления услуг ─────────────────────────────
    section_row(ws, R, "Б.  НАПРАВЛЕНИЯ УСЛУГ (отметьте ДА/НЕТ)", 5); R += 1
    directions = [
        ("МОП — уборка МКД", "ДА", BG["mop"]),
        ("ПТ — территория, озеленение", "ДА", BG["pt"]),
        ("УДС — уборка дорог/проездов", "НЕТ", BG["uds"]),
        ("Снегоуборка", "ДА", BG["white"]),
        ("Доп. услуги", "НЕТ", BG["white"]),
    ]
    dv_yesno = DataValidation(type="list", formula1='"ДА,НЕТ"', allow_blank=False)
    ws.add_data_validation(dv_yesno)
    for label, default, row_bg in directions:
        c(ws, R, 1, label, bg=row_bg)
        cc = c(ws, R, 2, default, bg=BG["input"], h="center", bold=True)
        dv_yesno.add(cc)
        for col in range(3,6): c(ws, R, col, bg=BG["white"])
        R += 1

    R += 1
    # ── Блок 3: Ставки налогов и параметры ──────────────────────
    section_row(ws, R, "В.  НАЛОГИ И СТАВКИ", 5); R += 1
    tax_params = [
        ("Режим налогообложения", "УСН 6%", None),
        ("Ставка НДС", 0.05, PFMT),
        ("Ставка УСН (% от выручки)", 0.015, PFMT),
        ("Ставка НДФЛ", 0.13, PFMT),
        ("Ставка страх. взносов (РФ, до МРОТ×2)", 0.30, PFMT),
        ("Ставка страх. взносов (выше МРОТ×2)", 0.15, PFMT),
        ("Ставка страх. взносов (СНГ)", 0.243, PFMT),
        ("Налог на прибыль (в КП)", 0.15, PFMT),
    ]
    for label, default, fmt in tax_params:
        c(ws, R, 1, label, bg=BG["sub"])
        c(ws, R, 2, default, bg=BG["input"], h="right" if fmt else "left", fmt=fmt)
        for col in range(3,6): c(ws, R, col, bg=BG["white"])
        R += 1

    R += 1
    # ── Блок 4: Коммерческие параметры ──────────────────────────
    section_row(ws, R, "Г.  КОММЕРЧЕСКИЕ ПАРАМЕТРЫ (структура КП)", 5); R += 1
    comm_params = [
        ("Накладные расходы (% от себест.)", 0.15, PFMT),
        ("Целевая прибыль (% от себест.)", 0.20, PFMT),
        ("Целевая рент. чистой прибыли", 0.20, PFMT),
        ("Расходы бэк-офиса (% от выручки)", 0.08, PFMT),
        ("Расходы управления (% от выручки)", 0.04, PFMT),
    ]
    for label, default, fmt in comm_params:
        c(ws, R, 1, label, bg=BG["sub"])
        c(ws, R, 2, default, bg=BG["input"], h="right", fmt=fmt)
        for col in range(3,6): c(ws, R, col, bg=BG["white"])
        R += 1

    R += 1
    # ── Блок 5: Сезонность ──────────────────────────────────────
    section_row(ws, R, "Д.  СЕЗОННОСТЬ", 5); R += 1
    c(ws, R, 1, "Летние месяцы (апр–сен)", bg=BG["sub"])
    c(ws, R, 2, 6, bg=BG["input"], h="right", fmt=NFMT)
    c(ws, R, 3, "мес.", bg=BG["white"]); R += 1
    c(ws, R, 1, "Зимние месяцы (окт–мар)", bg=BG["sub"])
    c(ws, R, 2, 6, bg=BG["input"], h="right", fmt=NFMT)
    c(ws, R, 3, "мес.", bg=BG["white"]); R += 1
    c(ws, R, 1, "Месяцев снегоуборки", bg=BG["sub"])
    c(ws, R, 2, 5, bg=BG["input"], h="right", fmt=NFMT)
    c(ws, R, 3, "мес.", bg=BG["white"]); R += 1

    R += 2
    # ── Инструкция ──────────────────────────────────────────────
    ws.merge_cells(start_row=R, start_column=1, end_row=R+3, end_column=5)
    cc = ws.cell(R, 1)
    cc.value = ("📌  КАК ПОЛЬЗОВАТЬСЯ:\n"
                "1. Заполните жёлтые ячейки на этом листе\n"
                "2. Перейдите на лист «👥 Расчёт ФОТ» — укажите штат сотрудников\n"
                "3. На листе «📊 Смета + КП» проверьте себестоимость и цену контракта\n"
                "4. Лист «💰 П&Л» покажет помесячный P&L (как в ПланФакте)\n"
                "5. Итоги на листе «🎯 Итоги»")
    cc.font = _f(size=10, italic=True)
    cc.fill = _fill("FFFDE7")
    cc.alignment = _al("left", wrap=True)
    cc.border = _border()

    ws.freeze_panes = "A3"


# ═══════════════════════════════════════════════════════════════
# ЛИСТ 2: РАСЧЁТ ФОТ
# ═══════════════════════════════════════════════════════════════
def build_fot(wb: Workbook) -> None:
    ws = wb.create_sheet("👥 Расчёт ФОТ")
    cols = [("A",40),("B",12),("C",12),("D",16),("E",16),("F",14),
            ("G",12),("H",14),("I",16),("J",16)]
    for col, w in cols:
        W(ws, col, w)

    H(ws, 1, 28)
    merge(ws,1,1,10,"👥  РАСЧЁТ ФОТ ПО НАПРАВЛЕНИЯМ",
          bg=BG["header"], fg="FFFFFF", bold=True, size=12, h="center")

    R = 3
    # Легенда ставок (ссылки на Параметры)
    c(ws, R, 1, "Ставки (из листа Параметры):", bold=True, bg=BG["sub"]); R += 1
    ref_params = [
        ("НДФЛ:", "='📋 Параметры'!B19", PFMT),
        ("СВ РФ (до порога):", "='📋 Параметры'!B20", PFMT),
        ("СВ (выше порога):", "='📋 Параметры'!B21", PFMT),
        ("СВ СНГ:", "='📋 Параметры'!B22", PFMT),
    ]
    for i, (label, formula, fmt) in enumerate(ref_params):
        c(ws, R, 1, label, bg=BG["sub"])
        c(ws, R, 2, formula, bg=BG["formula"], fmt=fmt)
        c(ws, R, 3, "", bg=BG["white"]); R += 1

    NDFL_CELL  = "B4"
    SV_RF_CELL = "B5"

    R += 1
    # Таблица: заголовок
    headers = ["Должность", "Кол-во\n(лето)", "Кол-во\n(зима)", "ЗП на руки\n(1 чел.)",
               "ЗП gross\n(1 чел.)", "НДФЛ\n(1 чел.)", "Ставка\nСВ%",
               "СВ\n(1 чел.)", "Расход/мес\n(лето)", "Расход/мес\n(зима)"]
    for ci, h in enumerate(headers, 1):
        cc = c(ws, R, ci, h, bg=BG["header"], fg="FFFFFF", bold=True, h="center", size=9)
        cc.alignment = _al("center", wrap=True)
    H(ws, R, 35); R += 1

    TABLE_HEAD = R - 1
    TABLE_START = R

    # ── Направления ──────────────────────────────────────────────
    directions = [
        ("МОП — УБОРКА МКД", BG["mop"], [
            ("Уборщик производственных помещений", 4, 4, 60_000),
            ("Уборщик (сменный)", 0, 0, 60_000),
            ("Уборщик холлов и лифтов", 0, 0, 55_000),
            ("ОПМ (оператор профмашины)", 0, 0, 65_000),
            ("Администратор/старший смены", 1, 1, 80_000),
        ]),
        ("ПТ — ТЕРРИТОРИЯ И ОЗЕЛЕНЕНИЕ", BG["pt"], [
            ("Дворник", 3, 4, 80_000),
            ("Дворник-тракторист", 0, 0, 90_000),
            ("Бригадир", 1, 1, 80_000),
            ("Садовник", 0, 0, 70_000),
            ("Администратор", 0, 0, 120_000),
        ]),
        ("УДС — УБОРКА ДОРОГ И ПРОЕЗДОВ", BG["uds"], [
            ("Дворник", 0, 0, 80_000),
            ("Тракторист", 0, 0, 90_000),
            ("Бригадир", 0, 0, 80_000),
            ("Администратор", 0, 0, 120_000),
        ]),
        ("АУП — УПРАВЛЕНИЕ ОБЪЕКТОМ", BG["aup"], [
            ("Менеджер объекта", 1, 1, 80_000),
            ("Менеджер разъездной (доля)", 0, 0, 120_000),
        ]),
    ]

    # Итоговые строки по направлениям (для сводки)
    dir_total_rows = {}

    for dir_name, dir_bg, roles in directions:
        section_row(ws, R, f"  {dir_name}", 10, bg=dir_bg); dir_start = R; R += 1
        role_rows = []
        for role, cnt_s, cnt_w, net in roles:
            role_row = R
            role_rows.append(role_row)
            c(ws, R, 1, f"    {role}", bg=BG["white"], size=10)
            c(ws, R, 2, cnt_s if cnt_s > 0 else None, bg=BG["input"], h="center", fmt=NFMT)
            c(ws, R, 3, cnt_w if cnt_w > 0 else None, bg=BG["input"], h="center", fmt=NFMT)
            c(ws, R, 4, net, bg=BG["input"], h="right", fmt=NFMT)

            # Gross = net / (1 - НДФЛ)
            c(ws, R, 5, f"=IF(D{R}=0,0,D{R}/(1-{NDFL_CELL}))",
              bg=BG["formula"], h="right", fmt=NFMT)
            # НДФЛ
            c(ws, R, 6, f"=IF(E{R}=0,0,E{R}*{NDFL_CELL})",
              bg=BG["formula"], h="right", fmt=NFMT)
            # Ставка СВ (по умолчанию РФ основная)
            c(ws, R, 7, f"={SV_RF_CELL}", bg=BG["input"], h="center", fmt=PFMT)
            # СВ
            c(ws, R, 8, f"=IF(E{R}=0,0,E{R}*G{R})",
              bg=BG["formula"], h="right", fmt=NFMT)
            # Расход лето = (Gross + СВ) * кол-во лето
            c(ws, R, 9, f"=IF(B{R}=0,0,(E{R}+H{R})*B{R})",
              bg=BG["result"], h="right", fmt=NFMT, bold=True)
            # Расход зима
            c(ws, R, 10, f"=IF(C{R}=0,0,(E{R}+H{R})*C{R})",
              bg=BG["result"], h="right", fmt=NFMT, bold=True)
            R += 1

        # Итог по направлению
        if role_rows:
            rng_s = ",".join(f"I{r}" for r in role_rows)
            rng_w = ",".join(f"J{r}" for r in role_rows)
            c(ws, R, 1, f"  ИТОГО {dir_name.split('—')[0].strip()}", bold=True,
              bg=dir_bg, size=10)
            c(ws, R, 2, f"=SUM({','.join(f'B{r}' for r in role_rows)})",
              bg=dir_bg, h="center", fmt=NFMT, bold=True)
            c(ws, R, 3, f"=SUM({','.join(f'C{r}' for r in role_rows)})",
              bg=dir_bg, h="center", fmt=NFMT, bold=True)
            for col in range(4, 9):
                c(ws, R, col, bg=dir_bg)
            c(ws, R, 9, f"=SUM({rng_s})", bg=dir_bg, h="right", fmt=NFMT, bold=True)
            c(ws, R, 10, f"=SUM({rng_w})", bg=dir_bg, h="right", fmt=NFMT, bold=True)
            dir_total_rows[dir_name.split("—")[0].strip()] = R
            R += 1
        R += 1

    TABLE_END = R - 2

    # ── ИТОГОВАЯ СВОДКА ──────────────────────────────────────────
    section_row(ws, R, "ИТОГО ФОТ ПО ВСЕМ НАПРАВЛЕНИЯМ (с налогами)", 10, bg=BG["section"]); R += 1
    dir_totals_rows = list(dir_total_rows.values())

    c(ws, R, 1, "Всего ФОТ — ЛЕТНИЙ месяц", bold=True, bg=BG["result"])
    for col in range(2, 9): c(ws, R, col, bg=BG["result"])
    sum_s = "+".join(f"I{r}" for r in dir_totals_rows)
    c(ws, R, 9, f"={sum_s}", bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    c(ws, R, 10, bg=BG["result"])
    FOT_SUMMER_ROW = R; R += 1

    c(ws, R, 1, "Всего ФОТ — ЗИМНИЙ месяц", bold=True, bg=BG["result"])
    for col in range(2, 10): c(ws, R, col, bg=BG["result"])
    sum_w = "+".join(f"J{r}" for r in dir_totals_rows)
    c(ws, R, 10, f"={sum_w}", bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    FOT_WINTER_ROW = R; R += 1

    c(ws, R, 1, "Среднегодовой ФОТ/мес", bold=True, bg=BG["formula"])
    for col in range(2, 9): c(ws, R, col, bg=BG["formula"])
    c(ws, R, 9, (f"=(I{FOT_SUMMER_ROW}*'📋 Параметры'!B32+"
                 f"J{FOT_WINTER_ROW}*'📋 Параметры'!B33)/12"),
      bg=BG["formula"], h="right", fmt=NFMT, bold=True); R += 1

    R += 1
    # ── Итого NET зарплаты ────────────────────────────────────────
    c(ws, R, 1, "Итого ЗП на руки / мес (лето)", bg=BG["sub"])
    c(ws, R, 2, f"=SUMPRODUCT(B{TABLE_START}:B{TABLE_END},D{TABLE_START}:D{TABLE_END})",
      bg=BG["formula"], h="right", fmt=NFMT)
    R += 1
    c(ws, R, 1, "Итого ЗП на руки / мес (зима)", bg=BG["sub"])
    c(ws, R, 2, f"=SUMPRODUCT(C{TABLE_START}:C{TABLE_END},D{TABLE_START}:D{TABLE_END})",
      bg=BG["formula"], h="right", fmt=NFMT)
    R += 1

    ws.freeze_panes = "A9"


# ═══════════════════════════════════════════════════════════════
# ЛИСТ 3: СМЕТА + КП (Опенбук стиль)
# ═══════════════════════════════════════════════════════════════
def build_estimate(wb: Workbook) -> None:
    ws = wb.create_sheet("📊 Смета + КП")
    for col, w in [(1,40),(2,20),(3,20),(4,20),(5,16)]:
        W(ws, col, w)

    H(ws, 1, 28)
    merge(ws,1,1,5,"📊  СМЕТА И РАСЧЁТ ЦЕНЫ КОНТРАКТА",
          bg=BG["header"], fg="FFFFFF", bold=True, size=12, h="center")

    R = 3
    # Заголовки
    for ci, (h, bg_) in enumerate(
        [("Статья затрат",BG["header"]),("Лето/мес",BG["mop"]),
         ("Зима/мес",BG["pt"]),("Среднегод./мес",BG["header"]),("% от себест.",BG["header"])], 1):
        cc = c(ws, R, ci, h, bg=bg_, fg="FFFFFF", bold=True, h="center")
        cc.alignment = _al("center")
    H(ws, R, 28); R += 1

    # Строки ФОТ по направлениям
    section_row(ws, R, "А.  ФОТ (из листа «Расчёт ФОТ»)", 5, bg=BG["section"]); R += 1

    fot_rows = {}
    fot_defs = [
        ("ФОТ МОП (с налогами)", "МОП", BG["mop"]),
        ("ФОТ ПТ (с налогами)",  "ПТ",  BG["pt"]),
        ("ФОТ УДС (с налогами)", "УДС", BG["uds"]),
        ("ФОТ АУП (с налогами)", "АУП", BG["aup"]),
    ]

    # Ищем строки итогов из листа ФОТ через поиск по тексту (пишем формулы с INDIRECT)
    fot_dir_labels = {
        "МОП": "ИТОГО МОП",
        "ПТ":  "ИТОГО ПТ",
        "УДС": "ИТОГО УДС",
        "АУП": "ИТОГО АУП",
    }

    # Поскольку точные строки неизвестны — вводим ручные ссылки с комментарием
    # (пользователь увидит строку в ФОТ листе и при необходимости скорректирует)
    fot_summer_refs = {
        "МОП": "='👥 Расчёт ФОТ'!I22",
        "ПТ":  "='👥 Расчёт ФОТ'!I29",
        "УДС": "='👥 Расчёт ФОТ'!I35",
        "АУП": "='👥 Расчёт ФОТ'!I39",
    }
    fot_winter_refs = {
        "МОП": "='👥 Расчёт ФОТ'!J22",
        "ПТ":  "='👥 Расчёт ФОТ'!J29",
        "УДС": "='👥 Расчёт ФОТ'!J35",
        "АУП": "='👥 Расчёт ФОТ'!J39",
    }

    # Вместо хрупких ссылок — используем именованные INPUT ячейки на этом листе
    # Пользователь копирует итоговые ФОТ с листа Расчёт ФОТ
    note_row = R
    merge(ws, R, 1, 5, "  ⚠️  Заполните ячейки «Лето/мес» и «Зима/мес» вручную из листа «👥 Расчёт ФОТ» (строки ИТОГО по направлениям), ИЛИ удалите значения — тогда используйте формулы ниже.", bg="FFFDE7")
    ws.cell(R,1).font = _f(italic=True, size=9); R += 1

    for label, key, row_bg in fot_defs:
        c(ws, R, 1, f"  {label}", bg=row_bg)
        c(ws, R, 2, 0, bg=BG["input"], h="right", fmt=NFMT)  # ФОТ лето — ВВОД
        c(ws, R, 3, 0, bg=BG["input"], h="right", fmt=NFMT)  # ФОТ зима — ВВОД
        c(ws, R, 4, f"=(B{R}*'📋 Параметры'!B32+C{R}*'📋 Параметры'!B33)/12",
          bg=BG["formula"], h="right", fmt=NFMT)
        c(ws, R, 5, "", bg=BG["white"])
        fot_rows[key] = R; R += 1

    fot_row_nums = [fot_rows[k] for k in ["МОП","ПТ","УДС","АУП"]]
    fot_sum = "+".join(f"B{r}" for r in fot_row_nums)
    fot_sum_w = "+".join(f"C{r}" for r in fot_row_nums)
    fot_sum_avg = "+".join(f"D{r}" for r in fot_row_nums)
    c(ws, R, 1, "  ИТОГО ФОТ", bold=True, bg=BG["section"])
    c(ws, R, 2, f"=SUM({','.join(f'B{r}' for r in fot_row_nums)})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 3, f"=SUM({','.join(f'C{r}' for r in fot_row_nums)})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 4, f"=SUM({','.join(f'D{r}' for r in fot_row_nums)})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 5, "", bg=BG["section"])
    FOT_TOT_ROW = R; R += 1

    # ── Прочие прямые затраты ──────────────────────────────────
    R += 1
    section_row(ws, R, "Б.  ПРОЧИЕ ПРЯМЫЕ ЗАТРАТЫ", 5, bg=BG["section"]); R += 1
    direct_items = [
        ("Расходные материалы и моющие средства", 20_000, 20_000),
        ("Сыпучие материалы (ПТ, сезонно)", 10_000, 30_000),
        ("Малый инвентарь (лопаты, грабли, мётлы)", 5_000, 5_000),
        ("Обслуживание и ремонт техники", 5_000, 5_000),
        ("Спецодежда и обувь (в мес.)", 3_000, 4_000),
        ("Вывоз мусора", 0, 0),
        ("Прочие прямые расходы", 0, 0),
    ]
    other_direct_rows = []
    for label, summer_def, winter_def in direct_items:
        c(ws, R, 1, f"  {label}", bg=BG["white"])
        c(ws, R, 2, summer_def or None, bg=BG["input"], h="right", fmt=NFMT)
        c(ws, R, 3, winter_def or None, bg=BG["input"], h="right", fmt=NFMT)
        c(ws, R, 4, f"=(B{R}*'📋 Параметры'!B32+C{R}*'📋 Параметры'!B33)/12",
          bg=BG["formula"], h="right", fmt=NFMT)
        c(ws, R, 5, "", bg=BG["white"])
        other_direct_rows.append(R); R += 1

    other_s = "+".join(f"B{r}" for r in other_direct_rows)
    other_w = "+".join(f"C{r}" for r in other_direct_rows)
    c(ws, R, 1, "  ИТОГО прочие прямые", bold=True, bg=BG["section"])
    c(ws, R, 2, f"=SUM({other_s})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 3, f"=SUM({other_w})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 4, f"=SUM({','.join(f'D{r}' for r in other_direct_rows)})", bg=BG["section"], h="right", fmt=NFMT, bold=True)
    c(ws, R, 5, "", bg=BG["section"])
    OTHER_TOT_ROW = R; R += 1

    # ── СЕБЕСТОИМОСТЬ ─────────────────────────────────────────────
    R += 1
    section_row(ws, R, "В.  СЕБЕСТОИМОСТЬ", 5, bg="D5E8D4"); R += 1
    c(ws, R, 1, "ИТОГО СЕБЕСТОИМОСТЬ", bold=True, bg=BG["result"], size=11)
    c(ws, R, 2, f"=B{FOT_TOT_ROW}+B{OTHER_TOT_ROW}", bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    c(ws, R, 3, f"=C{FOT_TOT_ROW}+C{OTHER_TOT_ROW}", bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    c(ws, R, 4, f"=D{FOT_TOT_ROW}+D{OTHER_TOT_ROW}", bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    c(ws, R, 5, "100%", bg=BG["result"], h="center", bold=True)
    COST_ROW = R; R += 1

    # ── РАСЧЁТ ЦЕНЫ КОНТРАКТА (Опенбук стиль) ────────────────────
    R += 1
    section_row(ws, R, "Г.  РАСЧЁТ ЦЕНЫ КОНТРАКТА (по методологии Опенбук)", 5, bg="D6EAF8"); R += 1

    prc_rows = {}
    pricing_items = [
        ("Накладные расходы (%)", "='📋 Параметры'!B27", f"=D{COST_ROW}*'📋 Параметры'!B27"),
        ("Прибыль (%)",           "='📋 Параметры'!B28", f"=D{COST_ROW}*'📋 Параметры'!B28"),
        ("Налог на прибыль (15%)", None,                 f"=D{COST_ROW}*'📋 Параметры'!B28*'📋 Параметры'!B24"),
    ]
    for label, pct_formula, sum_formula in pricing_items:
        c(ws, R, 1, f"  {label}", bg=BG["formula"])
        if pct_formula:
            c(ws, R, 5, pct_formula, bg=BG["formula"], h="center", fmt=PFMT)
        else:
            c(ws, R, 5, "15%", bg=BG["formula"], h="center", fmt=PFMT)
        for col in [2,3]:
            c(ws, R, col, bg=BG["white"])
        c(ws, R, 4, sum_formula, bg=BG["formula"], h="right", fmt=NFMT)
        prc_rows[label] = R; R += 1

    # Цена без НДС
    c(ws, R, 1, "Стоимость контракта БЕЗ НДС/мес", bold=True, bg=BG["result"], size=11)
    nr = [prc_rows[k] for k in prc_rows]
    c(ws, R, 4, f"=D{COST_ROW}+" + "+".join(f"D{r}" for r in nr),
      bg=BG["result"], h="right", fmt=NFMT, bold=True, size=11)
    for col in [2,3,5]: c(ws, R, col, bg=BG["result"])
    REV_NO_NDS_ROW = R; R += 1

    # НДС
    c(ws, R, 1, "НДС 5%/мес", bg=BG["formula"])
    c(ws, R, 4, f"=D{REV_NO_NDS_ROW}*'📋 Параметры'!B18",
      bg=BG["formula"], h="right", fmt=NFMT)
    for col in [2,3,5]: c(ws, R, col, bg=BG["formula"])
    NDS_ROW = R; R += 1

    # Итоговая цена
    c(ws, R, 1, "★  СТОИМОСТЬ КОНТРАКТА С НДС/мес", bold=True, bg="1F3864", size=12)
    c(ws, R, 4, f"=D{REV_NO_NDS_ROW}+D{NDS_ROW}",
      bg="1F3864", fg="FFFFFF", h="right", fmt=NFMT, bold=True, size=12)
    ws.cell(R,4).font = _f("FFFFFF", bold=True, size=13)
    for col in [2,3,5]:
        c(ws, R, col, bg="1F3864")
    CONTRACT_ROW = R; R += 1

    # ── Рентабельность ────────────────────────────────────────────
    R += 1
    section_row(ws, R, "Д.  КЛЮЧЕВЫЕ ПОКАЗАТЕЛИ", 5, bg=BG["section"]); R += 1
    def kpi_row(label, formula, fmt=PFMT, bg=BG["result"]):
        nonlocal R
        c(ws, R, 1, f"  {label}", bg=bg, bold=True)
        for col in [2,3]: c(ws, R, col, bg=bg)
        c(ws, R, 4, formula, bg=bg, h="right", fmt=fmt, bold=True)
        c(ws, R, 5, bg=bg)
        R += 1

    kpi_row("Себестоимость/мес (среднегод.)",
            f"=D{COST_ROW}", NFMT, BG["sub"])
    kpi_row("Цена контракта/мес (с НДС)",
            f"=D{CONTRACT_ROW}", NFMT, BG["result"])
    kpi_row("Чистая прибыль/мес (до КП-метода)",
            f"=D{CONTRACT_ROW}-D{COST_ROW}-D{NDS_ROW}-D{COST_ROW}*'📋 Параметры'!B28*'📋 Параметры'!B24", NFMT, BG["result"])
    kpi_row("Рентабельность ЧП (от цены без НДС)",
            f"=IFERROR((D{REV_NO_NDS_ROW}-D{COST_ROW})/ D{REV_NO_NDS_ROW},0)", PFMT, BG["result"])
    kpi_row("ФОТ / Выручка",
            f"=IFERROR(D{FOT_TOT_ROW}/D{CONTRACT_ROW},0)", PFMT, BG["sub"])

    ws.freeze_panes = "A4"


# ═══════════════════════════════════════════════════════════════
# ЛИСТ 4: П&Л (PlanFact / Google Sheets стиль)
# ═══════════════════════════════════════════════════════════════
def build_pl(wb: Workbook) -> None:
    ws = wb.create_sheet("💰 П&Л")
    months = ["Янв","Фев","Мар","Апр","Май","Июн","Июл","Авг","Сен","Окт","Ноя","Дек","Итого"]
    W(ws, 1, 38)
    for ci in range(2, len(months)+3):
        W(ws, ci, 13)

    H(ws, 1, 28)
    merge(ws,1,1,len(months)+1,"💰  П&Л — ОТЧЁТ О ПРИБЫЛЯХ И УБЫТКАХ (по месяцам)",
          bg=BG["header"], fg="FFFFFF", bold=True, size=12, h="center")

    R = 3
    # Шапка
    hdr = ["Статья"] + months
    for ci, h in enumerate(hdr, 1):
        c(ws, R, ci, h, bg=BG["header"], fg="FFFFFF", bold=True, h="center", size=9)
    H(ws, R, 22); R += 1

    MCOL = {m: i+2 for i, m in enumerate(months)}

    def pl_row(row_r, label, values_dict, bg_=BG["white"], bold_=False, indent=0, fmt=NFMT, is_pct=False):
        prefix = "  " * indent
        c(ws, row_r, 1, prefix + label, bg=bg_, bold=bold_, size=10)
        for m in months[:-1]:
            v = values_dict.get(m, 0)
            c(ws, row_r, MCOL[m], v if v else None, bg=bg_, h="right", fmt=fmt, bold=bold_)
        # Итого
        cols = ",".join(f"{get_column_letter(MCOL[m])}{row_r}" for m in months[:-1])
        if is_pct:
            c(ws, row_r, MCOL["Итого"], f"=AVERAGE({cols})" if not is_pct else "",
              bg=bg_, h="right", fmt=fmt, bold=bold_)
        else:
            c(ws, row_r, MCOL["Итого"], f"=SUM({cols})", bg=bg_, h="right", fmt=fmt, bold=bold_)

    def formula_row(row_r, label, formula_tmpl, bg_=BG["formula"], bold_=True, fmt=NFMT, bg=None):
        actual_bg = bg if bg is not None else bg_
        c(ws, row_r, 1, label, bg=actual_bg, bold=bold_, size=10)
        for m in months:
            col_l = get_column_letter(MCOL[m])
            formula = formula_tmpl.replace("{C}", col_l)
            c(ws, row_r, MCOL[m], formula, bg=actual_bg, h="right", fmt=fmt, bold=bold_)

    # ── ВЫРУЧКА ──────────────────────────────────────────────────
    section_row(ws, R, "ВЫРУЧКА", len(months)+1, bg=BG["section"]); R += 1

    # Выручка МОП — из Сметы (ввод или формула)
    c(ws, R, 1, "  Контракт МОП (с НДС)", bg=BG["input"], size=10)
    for m in months[:-1]:
        c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
    cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
    c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["input"], h="right", fmt=NFMT)
    rev_mop_row = R; R += 1

    c(ws, R, 1, "  Контракт ПТ (с НДС)", bg=BG["input"], size=10)
    for m in months[:-1]:
        c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
    cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
    c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["input"], h="right", fmt=NFMT)
    rev_pt_row = R; R += 1

    c(ws, R, 1, "  Контракт УДС (с НДС)", bg=BG["input"], size=10)
    for m in months[:-1]:
        c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
    cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
    c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["input"], h="right", fmt=NFMT)
    rev_uds_row = R; R += 1

    c(ws, R, 1, "  Доп. услуги / снег", bg=BG["input"], size=10)
    for m in months[:-1]:
        c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
    cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
    c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["input"], h="right", fmt=NFMT)
    rev_add_row = R; R += 1

    rev_rows = [rev_mop_row, rev_pt_row, rev_uds_row, rev_add_row]
    formula_row(R, "ИТОГО ВЫРУЧКА",
                f"=SUM({','.join(f'{{C}}{r}' for r in rev_rows)})",
                bg=BG["result"], bold_=True)
    REV_ROW = R; R += 1

    # ── ПРЯМЫЕ РАСХОДЫ ────────────────────────────────────────────
    R += 1
    section_row(ws, R, "ПРЯМЫЕ РАСХОДЫ", len(months)+1, bg=BG["section"]); R += 1

    direct_items_pl = [
        ("  ФОТ МОП (с налогами)", BG["mop"]),
        ("  ФОТ ПТ (с налогами)",  BG["pt"]),
        ("  ФОТ УДС (с налогами)", BG["uds"]),
        ("  ФОТ АУП",              BG["aup"]),
        ("  Переменные (материалы, расходники)", BG["white"]),
        ("  Обслуживание техники", BG["white"]),
        ("  Спецодежда / инвентарь", BG["white"]),
        ("  Прочие прямые расходы", BG["white"]),
    ]
    direct_rows_pl = []
    for label, row_bg in direct_items_pl:
        c(ws, R, 1, label, bg=row_bg, size=10)
        for m in months[:-1]:
            c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
        cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
        c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=row_bg, h="right", fmt=NFMT)
        direct_rows_pl.append(R); R += 1

    formula_row(R, "ИТОГО ПРЯМЫЕ РАСХОДЫ",
                f"=SUM({','.join(f'{{C}}{r}' for r in direct_rows_pl)})",
                bg=BG["warn"], bold_=True)
    DIR_ROW = R; R += 1

    # ── ВАЛОВАЯ ПРИБЫЛЬ ───────────────────────────────────────────
    formula_row(R, "ВАЛОВАЯ ПРИБЫЛЬ",
                f"={{C}}{REV_ROW}-{{C}}{DIR_ROW}",
                bg=BG["result"], bold_=True)
    GROSS_ROW = R; R += 1

    c(ws, R, 1, "  Валовая рентабельность", bg=BG["formula"], italic=True)
    for m in months:
        c_l = get_column_letter(MCOL[m])
        c(ws, R, MCOL[m], f"=IFERROR({c_l}{GROSS_ROW}/{c_l}{REV_ROW},0)",
          bg=BG["formula"], h="right", fmt=PFMT, italic=True)
    R += 1

    # ── КОСВЕННЫЕ (БЭК-ОФИС) ─────────────────────────────────────
    R += 1
    section_row(ws, R, "КОСВЕННЫЕ РАСХОДЫ (Бэк-офис + Управление)", len(months)+1, bg=BG["section"]); R += 1
    indirect_items = [
        "  Расходы Бэк-Офис (% от выручки)",
        "  Расходы управления (% от выручки)",
        "  Прочие административные",
    ]
    indirect_rows = []
    for label in indirect_items:
        c(ws, R, 1, label, bg=BG["formula"], size=10)
        for m in months[:-1]:
            c_l = get_column_letter(MCOL[m])
            if "Бэк-Офис" in label:
                c(ws, R, MCOL[m], f"={c_l}{REV_ROW}*'📋 Параметры'!B29",
                  bg=BG["formula"], h="right", fmt=NFMT)
            elif "управления" in label:
                c(ws, R, MCOL[m], f"={c_l}{REV_ROW}*'📋 Параметры'!B30",
                  bg=BG["formula"], h="right", fmt=NFMT)
            else:
                c(ws, R, MCOL[m], 0, bg=BG["input"], h="right", fmt=NFMT)
        cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
        c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["formula"], h="right", fmt=NFMT)
        indirect_rows.append(R); R += 1

    formula_row(R, "ИТОГО КОСВЕННЫЕ",
                f"=SUM({','.join(f'{{C}}{r}' for r in indirect_rows)})",
                bg=BG["section"], bold_=True)
    INDIR_ROW = R; R += 1

    # ── ОПЕРАЦИОННАЯ ПРИБЫЛЬ ─────────────────────────────────────
    formula_row(R, "ОПЕРАЦИОННАЯ ПРИБЫЛЬ",
                f"={{C}}{GROSS_ROW}-{{C}}{INDIR_ROW}",
                bg=BG["result"], bold_=True)
    OP_ROW = R; R += 1

    c(ws, R, 1, "  Операционная рентабельность", bg=BG["formula"], italic=True)
    for m in months:
        c_l = get_column_letter(MCOL[m])
        c(ws, R, MCOL[m], f"=IFERROR({c_l}{OP_ROW}/{c_l}{REV_ROW},0)",
          bg=BG["formula"], h="right", fmt=PFMT, italic=True)
    R += 1

    # ── НАЛОГИ ───────────────────────────────────────────────────
    R += 1
    section_row(ws, R, "НАЛОГИ", len(months)+1, bg=BG["section"]); R += 1
    tax_rows_pl = []
    for label, fml in [
        ("  НДС 5% (от выручки)", f"={{C}}{REV_ROW}*'📋 Параметры'!B18"),
        ("  УСН", f"={{C}}{REV_ROW}*'📋 Параметры'!B19"),
        ("  Амортизация", None),
    ]:
        c(ws, R, 1, label, bg=BG["formula"] if fml else BG["input"], size=10)
        for m in months[:-1]:
            c_l = get_column_letter(MCOL[m])
            ff = fml.replace("{C}", c_l) if fml else None
            c(ws, R, MCOL[m], ff if ff else 0,
              bg=BG["formula"] if fml else BG["input"], h="right", fmt=NFMT)
        cols_tot = ",".join(f"{get_column_letter(MCOL[m])}{R}" for m in months[:-1])
        c(ws, R, MCOL["Итого"], f"=SUM({cols_tot})", bg=BG["formula"], h="right", fmt=NFMT)
        tax_rows_pl.append(R); R += 1

    formula_row(R, "ИТОГО НАЛОГИ",
                f"=SUM({','.join(f'{{C}}{r}' for r in tax_rows_pl)})",
                bg=BG["section"], bold_=True)
    TAX_ROW = R; R += 1

    # ── ЧИСТАЯ ПРИБЫЛЬ ───────────────────────────────────────────
    formula_row(R, "✅ ЧИСТАЯ ПРИБЫЛЬ",
                f"={{C}}{OP_ROW}-{{C}}{TAX_ROW}",
                bg=BG["result"], bold_=True)
    NET_ROW = R; R += 1

    c(ws, R, 1, "  Рентабельность чистой прибыли", bg=BG["formula"], italic=True)
    for m in months:
        c_l = get_column_letter(MCOL[m])
        c(ws, R, MCOL[m], f"=IFERROR({c_l}{NET_ROW}/{c_l}{REV_ROW},0)",
          bg=BG["formula"], h="right", fmt=PFMT, italic=True)
    R += 1

    ws.freeze_panes = "B4"


# ═══════════════════════════════════════════════════════════════
# ЛИСТ 5: ИТОГИ — ДАШБОРД
# ═══════════════════════════════════════════════════════════════
def build_dashboard(wb: Workbook) -> None:
    ws = wb.create_sheet("🎯 Итоги")
    W(ws, 1, 40); W(ws, 2, 22); W(ws, 3, 22); W(ws, 4, 22)

    H(ws, 1, 35)
    merge(ws,1,1,4,"🎯  ИТОГОВЫЙ ДАШБОРД — НОВЫЙ ОБЪЕКТ",
          bg=BG["header"], fg="FFFFFF", bold=True, size=14, h="center")

    R = 3
    c(ws, R, 1, "Объект:", bold=True, bg=BG["sub"])
    c(ws, R, 2, "='📋 Параметры'!B7", bg=BG["formula"])
    c(ws, R, 3, "Город:", bold=True, bg=BG["sub"])
    c(ws, R, 4, "='📋 Параметры'!B8", bg=BG["formula"])
    R += 2

    # ── Блок 1: Контракт ─────────────────────────────────────────
    section_row(ws, R, "📋  ЦЕНА КОНТРАКТА (из Сметы)", 4); R += 1
    kpis_contract = [
        ("Себестоимость/мес (среднегод.)",     "='📊 Смета + КП'!D32", NFMT),
        ("Цена без НДС/мес",                   "='📊 Смета + КП'!D39", NFMT),
        ("НДС 5%/мес",                         "='📊 Смета + КП'!D40", NFMT),
        ("★ Цена контракта с НДС/мес",         "='📊 Смета + КП'!D41", NFMT),
        ("★ Цена контракта с НДС/год",         "='📊 Смета + КП'!D41*12", NFMT),
    ]
    for label, formula, fmt in kpis_contract:
        bold_ = "★" in label
        bg_ = BG["result"] if bold_ else BG["formula"]
        c(ws, R, 1, f"  {label}", bold=bold_, bg=bg_)
        c(ws, R, 2, formula, bg=bg_, h="right", fmt=fmt, bold=bold_)
        c(ws, R, 3, bg=bg_); c(ws, R, 4, bg=bg_)
        R += 1

    R += 1
    # ── Блок 2: П&Л ─────────────────────────────────────────────
    section_row(ws, R, "💰  П&Л (из листа П&Л, ИТОГО за год)", 4); R += 1
    c(ws, R, 1, "", bg=BG["sub"]); c(ws, R, 2, "Лето/мес", bg=BG["mop"], h="center", bold=True)
    c(ws, R, 3, "Зима/мес", bg=BG["pt"], h="center", bold=True)
    c(ws, R, 4, "Год (Итого)", bg=BG["section"], h="center", bold=True)
    H(ws, R, 22); R += 1

    # Ссылки на итоговый столбец П&Л листа (последний столбец = N = 15)
    pl_ref = lambda row: f"='💰 П&Л'!{get_column_letter(15)}{row}"

    pl_kpis = [
        ("Выручка",               5,  BG["result"]),
        ("Прямые расходы",        8,  BG["warn"]),
        ("Валовая прибыль",       9,  BG["result"]),
        ("Косвенные расходы",     13, BG["sub"]),
        ("Операционная прибыль",  14, BG["result"]),
        ("Налоги",                16, BG["sub"]),
        ("✅ Чистая прибыль",      18, BG["result"]),
    ]
    for label, pl_row, bg_ in pl_kpis:
        bold_ = "✅" in label or label in ("Выручка","Валовая прибыль","Операционная прибыль")
        c(ws, R, 1, f"  {label}", bold=bold_, bg=bg_)
        c(ws, R, 2, "", bg=bg_)
        c(ws, R, 3, "", bg=bg_)
        c(ws, R, 4, pl_ref(pl_row+3), bg=bg_, h="right", fmt=NFMT, bold=bold_)
        R += 1

    R += 1
    # ── Блок 3: Рентабельность ───────────────────────────────────
    section_row(ws, R, "📈  РЕНТАБЕЛЬНОСТЬ", 4); R += 1
    rent_kpis = [
        ("Валовая рентабельность",      "=IFERROR('💰 П&Л'!O10/'💰 П&Л'!O5,0)"),
        ("Операционная рентабельность", "=IFERROR('💰 П&Л'!O15/'💰 П&Л'!O5,0)"),
        ("★ Чистая рентабельность",     "=IFERROR('💰 П&Л'!O19/'💰 П&Л'!O5,0)"),
        ("ФОТ / Выручка",               "='📊 Смета + КП'!D46"),
    ]
    for label, formula in rent_kpis:
        bold_ = "★" in label
        bg_ = BG["result"] if bold_ else BG["formula"]
        c(ws, R, 1, f"  {label}", bold=bold_, bg=bg_)
        c(ws, R, 2, formula, bg=bg_, h="right", fmt=PFMT, bold=bold_)
        c(ws, R, 3, bg=bg_); c(ws, R, 4, bg=bg_)
        R += 1

    R += 1
    # ── Блок 4: Сигнальные показатели ────────────────────────────
    section_row(ws, R, "🚦  ПРОВЕРКА ЦЕЛЕЙ", 4); R += 1
    checks = [
        ("Цель рент. ЧП ≥ 20%",
         "='📋 Параметры'!B28",
         "=IFERROR('💰 П&Л'!O19/'💰 П&Л'!O5,0)"),
        ("ФОТ / Выручка ≤ 65%",
         "65%",
         "='📊 Смета + КП'!D46"),
    ]
    for label, target, actual in checks:
        c(ws, R, 1, f"  {label}", bg=BG["sub"])
        c(ws, R, 2, "Цель:", bg=BG["sub"], italic=True)
        c(ws, R, 3, target, bg=BG["input"], h="right", fmt=PFMT, bold=True)
        c(ws, R, 4, actual, bg=BG["result"], h="right", fmt=PFMT, bold=True)
        R += 1

    R += 2
    # ── Инструкция ───────────────────────────────────────────────
    ws.merge_cells(start_row=R, start_column=1, end_row=R+2, end_column=4)
    cc = ws.cell(R, 1)
    cc.value = "⚠️ Примечание: значения из П&Л рассчитываются автоматически только после заполнения всех жёлтых ячеек на листах «📋 Параметры», «👥 Расчёт ФОТ» и «💰 П&Л»."
    cc.font = _f(italic=True, size=9, color="595959")
    cc.alignment = _al("left", wrap=True)
    cc.fill = _fill("FFFDE7")
    cc.border = _border()


# ═══════════════════════════════════════════════════════════════
# СБОРКА КНИГИ
# ═══════════════════════════════════════════════════════════════
def build(output_path: Path) -> None:
    wb = Workbook()
    wb.remove(wb.active)

    build_params(wb)
    build_fot(wb)
    build_estimate(wb)
    build_pl(wb)
    build_dashboard(wb)

    # Порядок листов и цвета вкладок
    tab_colors = {
        "📋 Параметры":  "1F3864",
        "👥 Расчёт ФОТ": "2E75B6",
        "📊 Смета + КП": "ED7D31",
        "💰 П&Л":        "70AD47",
        "🎯 Итоги":      "C00000",
    }
    for ws in wb.worksheets:
        if ws.title in tab_colors:
            ws.sheet_properties.tabColor = tab_colors[ws.title]

    output_path.parent.mkdir(parents=True, exist_ok=True)
    wb.save(output_path)
    print(f"✓ {output_path.name}")


if __name__ == "__main__":
    out = Path("/workspace/budget/output/Калькулятор бюджета ЖК.xlsx")
    build(out)
    print("\nГотово!")
