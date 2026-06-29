#!/usr/bin/env python3
"""Финансовые модели: сценарии роста ФОТ и целевой рентабельности."""

from __future__ import annotations
from pathlib import Path
from openpyxl import Workbook
from openpyxl.styles import (
    Font, PatternFill, Alignment, Border, Side, numbers
)
from openpyxl.utils import get_column_letter

# ──────────────────────────────────────────────────────────────
# Цветовая палитра
# ──────────────────────────────────────────────────────────────
C_HEADER_BG   = "1F3864"   # тёмно-синий
C_HEADER_FG   = "FFFFFF"
C_SECTION_BG  = "D6E4F0"   # светло-голубой
C_FOT_BG      = "FFF2CC"   # жёлтый — ФОТ
C_RESULT_BG   = "E2EFDA"   # зелёный — итог
C_WARN_BG     = "FCE4D6"   # оранжевый — убыток
C_SCENARIO_BG = "EBF3FB"   # очень светло-голубой — сценарий
C_HIGHLIGHT   = "FF0000"   # красный

NUM_FMT   = '#,##0'
PCT_FMT   = '0.0%'
MONEY_FMT = '# ##0 ₽'

def fill(hex_color: str) -> PatternFill:
    return PatternFill("solid", fgColor=hex_color)

def font(bold=False, color="000000", size=11, italic=False) -> Font:
    return Font(bold=bold, color=color, size=size, italic=italic)

def align(h="left", v="center", wrap=False) -> Alignment:
    return Alignment(horizontal=h, vertical=v, wrap_text=wrap)

def thin_border() -> Border:
    s = Side(style="thin", color="BFBFBF")
    return Border(left=s, right=s, top=s, bottom=s)

def thick_bottom() -> Border:
    t = Side(style="medium", color="1F3864")
    n = Side(style="thin", color="BFBFBF")
    return Border(left=n, right=n, top=n, bottom=t)

def set_cell(ws, row, col, value, bold=False, bg=None, fg="000000",
             fmt=None, align_h="left", size=11, border=True, italic=False):
    c = ws.cell(row, col, value)
    c.font = font(bold=bold, color=fg, size=size, italic=italic)
    if bg:
        c.fill = fill(bg)
    c.alignment = align(h=align_h, v="center")
    if fmt:
        c.number_format = fmt
    if border:
        c.border = thin_border()
    return c

def header_row(ws, row, texts: list, widths=None):
    for i, t in enumerate(texts, 1):
        c = set_cell(ws, row, i, t, bold=True, bg=C_HEADER_BG, fg=C_HEADER_FG,
                     align_h="center", size=10)
    if widths:
        for i, w in enumerate(widths, 1):
            ws.column_dimensions[get_column_letter(i)].width = w

def section_row(ws, row, label, ncols=8):
    set_cell(ws, row, 1, label, bold=True, bg=C_SECTION_BG, size=10)
    for c in range(2, ncols+1):
        ws.cell(row, c).fill = fill(C_SECTION_BG)
        ws.cell(row, c).border = thin_border()

def data_row(ws, row, label, values: list, bg=None, bold=False,
             fmt=NUM_FMT, indent=0, italic=False):
    label_text = "  " * indent + label
    set_cell(ws, row, 1, label_text, bold=bold, bg=bg or "FFFFFF", size=10, italic=italic)
    for i, v in enumerate(values, 2):
        set_cell(ws, row, i, v, bold=bold, bg=bg or "FFFFFF",
                 fmt=fmt, align_h="right", size=10, italic=italic)

def result_row(ws, row, label, values, bg=C_RESULT_BG, fmt=NUM_FMT):
    data_row(ws, row, label, values, bg=bg, bold=True, fmt=fmt)

def merged_header(ws, row, text, col_from, col_to, bg=C_HEADER_BG, fg=C_HEADER_FG):
    ws.merge_cells(start_row=row, start_column=col_from,
                   end_row=row, end_column=col_to)
    c = ws.cell(row, col_from, text)
    c.font = font(bold=True, color=fg, size=11)
    c.fill = fill(bg)
    c.alignment = align(h="center", v="center")
    c.border = thin_border()

# ──────────────────────────────────────────────────────────────
# Данные для каждого ЖК (4 месяца: июнь–сентябрь, без ноября)
# ──────────────────────────────────────────────────────────────

MONTHS = ["Июнь", "Июль", "Август", "Сентябрь"]

DATA = {
    "Астрид": {
        "rev_od":    [228_371, 653_961, 228_371, 653_961],
        "rev_add":   [0,       470_000, 0,       0      ],
        "rev_snow":  [0,       0,       0,       0      ],
        "mat_mop":   [10_588,  21_588,  10_588,  10_000 ],
        "mat_pt":    [0,       5_000,   0,       5_000  ],
        "zp_mop":    [209_689, 210_030, 209_689, 210_030],
        "zp_pt":     [294_494, 258_000, 294_494, 258_000],
        "nal_mop":   [19_188,  64_800,  19_188,  42_000 ],
        "nal_pt":    [13_381,  5_013,   13_381,  0      ],
        "remont":    [1_019,   0,       1_019,   0      ],
        "autsors":   [10_000,  7_500,   10_000,  2_500  ],
        "zp_aup":    [60_000,  60_000,  60_000,  60_000 ],
        "gsm":       [2_000,   1_000,   2_000,   1_000  ],
        "nal_ip":    [26_500,  36_960,  26_500,  36_960 ],
        "kom_zp":    [1_000,   0,       1_000,   0      ],
        "bek":       [55_821,  84_919,  55_821,  84_919 ],
        "usn":       [0,       10_000,  0,       10_000 ],
        "nds":       [38_679,  31_141,  38_679,  31_141 ],
    },
    "Новое Колпино": {
        "rev_od":    [2_759_092, 2_472_044, 2_472_044, 2_472_044],
        "rev_add":   [0, 0, 0, 0],
        "rev_snow":  [0, 0, 0, 0],
        "mat_mop":   [43_810,  30_000,  30_000,  30_000 ],
        "mat_pt":    [5_901,   60_000,  10_000,  10_000 ],
        "sadov":     [88_000,  88_000,  88_000,  88_000 ],
        "arena":     [0,       30_000,  0,       0      ],
        "zp_mop":    [685_000, 685_000, 685_000, 685_000],
        "zp_pt":     [958_085, 920_000, 920_000, 920_000],
        "nal_mop":   [28_000,  28_000,  28_000,  28_000 ],
        "nal_pt":    [143_705, 143_705, 143_705, 143_705],
        "remont":    [3_000,   3_000,   3_000,   3_000  ],
        "autsors":   [5_000,   5_000,   5_000,   5_000  ],
        "zp_aup":    [100_000, 100_000, 100_000, 100_000],
        "gsm":       [5_000,   5_000,   5_000,   5_000  ],
        "nal_ip":    [119_350, 119_350, 119_350, 119_350],
        "kom_zp":    [2_000,   2_000,   2_000,   2_000  ],
        "bek":       [295_694, 295_694, 295_694, 295_694],
        "office":    [3_000,   3_000,   3_000,   3_000  ],
        "usn":       [42_000,  42_000,  42_000,  42_000 ],
        "nds":       [117_716, 117_716, 117_716, 117_716],
    },
    "Живи в Курортном": {
        "rev_od":    [1_299_348, 1_299_348, 1_299_348, 1_299_348],
        "rev_add":   [772_382,   661_260,   661_261,   661_261  ],
        "rev_snow":  [0, 0, 0, 0],
        "mat_mop":   [10_000,  10_000,  10_000,  10_000 ],
        "mat_pt":    [7_999,   5_000,   5_000,   5_000  ],
        "uniforpt":  [7_069,   0,       0,       0      ],
        "zp_mop":    [695_035, 665_000, 665_000, 665_000],
        "zp_pt":     [600_000, 600_000, 600_000, 600_000],
        "nal_mop":   [60_061,  79_913,  79_913,  79_913 ],
        "nal_pt":    [28_000,  28_000,  28_000,  28_000 ],
        "remont":    [3_000,   3_000,   3_000,   3_000  ],
        "autsors":   [2_500,   2_500,   2_500,   2_500  ],
        "zp_aup":    [110_000, 110_000, 110_000, 110_000],
        "gsm":       [3_504,   3_500,   3_500,   3_500  ],
        "nal_ip":    [95_550,  95_550,  95_550,  95_550 ],
        "kom_zp":    [4_000,   4_000,   4_000,   4_000  ],
        "arenda":    [44_440,  45_000,  45_000,  45_000 ],
        "bek":       [139_048, 139_048, 139_048, 139_048],
        "usn":       [28_000,  28_000,  28_000,  28_000 ],
        "nds":       [94_590,  94_590,  94_590,  94_590 ],
    },
}

def calc_totals(d: dict) -> dict:
    """Суммирует и считает P&L для словаря с 4-месячными списками."""
    s = {k: sum(v) for k, v in d.items()}
    rev = s.get("rev_od",0) + s.get("rev_add",0) + s.get("rev_snow",0)
    fot_direct = (s.get("zp_mop",0) + s.get("zp_pt",0) +
                  s.get("nal_mop",0) + s.get("nal_pt",0))
    fot_indirect = s.get("zp_aup",0) + s.get("nal_ip",0)
    fot_total = fot_direct + fot_indirect
    non_fot = (s.get("mat_mop",0) + s.get("mat_pt",0) + s.get("sadov",0) +
               s.get("arena",0) + s.get("remont",0) + s.get("uniforpt",0) +
               s.get("autsors",0) + s.get("gsm",0) + s.get("kom_zp",0) +
               s.get("arenda",0) + s.get("office",0))
    direct = fot_direct + fot_indirect + non_fot
    indirect = s.get("bek",0) + s.get("office",0)
    # уберём office из indirect если уже в direct
    indirect = s.get("bek",0)
    gross = rev - direct
    op = gross - indirect
    tax = s.get("usn",0) + s.get("nds",0)
    net = op - tax
    return {
        "rev": rev, "fot_direct": fot_direct, "fot_indirect": fot_indirect,
        "fot_total": fot_total, "non_fot": non_fot, "direct": direct,
        "indirect": indirect, "gross": gross, "op": op, "tax": tax, "net": net,
    }

def required_rev_for_same_net(base: dict, fot_growth: float) -> tuple[float, float]:
    """Возвращает (новая выручка, прирост выручки) чтобы сохранить текущую чистую прибыль."""
    fot_increase = base["fot_total"] * fot_growth
    new_net_target = base["net"]
    # Net = Rev - Direct_new - Indirect - Tax
    # Tax_nds ≈ Rev * nds_rate; УСН = fixed
    # Solve: Rev*(1-nds_rate) - (direct_new + indirect + usn) = new_net_target
    usn = sum(d.get("usn",0) for m_vals in [DATA[jk] for jk in DATA if jk in DATA] for d in [])
    # Simple: ΔRev = ΔCost (ignoring НДС on incremental revenue for simplicity of model)
    # More accurate: НДС = Rev × 5/105 → ΔRevenue_needed = ΔCost / (1 - 5/105)
    nds_rate = 5 / 105  # ~4.76%
    new_revenue = (base["rev"] * (1 - nds_rate) + fot_increase) / (1 - nds_rate)
    delta = new_revenue - base["rev"]
    return new_revenue, delta

def required_rev_for_target_margin(base_data: dict, target_margin: float) -> tuple[float, float]:
    """Возвращает (новая выручка, прирост) для достижения целевой чистой маржи."""
    t = calc_totals(base_data)
    fixed_costs = t["direct"] + t["indirect"] + t.get("usn", sum(base_data.get("usn", [0,0,0,0])))
    nds_rate = 5 / 105
    # Net = Rev*(1-nds_rate) - fixed_costs = target_margin * Rev
    # Rev*(1 - nds_rate - target_margin) = fixed_costs
    usn_total = sum(base_data.get("usn", [0,0,0,0]))
    nds_paid = sum(base_data.get("nds", [0,0,0,0]))
    # НДС is computed as Revenue * 5/105
    # Net = Revenue - direct - indirect - usn - nds
    # nds = Revenue * 5/105
    # Net = Revenue*(1 - 5/105) - (direct + indirect + usn) = target * Revenue
    # Revenue*(1 - 5/105 - target) = direct + indirect + usn
    fixed = t["direct"] + t["indirect"] + usn_total
    new_rev = fixed / (1 - nds_rate - target_margin)
    return new_rev, new_rev - t["rev"]


def build_sheet(wb: Workbook, sheet_name: str, jk_name: str,
                scenario_label: str, fot_growth: float,
                base_data: dict, is_target_margin: bool = False,
                target_margin: float = 0.0):
    ws = wb.create_sheet(title=sheet_name[:31])
    ws.row_dimensions[1].height = 30
    ws.column_dimensions["A"].width = 38
    for col in "BCDEFG":
        ws.column_dimensions[col].width = 16

    # ── Заголовок ──
    merged_header(ws, 1, f"Финансовая модель — {jk_name}  |  {scenario_label}",
                  1, 7)

    # ── Шапка таблицы ──
    header_row(ws, 2, ["Статья"] + MONTHS + ["Итого", "Сценарий"])

    ROW = 3  # текущая строка

    # ── Блок: Выручка ──
    section_row(ws, ROW, "📈  ВЫРУЧКА", 7); ROW += 1
    d = base_data
    rev_m = [sum([d.get("rev_od",[0,0,0,0])[i], d.get("rev_add",[0,0,0,0])[i],
                  d.get("rev_snow",[0,0,0,0])[i]]) for i in range(4)]
    rev_total = sum(rev_m)

    if fot_growth > 0 and not is_target_margin:
        t = calc_totals(d)
        new_rev, delta_rev = required_rev_for_same_net(t, fot_growth)
        rev_factor = new_rev / rev_total if rev_total else 1
        rev_scen = [v * rev_factor for v in rev_m]
    elif is_target_margin:
        new_rev, delta_rev = required_rev_for_target_margin(d, target_margin)
        rev_factor = new_rev / rev_total if rev_total else 1
        rev_scen = [v * rev_factor for v in rev_m]
    else:
        new_rev = rev_total
        delta_rev = 0
        rev_scen = rev_m[:]

    data_row(ws, ROW, "Реализация услуг (ОД)",
             d.get("rev_od",[0,0,0,0]) + [sum(d.get("rev_od",[0,0,0,0])), ""], indent=1); ROW+=1
    if sum(d.get("rev_add",[0,0,0,0])) > 0:
        data_row(ws, ROW, "Доп. услуги",
                 d.get("rev_add",[0,0,0,0]) + [sum(d.get("rev_add",[0,0,0,0])), ""], indent=1); ROW+=1

    result_row(ws, ROW, "ИТОГО ВЫРУЧКА",
               rev_m + [rev_total, new_rev],
               bg=C_RESULT_BG if fot_growth==0 else C_SCENARIO_BG)
    # подсвечиваем изменение
    if new_rev != rev_total:
        ws.cell(ROW, 7).fill = fill(C_FOT_BG)
        ws.cell(ROW, 7).font = font(bold=True, color="C55A11")
    ROW += 1
    REV_RESULT_ROW = ROW - 1

    # ── Блок: Прямые расходы ──
    section_row(ws, ROW, "💸  ПРЯМЫЕ РАСХОДЫ", 7); ROW += 1

    # ФОТ секция
    section_row(ws, ROW, "  ФОТ прямой (МОП + ПТ)", 7); ROW += 1
    fot_direct_keys = [
        ("zp_mop",  "Заработная плата МОП"),
        ("zp_pt",   "Заработная плата ПТ"),
        ("nal_mop", "Налоги с ФОТ (МОП)"),
        ("nal_pt",  "Налоги с ФОТ (ПТ)"),
    ]
    fot_direct_m = [0,0,0,0]
    for key, label in fot_direct_keys:
        vals = d.get(key, [0,0,0,0])
        if sum(vals) == 0:
            continue
        scen_vals = [v*(1+fot_growth) for v in vals]
        data_row(ws, ROW, label,
                 vals + [sum(vals), sum(scen_vals)],
                 bg=C_FOT_BG, fmt=NUM_FMT, indent=2)
        fot_direct_m = [fot_direct_m[i]+vals[i] for i in range(4)]
        ROW += 1

    fot_d_total = sum(fot_direct_m)
    fot_d_scen  = fot_d_total * (1 + fot_growth)
    result_row(ws, ROW, "  Итого ФОТ прямой",
               fot_direct_m + [fot_d_total, fot_d_scen], bg=C_FOT_BG)
    ROW += 1

    # ФОТ АУП
    section_row(ws, ROW, "  ФОТ АУП (общехозяйственный)", 7); ROW += 1
    fot_aup_keys = [
        ("zp_aup",  "Заработная плата МО АУП"),
        ("nal_ip",  "Налоги с ФОТ (ИП)"),
    ]
    fot_aup_m = [0,0,0,0]
    for key, label in fot_aup_keys:
        vals = d.get(key, [0,0,0,0])
        if sum(vals) == 0:
            continue
        scen_vals = [v*(1+fot_growth) for v in vals]
        data_row(ws, ROW, label,
                 vals + [sum(vals), sum(scen_vals)],
                 bg=C_FOT_BG, fmt=NUM_FMT, indent=2)
        fot_aup_m = [fot_aup_m[i]+vals[i] for i in range(4)]
        ROW += 1

    fot_aup_total = sum(fot_aup_m)
    fot_aup_scen  = fot_aup_total * (1 + fot_growth)
    result_row(ws, ROW, "  Итого ФОТ АУП",
               fot_aup_m + [fot_aup_total, fot_aup_scen], bg=C_FOT_BG)
    ROW += 1

    fot_total_base = fot_d_total + fot_aup_total
    fot_total_scen = fot_d_scen + fot_aup_scen
    result_row(ws, ROW, "⚡ ИТОГО ФОТ",
               [fot_direct_m[i]+fot_aup_m[i] for i in range(4)] +
               [fot_total_base, fot_total_scen],
               bg=C_FOT_BG)
    ROW += 1
    FOT_DELTA = fot_total_scen - fot_total_base

    # Прочие прямые
    non_fot_keys = [
        ("mat_mop", "Расходные материалы МОП"),
        ("mat_pt",  "Расходные материалы ПТ"),
        ("sadov",   "Услуги садовника (ПТ)"),
        ("arena",   "Аренда оборудования (МОП)"),
        ("remont",  "Ремонт оборудования (ПТ)"),
        ("uniforpt","Униформа (ПТ)"),
        ("autsors", "Аутсорс направления"),
        ("gsm",     "Инструменты: ГСМ"),
        ("kom_zp",  "Комиссия за перевод ЗП"),
        ("arenda",  "Аренда квартиры"),
        ("office",  "Офисные расходы"),
    ]
    non_fot_m = [0,0,0,0]
    section_row(ws, ROW, "  Прочие прямые расходы", 7); ROW += 1
    for key, label in non_fot_keys:
        vals = d.get(key, [0,0,0,0])
        if sum(vals) == 0:
            continue
        data_row(ws, ROW, label, vals + [sum(vals), sum(vals)], indent=2)
        non_fot_m = [non_fot_m[i]+vals[i] for i in range(4)]
        ROW += 1

    non_fot_total = sum(non_fot_m)
    result_row(ws, ROW, "  Итого прочие прямые", non_fot_m + [non_fot_total, non_fot_total])
    ROW += 1

    direct_m = [fot_direct_m[i]+fot_aup_m[i]+non_fot_m[i] for i in range(4)]
    direct_total = sum(direct_m)
    direct_scen  = fot_total_scen + non_fot_total
    result_row(ws, ROW, "ИТОГО ПРЯМЫЕ РАСХОДЫ",
               direct_m + [direct_total, direct_scen], bg=C_WARN_BG)
    ROW += 1

    # ── Валовая прибыль ──
    gross_m = [rev_m[i]-direct_m[i] for i in range(4)]
    gross_base = sum(gross_m)
    gross_scen = new_rev - direct_scen
    gross_pct  = gross_scen / new_rev if new_rev else 0
    result_row(ws, ROW, "ВАЛОВАЯ ПРИБЫЛЬ",
               gross_m + [gross_base, gross_scen],
               bg=C_RESULT_BG if gross_scen>=0 else C_WARN_BG)
    ROW += 1
    data_row(ws, ROW, "Валовая рентабельность",
             [""]*4 + ["", f"{gross_scen/new_rev*100:.1f}%" if new_rev else "-"],
             italic=True)
    ROW += 1

    # ── Косвенные расходы ──
    section_row(ws, ROW, "🏢  КОСВЕННЫЕ РАСХОДЫ (БЭК-ОФИС)", 7); ROW += 1
    bek_m = d.get("bek", [0,0,0,0])
    bek_total = sum(bek_m)
    data_row(ws, ROW, "Расход Бэк-Офис", bek_m + [bek_total, bek_total], indent=1)
    ROW += 1
    result_row(ws, ROW, "ИТОГО КОСВЕННЫЕ", bek_m + [bek_total, bek_total])
    ROW += 1

    # ── Операционная прибыль ──
    op_m = [gross_m[i]-bek_m[i] for i in range(4)]
    op_base = gross_base - bek_total
    op_scen  = gross_scen - bek_total
    result_row(ws, ROW, "ОПЕРАЦИОННАЯ ПРИБЫЛЬ",
               op_m + [op_base, op_scen],
               bg=C_RESULT_BG if op_scen>=0 else C_WARN_BG)
    ROW += 1

    # ── Налог ──
    section_row(ws, ROW, "📋  НАЛОГИ", 7); ROW += 1
    usn_m = d.get("usn", [0,0,0,0])
    nds_m = d.get("nds", [0,0,0,0])
    usn_total = sum(usn_m)
    nds_total_base = sum(nds_m)
    nds_total_scen = new_rev * 5 / 105  # НДС 5% от выручки
    data_row(ws, ROW, "Налоги УСН (6%)",
             usn_m + [usn_total, usn_total], indent=1); ROW += 1
    data_row(ws, ROW, "НДС 5%",
             nds_m + [nds_total_base, round(nds_total_scen)], indent=1); ROW += 1
    tax_base = usn_total + nds_total_base
    tax_scen = usn_total + nds_total_scen
    result_row(ws, ROW, "ИТОГО НАЛОГИ",
               [usn_m[i]+nds_m[i] for i in range(4)] + [tax_base, round(tax_scen)])
    ROW += 1

    # ── Чистая прибыль ──
    net_base = op_base - tax_base
    net_scen = op_scen - tax_scen
    net_pct  = net_scen / new_rev if new_rev else 0
    bg_net = C_RESULT_BG if net_scen >= 0 else C_WARN_BG
    result_row(ws, ROW, "✅ ЧИСТАЯ ПРИБЫЛЬ",
               [op_m[i]-usn_m[i]-nds_m[i] for i in range(4)] + [net_base, round(net_scen)],
               bg=bg_net)
    ROW += 1
    data_row(ws, ROW, "Рентабельность чистой прибыли",
             [""]*4 + ["", f"{net_pct*100:.1f}%"],
             italic=True); ROW += 1

    ROW += 1
    # ── Блок выводов ──
    merged_header(ws, ROW, "💡  РЕЗУЛЬТАТЫ СЦЕНАРИЯ", 1, 7,
                  bg="1F3864", fg="FFFFFF")
    ROW += 1

    results = []
    if fot_growth > 0:
        results = [
            ("Текущая выручка (4 мес.)",          f"{rev_total:,.0f} ₽"),
            (f"Прирост ФОТ (+{fot_growth*100:.0f}%)",  f"{FOT_DELTA:,.0f} ₽"),
            ("Требуемая выручка (новый контракт)", f"{new_rev:,.0f} ₽"),
            ("Прирост выручки",                   f"{delta_rev:+,.0f} ₽"),
            ("Прирост контракта, %",              f"{delta_rev/rev_total*100:+.1f}%"),
            ("Требуемая МЕСЯЧНАЯ ставка",          f"{new_rev/4:,.0f} ₽/мес"),
            ("Текущая МЕСЯЧНАЯ ставка",            f"{rev_total/4:,.0f} ₽/мес"),
            ("Рентабельность чистой прибыли",      f"{net_pct*100:.1f}%"),
        ]
    elif is_target_margin:
        results = [
            ("Текущая выручка (4 мес.)",           f"{rev_total:,.0f} ₽"),
            (f"Цель: рентабельность чистой прибыли", f"{target_margin*100:.0f}%"),
            ("Требуемая выручка для цели",          f"{new_rev:,.0f} ₽"),
            ("Необходимый прирост выручки",         f"{delta_rev:+,.0f} ₽"),
            ("Прирост контракта, %",               f"{delta_rev/rev_total*100:+.1f}%"),
            ("Требуемая МЕСЯЧНАЯ ставка",           f"{new_rev/4:,.0f} ₽/мес"),
            ("Текущая МЕСЯЧНАЯ ставка",             f"{rev_total/4:,.0f} ₽/мес"),
            ("Текущая рентабельность ч.п.",        f"{net_base/rev_total*100:.1f}%"),
        ]
    else:
        t = calc_totals(d)
        results = [
            ("Выручка (4 мес.)",                  f"{t['rev']:,.0f} ₽"),
            ("ФОТ итого",                          f"{t['fot_total']:,.0f} ₽"),
            ("ФОТ / Выручка",                     f"{t['fot_total']/t['rev']*100:.1f}%"),
            ("Валовая прибыль",                   f"{t['gross']:,.0f} ₽"),
            ("Валовая рентабельность",            f"{t['gross']/t['rev']*100:.1f}%"),
            ("Операционная прибыль",              f"{t['op']:,.0f} ₽"),
            ("Чистая прибыль",                    f"{t['net']:,.0f} ₽"),
            ("Рентабельность чистой прибыли",     f"{t['net']/t['rev']*100:.1f}%"),
        ]

    for label, value in results:
        ws.cell(ROW, 1, label).font = font(size=10)
        ws.cell(ROW, 1).fill = fill(C_SCENARIO_BG)
        ws.cell(ROW, 1).border = thin_border()
        ws.cell(ROW, 1).alignment = align("left")
        c = ws.cell(ROW, 2, value)
        c.font = font(bold=True, size=11)
        c.fill = fill(C_RESULT_BG if "прибыл" in label.lower() or "рент" in label.lower() or "треб" in label.lower() else C_SCENARIO_BG)
        c.border = thin_border()
        for cc in range(3, 8):
            ws.cell(ROW, cc).fill = fill(C_SCENARIO_BG)
            ws.cell(ROW, cc).border = thin_border()
        ROW += 1

    ws.freeze_panes = "B3"
    return ws


def build_model(jk_name: str, output_path: Path):
    wb = Workbook()
    wb.remove(wb.active)
    d = DATA[jk_name]

    if jk_name in ("Астрид", "Новое Колпино"):
        build_sheet(wb, "Текущий бюджет",       jk_name, "Текущее состояние",       0.0, d)
        build_sheet(wb, "+30% ФОТ",             jk_name, "Сценарий: ФОТ +30%",      0.3, d)
        build_sheet(wb, "+40% ФОТ",             jk_name, "Сценарий: ФОТ +40%",      0.4, d)
    else:  # Курортный
        build_sheet(wb, "Текущий бюджет",       jk_name, "Текущее состояние",       0.0, d)
        build_sheet(wb, "Цель 20% рент.",       jk_name, "Сценарий: цель ЧП 20%",   0.0, d,
                    is_target_margin=True, target_margin=0.20)
        build_sheet(wb, "Цель 15% рент.",       jk_name, "Сценарий: цель ЧП 15%",   0.0, d,
                    is_target_margin=True, target_margin=0.15)

    wb.save(output_path)
    print(f"✓ {output_path.name}")


if __name__ == "__main__":
    out = Path("/workspace/budget/output")
    out.mkdir(parents=True, exist_ok=True)
    build_model("Астрид",           out / "Фин.модель — Астрид.xlsx")
    build_model("Новое Колпино",    out / "Фин.модель — Новое Колпино.xlsx")
    build_model("Живи в Курортном", out / "Фин.модель — Курортный.xlsx")
    print("\nВсе модели готовы!")
