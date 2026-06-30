#!/usr/bin/env python3
"""Финансовая модель Астрид: ФОТ-калькулятор + помесячный P&L со сценариями."""

from __future__ import annotations
from pathlib import Path
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

# ══════════════════════════════════════════════════════════════
# СТИЛИ
# ══════════════════════════════════════════════════════════════
def _f(color="000000", bold=False, size=10, italic=False):
    return Font(color=color, bold=bold, size=size, italic=italic)

def _fill(hex6):
    return PatternFill("solid", fgColor=hex6)

def _al(h="left", v="center", wrap=False):
    return Alignment(horizontal=h, vertical=v, wrap_text=wrap)

def _border(light="BFBFBF", thick=None):
    s = Side(style="thin", color=light)
    t = Side(style="medium", color=thick) if thick else s
    return Border(left=s, right=s, top=s, bottom=t)

BG = {
    "header":   "1F3864",
    "section":  "D6E4F0",
    "fot":      "FFF2CC",   # жёлтый — ФОТ (input)
    "input":    "EBF3FB",   # голубой — прочие input
    "result":   "E2EFDA",   # зелёный — итоги
    "loss":     "FCE4D6",   # оранжевый — убыток
    "calc":     "F2F2F2",   # серый — расчётные формулы
    "scenario": "D5E8D4",   # насыщенный зелёный — сценарий
    "white":    "FFFFFF",
    "neutral":  "F5F5F5",
}

COL_MONTHS = [2, 3, 4, 5]   # B C D E  — июнь июль август сентябрь
COL_TOTAL  = 6               # F — Итого
COL_SCEN   = 7               # G — Сценарий
MONTHS     = ["Июнь", "Июль", "Август", "Сентябрь"]


def cell(ws, r, c, v=None, bg=None, fg="000000", bold=False, fmt=None,
         h="left", size=10, italic=False, border=True, thick_bottom=False):
    cc = ws.cell(r, c, v)
    cc.font = _f(fg, bold, size, italic)
    if bg:
        cc.fill = _fill(bg)
    cc.alignment = _al(h=h)
    if fmt:
        cc.number_format = fmt
    cc.border = _border(thick="1F3864") if thick_bottom else (_border() if border else Border())
    return cc


def merge_title(ws, r, text, c1, c2, bg="1F3864", fg="FFFFFF"):
    ws.merge_cells(start_row=r, start_column=c1, end_row=r, end_column=c2)
    cc = ws.cell(r, c1, text)
    cc.font = _f(fg, bold=True, size=11)
    cc.fill = _fill(bg)
    cc.alignment = _al("center")
    cc.border = _border()


# ══════════════════════════════════════════════════════════════
# ИСХОДНЫЕ ДАННЫЕ (из бюджета Астрид, июнь–сентябрь)
# ══════════════════════════════════════════════════════════════
MONTHLY_REV = 653_961   # новая ежемесячная ставка

FOT = {
    "zp_mop":  [209_689, 210_030, 209_689, 210_030],
    "zp_pt":   [294_494, 258_000, 294_494, 258_000],
    "nal_mop": [ 19_188,  64_800,  19_188,  42_000],
    "nal_pt":  [ 13_381,   5_013,  13_381,       0],
    "zp_aup":  [ 60_000,  60_000,  60_000,  60_000],
    "nal_ip":  [ 26_500,  36_960,  26_500,  36_960],
}

OTHER = {
    "mat_mop": [10_588, 21_588, 10_588, 10_000],
    "mat_pt":  [     0,  5_000,      0,  5_000],
    "remont":  [ 1_019,      0,  1_019,      0],
    "autsors": [10_000,  7_500, 10_000,  2_500],
    "gsm":     [ 2_000,  1_000,  2_000,  1_000],
    "kom_zp":  [ 1_000,      0,  1_000,      0],
}

BEK  = [55_821, 84_919, 55_821, 84_919]
TAX  = {
    "usn": [    0, 10_000,     0, 10_000],
    "nds": [38_679, 31_141, 38_679, 31_141],
}

TARGET_MARGIN = 0.20
NDS_RATE = 5 / 105   # ~4.762%


# ══════════════════════════════════════════════════════════════
# РАСЧЁТ СЦЕНАРИЯ (Python, для вывода в блок итогов)
# ══════════════════════════════════════════════════════════════
def scenario_values(fot_growth: float):
    """Возвращает требуемую выручку для 20% ЧП при данном росте ФОТ."""
    rev_base   = MONTHLY_REV * 4
    fot_base   = sum(sum(v) for v in FOT.values())
    other_base = sum(sum(v) for v in OTHER.values())
    bek_base   = sum(BEK)
    usn_base   = sum(TAX["usn"])
    fot_new    = fot_base * (1 + fot_growth)
    direct_new = fot_new + other_base
    fixed      = direct_new + bek_base + usn_base
    denom      = 1 - NDS_RATE - TARGET_MARGIN
    new_rev    = fixed / denom if denom > 0 else 0
    delta_rev  = new_rev - rev_base
    nds_new    = new_rev * NDS_RATE
    net_new    = new_rev - direct_new - bek_base - usn_base - nds_new
    return {
        "rev_base": rev_base,
        "new_rev":  new_rev,
        "delta_rev": delta_rev,
        "fot_base": fot_base,
        "fot_new":  fot_new,
        "net_new":  net_new,
        "net_pct":  net_new / new_rev if new_rev else 0,
        "monthly_new": new_rev / 4,
    }


# ══════════════════════════════════════════════════════════════
# ЛИСТ 1: РАСЧЁТ ФОТ
# ══════════════════════════════════════════════════════════════
def build_fot_sheet(wb: Workbook) -> None:
    ws = wb.create_sheet("Расчёт ФОТ", 0)
    ws.column_dimensions["A"].width = 18
    ws.column_dimensions["B"].width = 22
    ws.column_dimensions["C"].width = 10
    ws.column_dimensions["D"].width = 16
    ws.column_dimensions["E"].width = 16
    ws.column_dimensions["F"].width = 14
    ws.column_dimensions["G"].width = 12
    ws.column_dimensions["H"].width = 14
    ws.column_dimensions["I"].width = 18

    # ── Заголовок ──
    ws.row_dimensions[1].height = 28
    merge_title(ws, 1, "ЖК Астрид — Расчёт ФОТ (ежемесячный)", 1, 9)

    # ── Ставки ──
    r = 3
    merge_title(ws, r, "⚙️  Параметры расчёта", 1, 4, bg="2E4057", fg="FFFFFF")
    r += 1
    for label, val, fmt in [
        ("НДФЛ",               0.13, "0%"),
        ("Страх. взносы (осн.)", 0.30, "0%"),
        ("Страх. взносы (льгот.)", 0.15, "0%"),
    ]:
        cell(ws, r, 1, label, bg=BG["neutral"], bold=True, size=10)
        cell(ws, r, 2, val,   bg=BG["fot"],     bold=True, h="center", fmt=fmt, size=10)
        for c in range(3, 10):
            cell(ws, r, c, bg=BG["neutral"])
        r += 1

    NDFL_CELL = "B4"       # ссылка на ставку НДФЛ
    SV_CELL   = "B5"       # ставка СВ (основная)

    # ── Таблица сотрудников ──
    r += 1
    heads = ["Категория", "Должность", "Кол-во", "ЗП на руки\n(1 чел.)",
             "Начислено\n(1 чел.)", "НДФЛ\n(1 чел.)",
             "Ставка СВ", "Страх.взносы\n(1 чел.)", "Расход/мес\n(все)"]
    for ci, h in enumerate(heads, 1):
        cell(ws, r, ci, h, bg=BG["header"], fg="FFFFFF", bold=True,
             h="center", size=9, border=True)
        ws.row_dimensions[r].height = 30
    TABLE_HEAD_ROW = r
    r += 1
    TABLE_START = r

    # Pre-populated employee rows (estimates based on Астрид ФОТ data)
    employees = [
        ("МОП", "Уборщица",  5, 37_000),
        ("МОП", "Уборщица (доп.)",  0, 0),
        ("ПТ",  "Дворник",   3, 65_000),
        ("ПТ",  "Дворник (доп.)",  0, 0),
        ("АУП", "Менеджер объекта", 1, 52_200),
        ("",    "",           0, 0),
        ("",    "",           0, 0),
        ("",    "",           0, 0),
    ]

    data_rows = []
    for cat, role, count, net_salary in employees:
        bg_row = BG["fot"] if cat == "МОП" else (BG["input"] if cat == "ПТ" else BG["neutral"])
        cell(ws, r, 1, cat,  bg=bg_row, bold=(cat!=""))
        cell(ws, r, 2, role, bg=bg_row)
        cell(ws, r, 3, count if count > 0 else None, bg=BG["fot"], h="center", fmt="#,##0")
        cell(ws, r, 4, net_salary if net_salary > 0 else None,
             bg=BG["fot"], h="right", fmt="#,##0 ₽")

        gross_f = f"=IF(D{r}=0,0,D{r}/(1-{NDFL_CELL}))"
        ndfl_f  = f"=IF(E{r}=0,0,E{r}*{NDFL_CELL})"
        sv_rate = f"={SV_CELL}"
        sv_f    = f"=IF(E{r}=0,0,E{r}*G{r})"
        total_f = f"=IF(C{r}=0,0,(E{r}+H{r})*C{r})"

        cell(ws, r, 5, gross_f, bg=BG["calc"], h="right", fmt="#,##0 ₽")
        cell(ws, r, 6, ndfl_f,  bg=BG["calc"], h="right", fmt="#,##0 ₽")
        cell(ws, r, 7, sv_rate, bg=BG["input"], h="center", fmt="0%")
        cell(ws, r, 8, sv_f,    bg=BG["calc"], h="right", fmt="#,##0 ₽")
        cell(ws, r, 9, total_f, bg=BG["result"], h="right", fmt="#,##0 ₽", bold=True)
        data_rows.append(r)
        r += 1

    TABLE_END = r - 1

    # ── Итого по таблице ──
    r += 1
    merge_title(ws, r, "ИТОГО ЕЖЕМЕСЯЧНЫЙ ФОТ", 1, 2, bg="2E4057", fg="FFFFFF")
    totals = [
        ("ЗП к выплате (net)",           f"=SUMPRODUCT(C{TABLE_START}:C{TABLE_END},D{TABLE_START}:D{TABLE_END})"),
        ("ЗП начисленная (gross)",        f"=SUMPRODUCT(C{TABLE_START}:C{TABLE_END},E{TABLE_START}:E{TABLE_END})"),
        ("НДФЛ итого",                   f"=SUMPRODUCT(C{TABLE_START}:C{TABLE_END},F{TABLE_START}:F{TABLE_END})"),
        ("Страх.взносы итого",           f"=SUMPRODUCT(C{TABLE_START}:C{TABLE_END},H{TABLE_START}:H{TABLE_END})"),
        ("✅ ИТОГО РАСХОД РАБОТОДАТЕЛЯ", f"=SUMIF(I{TABLE_START}:I{TABLE_END},\">0\")"),
    ]
    r += 1
    for label, formula in totals:
        is_total = "✅" in label
        cell(ws, r, 1, label,   bg=BG["result"] if is_total else BG["neutral"],
             bold=is_total, size=10)
        cell(ws, r, 2, formula, bg=BG["result"] if is_total else BG["calc"],
             bold=is_total, h="right", fmt="#,##0 ₽", size=10)
        for c in range(3, 10):
            cell(ws, r, c, bg=BG["neutral"] if not is_total else BG["result"])
        r += 1

    # ── Инструкция ──
    r += 1
    note = ("ℹ️  Инструкция: заполните жёлтые ячейки (Кол-во и ЗП на руки). "
            "Значения «Расход/мес» автоматически пересчитаются. "
            "Перенесите ЗП начисленную и Страх.взносы в строки ФОТ листа P&L.")
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=9)
    cc = ws.cell(r, 1, note)
    cc.font = _f("595959", italic=True, size=9)
    cc.alignment = _al("left", wrap=True)
    cc.fill = _fill("FFFBE6")
    ws.row_dimensions[r].height = 40

    ws.freeze_panes = "A9"


# ══════════════════════════════════════════════════════════════
# ЛИСТ P&L
# ══════════════════════════════════════════════════════════════
class PL:
    """Построитель P&L листа с отслеживанием Excel-строк."""

    def __init__(self, ws, fot_growth: float = 0.0):
        self.ws   = ws
        self.fot_growth = fot_growth
        self.r    = 1       # текущая строка
        self.rows = {}      # key → excel_row
        ws.column_dimensions["A"].width = 38
        ws.column_dimensions[get_column_letter(COL_TOTAL)].width = 15
        ws.column_dimensions[get_column_letter(COL_SCEN)].width = 17
        for ci, month in enumerate(MONTHS, 2):
            ws.column_dimensions[get_column_letter(ci)].width = 14

    # ── helpers ──
    def _col_refs(self, keys: list[str], col: int) -> str:
        return ",".join(f"{get_column_letter(col)}{self.rows[k]}" for k in keys if k in self.rows)

    def _sum_f(self, keys: list[str], col: int) -> str:
        refs = self._col_refs(keys, col)
        return f"=SUM({refs})" if refs else "=0"

    def _write(self, r, c, v, bg=None, bold=False, fmt=None, h="left",
               fg="000000", size=10, italic=False):
        cell(self.ws, r, c, v, bg=bg, bold=bold, fmt=fmt, h=h,
             fg=fg, size=size, italic=italic)

    # ── строки ──
    def title(self, text):
        self.ws.row_dimensions[self.r].height = 26
        merge_title(self.ws, self.r, text, 1, COL_SCEN)
        self.r += 1

    def header(self):
        hdrs = ["Статья"] + MONTHS + ["Итого", "Сценарий"]
        for ci, h in enumerate(hdrs, 1):
            bg = BG["header"] if ci == 1 else ("2E4057" if ci == COL_SCEN else BG["header"])
            cell(self.ws, self.r, ci, h, bg=bg, fg="FFFFFF", bold=True, h="center", size=10)
        self.r += 1

    def section(self, text, span=True):
        """Заголовок раздела."""
        if span:
            self.ws.merge_cells(start_row=self.r, start_column=1,
                                end_row=self.r, end_column=COL_SCEN)
        self._write(self.r, 1, text, bg=BG["section"], bold=True, size=10)
        for c in range(2, COL_SCEN + 1):
            self.ws.cell(self.r, c).fill = _fill(BG["section"])
            self.ws.cell(self.r, c).border = _border()
        self.r += 1

    def subsection(self, text):
        self.ws.merge_cells(start_row=self.r, start_column=1,
                            end_row=self.r, end_column=COL_SCEN)
        self._write(self.r, 1, "  " + text, bg="E8F0FE", bold=True, size=9, italic=True)
        for c in range(2, COL_SCEN + 1):
            self.ws.cell(self.r, c).fill = _fill("E8F0FE")
            self.ws.cell(self.r, c).border = _border()
        self.r += 1

    def leaf(self, key: str, label: str, values: list,
             bg=BG["white"], indent=0, fmt="#,##0", fot=False,
             scen_values=None):
        """Строка с данными (листовая). Значения — числа по месяцам."""
        self.rows[key] = self.r
        prefix = "  " * indent
        col_bg = BG["fot"] if fot else bg
        self._write(self.r, 1, prefix + label, bg=col_bg, size=10)
        for ci, (col, v) in enumerate(zip(COL_MONTHS, values)):
            self._write(self.r, col, v if v else None, bg=col_bg, fmt=fmt, h="right")
        # Итого = SUM(B:E)
        refs = ",".join(f"{get_column_letter(c)}{self.r}" for c in COL_MONTHS)
        self._write(self.r, COL_TOTAL, f"=SUM({refs})", bg=col_bg, fmt=fmt, h="right", bold=True)
        # Сценарий
        sv = scen_values or values
        refs_scen = ",".join(str(v) for v in sv)
        scen_total = sum(sv)
        self._write(self.r, COL_SCEN, scen_total if scen_total else None,
                    bg=BG["scenario"] if scen_total != sum(values) else BG["neutral"],
                    fmt=fmt, h="right", bold=(scen_total != sum(values)))
        self.r += 1

    def parent(self, key: str, label: str, children: list[str],
               bg=BG["result"], indent=0, fmt="#,##0", bold=True,
               scen_children=None):
        """Родительская строка — SUM дочерних по каждой колонке."""
        self.rows[key] = self.r
        prefix = "  " * indent
        self._write(self.r, 1, prefix + label, bg=bg, bold=bold, size=10)
        for col in COL_MONTHS:
            self._write(self.r, col, self._sum_f(children, col),
                        bg=bg, fmt=fmt, h="right", bold=bold)
        # Итого = SUM(B:E) через формулу от дочерних
        self._write(self.r, COL_TOTAL, self._sum_f(children, COL_TOTAL),
                    bg=bg, fmt=fmt, h="right", bold=bold)
        # Сценарий
        sc = scen_children or children
        self._write(self.r, COL_SCEN, self._sum_f(sc, COL_SCEN),
                    bg=BG["scenario"] if sc != children else BG["neutral"],
                    fmt=fmt, h="right", bold=bold)
        self.r += 1

    def calc(self, key: str, label: str, a_key: str, op: str, b_key: str,
             bg=BG["calc"], fmt="#,##0", bold=True, indent=0):
        """Расчётная строка: a_row ± b_row."""
        self.rows[key] = self.r
        ra = self.rows[a_key]
        rb = self.rows[b_key]
        prefix = "  " * indent
        self._write(self.r, 1, prefix + label, bg=bg, bold=bold, size=10)
        for col in list(COL_MONTHS) + [COL_TOTAL]:
            cl = get_column_letter(col)
            self._write(self.r, col, f"={cl}{ra}{op}{cl}{rb}",
                        bg=bg, fmt=fmt, h="right", bold=bold)
        # Сценарий
        cl_t = get_column_letter(COL_SCEN)
        self._write(self.r, COL_SCEN,
                    f"={cl_t}{ra}{op}{cl_t}{rb}",
                    bg=BG["scenario"], fmt=fmt, h="right", bold=bold)
        self.r += 1

    def pct_row(self, key: str, label: str, num_key: str, den_key: str, indent=0):
        """Рентабельность = num / den."""
        self.rows[key] = self.r
        rn, rd = self.rows[num_key], self.rows[den_key]
        prefix = "  " * indent
        self._write(self.r, 1, prefix + label, bg=BG["calc"], italic=True, size=9)
        for col in list(COL_MONTHS) + [COL_TOTAL]:
            cl = get_column_letter(col)
            f = f"=IFERROR({cl}{rn}/{cl}{rd},\"-\")"
            self._write(self.r, col, f, bg=BG["calc"], fmt="0.0%", h="right", italic=True)
        cl = get_column_letter(COL_SCEN)
        self._write(self.r, COL_SCEN,
                    f"=IFERROR({cl}{rn}/{cl}{rd},\"-\")",
                    bg=BG["scenario"], fmt="0.0%", h="right", italic=True)
        self.r += 1

    def blank(self):
        for c in range(1, COL_SCEN + 1):
            self.ws.cell(self.r, c).fill = _fill("FAFAFA")
        self.r += 1

    def results_block(self, sc: dict):
        """Блок итогов сценария."""
        self.blank()
        merge_title(self.ws, self.r, "💡  РЕЗУЛЬТАТ СЦЕНАРИЯ", 1, COL_SCEN,
                    bg="2E4057", fg="FFFFFF")
        self.r += 1
        rows = [
            ("Текущая выручка (4 мес.)",            f"{sc['rev_base']:,.0f} ₽",          False),
            (f"Прирост ФОТ ({self.fot_growth*100:.0f}%)",
             f"+{sc['fot_new']-sc['fot_base']:,.0f} ₽" if self.fot_growth else "—",     False),
            ("Цель: рентабельность ЧП",             f"{TARGET_MARGIN*100:.0f}%",           False),
            ("─" * 35,                              "",                                    False),
            ("Требуемая выручка (новый контракт)",  f"{sc['new_rev']:,.0f} ₽",             True),
            ("Прирост выручки",                     f"{sc['delta_rev']:+,.0f} ₽",          True),
            ("Прирост, %",                          f"{sc['delta_rev']/sc['rev_base']*100:+.1f}%", True),
            ("─" * 35,                              "",                                    False),
            ("Новая месячная ставка контракта",     f"{sc['monthly_new']:,.0f} ₽/мес",    True),
            ("Текущая месячная ставка",             f"{MONTHLY_REV:,.0f} ₽/мес",           False),
            ("Прирост мес. ставки",                 f"{sc['monthly_new']-MONTHLY_REV:+,.0f} ₽/мес", True),
            ("─" * 35,                              "",                                    False),
            ("Чистая прибыль (сценарий)",           f"{sc['net_new']:,.0f} ₽",             True),
            ("Рентабельность ЧП (сценарий)",        f"{sc['net_pct']*100:.1f}%",            True),
        ]
        for label, val, highlight in rows:
            bg_l = BG["result"] if highlight else BG["neutral"]
            self._write(self.r, 1, label, bg=bg_l, bold=highlight, size=10)
            self._write(self.r, 2, val,   bg=bg_l, bold=highlight, size=10, h="right")
            for c in range(3, COL_SCEN + 1):
                self.ws.cell(self.r, c).fill = _fill(bg_l)
                self.ws.cell(self.r, c).border = _border()
            self.r += 1


# ══════════════════════════════════════════════════════════════
# ПОСТРОЕНИЕ P&L ЛИСТА
# ══════════════════════════════════════════════════════════════
def build_pl_sheet(wb: Workbook, sheet_name: str, jk: str,
                   scenario_label: str, fot_growth: float) -> None:
    ws = wb.create_sheet(sheet_name)
    pl = PL(ws, fot_growth)
    sc = scenario_values(fot_growth)

    # Сценарные значения ФОТ (масштаб для G-колонки)
    def fot_scen(base_list):
        return [round(v * (1 + fot_growth)) for v in base_list]

    # Новая ежемесячная ставка для сценарного столбца G
    scen_rev_monthly = round(sc["monthly_new"])

    # ── Шапка ──
    pl.title(f"ЖК {jk}  |  {scenario_label}")
    # Подзаголовок G-колонки (поверх merged ячейки — пишем в строку 2 после header)
    pass  # будет написан после header
    pl.header()
    # Подзаголовок сценарной колонки
    scen_label = "Текущий" if fot_growth == 0 else f"Сценарий ФОТ +{fot_growth*100:.0f}%"
    cell(ws, pl.r - 1, COL_SCEN, f"{scen_label} → ЧП 20%",
         bg="155724", fg="FFFFFF", bold=True, h="center", size=9)
    pl.blank()

    # ── ВЫРУЧКА ──
    pl.section("📈  ВЫРУЧКА")
    pl.leaf("rev_od", "Реализация услуг Клининг (ОД)",
            [MONTHLY_REV] * 4, bg=BG["input"], indent=1,
            scen_values=[scen_rev_monthly] * 4)
    pl.parent("rev_total", "ИТОГО ВЫРУЧКА", ["rev_od"],
              bg=BG["result"])
    pl.blank()

    # ── ПРЯМЫЕ РАСХОДЫ ──
    pl.section("💸  ПРЯМЫЕ РАСХОДЫ")

    # Переменные
    pl.subsection("Переменные расходы")
    pl.leaf("mat_mop", "Расходные материалы МОП", OTHER["mat_mop"], indent=2,
            scen_values=OTHER["mat_mop"])
    pl.leaf("mat_pt",  "Расходные материалы ПТ",  OTHER["mat_pt"],  indent=2,
            scen_values=OTHER["mat_pt"])
    pl.parent("peremen", "Итого переменные", ["mat_mop", "mat_pt"],
              bg=BG["calc"], indent=1, bold=False)

    # ФОТ прямой
    pl.subsection("ФОТ прямой (МОП + ПТ)")
    pl.leaf("zp_mop",  "Заработная плата МОП", FOT["zp_mop"],
            fot=True, indent=2, scen_values=fot_scen(FOT["zp_mop"]))
    pl.leaf("zp_pt",   "Заработная плата ПТ",  FOT["zp_pt"],
            fot=True, indent=2, scen_values=fot_scen(FOT["zp_pt"]))
    pl.leaf("nal_mop", "Налоги с ФОТ (МОП)",   FOT["nal_mop"],
            fot=True, indent=2, scen_values=fot_scen(FOT["nal_mop"]))
    pl.leaf("nal_pt",  "Налоги с ФОТ (ПТ)",    FOT["nal_pt"],
            fot=True, indent=2, scen_values=fot_scen(FOT["nal_pt"]))
    pl.parent("fot_direct", "Итого ФОТ прямой",
              ["zp_mop", "zp_pt", "nal_mop", "nal_pt"],
              bg=BG["fot"], indent=1)

    # Прочие прямые
    pl.leaf("remont", "Ремонт оборудования (ПТ)", OTHER["remont"],
            indent=2, scen_values=OTHER["remont"])

    # ФОТ АУП / Общехозяйственные
    pl.subsection("ФОТ АУП + Общехозяйственные")
    pl.leaf("zp_aup",  "Заработная плата МО АУП", FOT["zp_aup"],
            fot=True, indent=2, scen_values=fot_scen(FOT["zp_aup"]))
    pl.leaf("autsors", "Аутсорс (направления)",   OTHER["autsors"],
            indent=2, scen_values=OTHER["autsors"])
    pl.leaf("gsm",     "Инструменты: ГСМ",         OTHER["gsm"],
            indent=2, scen_values=OTHER["gsm"])
    pl.leaf("nal_ip",  "Налоги с ФОТ (ИП)",        FOT["nal_ip"],
            fot=True, indent=2, scen_values=fot_scen(FOT["nal_ip"]))
    pl.leaf("kom_zp",  "Комиссия за перевод ЗП",   OTHER["kom_zp"],
            indent=2, scen_values=OTHER["kom_zp"])
    pl.parent("obshehhoz", "Итого ФОТ АУП + Общехоз",
              ["zp_aup", "autsors", "gsm", "nal_ip", "kom_zp"],
              bg=BG["fot"], indent=1)

    all_direct = ["peremen", "fot_direct", "remont", "obshehhoz"]
    pl.parent("direct_total", "ИТОГО ПРЯМЫЕ РАСХОДЫ", all_direct,
              bg=BG["loss"])
    pl.blank()

    # ── ВАЛОВАЯ ПРИБЫЛЬ ──
    pl.calc("gross", "ВАЛОВАЯ ПРИБЫЛЬ", "rev_total", "-", "direct_total",
            bg=BG["result"])
    pl.pct_row("gross_pct", "Валовая рентабельность", "gross", "rev_total", indent=1)
    pl.blank()

    # ── КОСВЕННЫЕ ──
    pl.section("🏢  КОСВЕННЫЕ РАСХОДЫ (БЭК-ОФИС)")
    pl.leaf("bek", "Расход Бэк-Офис", BEK, indent=1,
            scen_values=BEK)
    pl.parent("indirect", "ИТОГО КОСВЕННЫЕ", ["bek"])
    pl.blank()

    # ── ОПЕРАЦИОННАЯ ПРИБЫЛЬ ──
    pl.calc("op", "ОПЕРАЦИОННАЯ ПРИБЫЛЬ", "gross", "-", "indirect",
            bg=BG["result"])
    pl.pct_row("op_pct", "Операционная рентабельность", "op", "rev_total", indent=1)
    pl.blank()

    # ── НАЛОГИ ──
    pl.section("📋  НАЛОГИ")
    pl.leaf("usn", "Налоги УСН", TAX["usn"], indent=1, scen_values=TAX["usn"])
    pl.leaf("nds", "НДС 5%",    TAX["nds"], indent=1,
            scen_values=[round(scen_rev_monthly * NDS_RATE)] * 4)
    pl.parent("tax", "ИТОГО НАЛОГИ", ["usn", "nds"])
    pl.blank()

    # ── ЧИСТАЯ ПРИБЫЛЬ ──
    pl.calc("net", "✅ ЧИСТАЯ ПРИБЫЛЬ", "op", "-", "tax",
            bg=BG["result"])
    pl.pct_row("net_pct", "Рентабельность чистой прибыли", "net", "rev_total", indent=1)

    # ── Блок итогов ──
    pl.results_block(sc)

    ws.freeze_panes = f"B3"


# ══════════════════════════════════════════════════════════════
# ОСНОВНАЯ ФУНКЦИЯ
# ══════════════════════════════════════════════════════════════
def build_astrid_model(output_path: Path) -> None:
    wb = Workbook()
    wb.remove(wb.active)

    build_fot_sheet(wb)
    build_pl_sheet(wb, "Текущий бюджет",  "Астрид",
                   "Текущее состояние (выручка 653 961 ₽/мес)", 0.0)
    build_pl_sheet(wb, "+30% ФОТ → 20%", "Астрид",
                   "Сценарий: ФОТ +30%  →  цель ЧП 20%", 0.3)
    build_pl_sheet(wb, "+40% ФОТ → 20%", "Астрид",
                   "Сценарий: ФОТ +40%  →  цель ЧП 20%", 0.4)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    wb.save(output_path)
    print(f"✓ {output_path.name}")


if __name__ == "__main__":
    out = Path("/workspace/budget/output/Фин.модель — Астрид v2.xlsx")
    build_astrid_model(out)

    print("\n── Проверка расчётов ──")
    for rate in [0.0, 0.3, 0.4]:
        sc = scenario_values(rate)
        label = f"ФОТ +{rate*100:.0f}%" if rate > 0 else "Текущий"
        print(f"\n  [{label}]")
        print(f"    Текущая выручка:  {sc['rev_base']:>12,.0f} ₽ ({MONTHLY_REV:,.0f} ₽/мес)")
        print(f"    Нужная выручка:   {sc['new_rev']:>12,.0f} ₽ ({sc['monthly_new']:,.0f} ₽/мес)")
        print(f"    Прирост контракта:{sc['delta_rev']:>+12,.0f} ₽ ({sc['delta_rev']/sc['rev_base']*100:+.1f}%)")
        print(f"    Чистая прибыль:   {sc['net_new']:>12,.0f} ₽ ({sc['net_pct']*100:.1f}%)")
