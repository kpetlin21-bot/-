#!/usr/bin/env python3
"""Audit + dedupe migration for CleanSyst revenue spreadsheet."""
import csv
import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path

SHEETS = Path("/tmp/sheets")
OUT = Path(__file__).resolve().parent / "output"


def parse_num(s):
    if s is None:
        return None
    s = str(s).strip()
    if not s or s.startswith("#"):
        return None
    s = s.replace("\xa0", "").replace(" ", "").replace(",", ".")
    try:
        return round(float(s), 2)
    except ValueError:
        return None


def norm(s):
    if not s:
        return ""
    return re.sub(r"\s+", " ", str(s).strip().replace("\u200b", "").replace("\ufeff", ""))


def load(name):
    with (SHEETS / f"{name}.csv").open(encoding="utf-8") as f:
        rows = list(csv.reader(f))
    return rows[0], rows[1:]


def pad(row, n):
    row = list(row)
    if len(row) < n:
        row.extend([""] * (n - len(row)))
    return row[:n]


def deal_key(r, idx):
    return (
        norm(r[idx["Навзание проекта"]]),
        norm(r[idx["Клиент"]]),
        norm(r[idx["Статья ОПиУ"]]),
        norm(r[idx["Статья ДДС"]]),
        norm(r[idx["Дата начала работ"]]),
        norm(r[idx["Дата окончания проекта"]]),
        parse_num(r[idx["Начислено с НДС"]]),
        parse_num(r[idx["ИТОГО, реализация за минуоом штрафа"]]),
    )


def region_for(project, client):
    p, c = norm(project), norm(client)
    if "ЕКАТЕРИНБУРГ" in c.upper() or any(
        x in p for x in ("Космонавтов", "Исеть", "Нокса", "Милый дом", "Твоя привилегия", "Утес", "Бэк офис ЕКБ")
    ):
        return "Екатеринбург"
    if any(x in p for x in ("Куй", "Пех", "Астон")) or "БАЗА" in c:
        return "Казань"
    return "Санкт-Петербург"


def payment_status(total, remaining):
    if remaining is None:
        return "Неизвестно", 0.0
    if remaining == 0:
        return "Оплачено", total
    if total and 0 < remaining < total - 0.01:
        return "Частично", round(total - remaining, 2)
    return "Не оплачено", 0.0


MERGE_FIELDS_FROM_UCHET = (
    "Дата планового поступления ДС",
    "месяц планвого поступления  ДС",
    "статус акта",
    "Дата выставления акта",
    "Дата подписания акта",
    "№ Акта",
    "ДАТА УПД",
)


def merge_rows(bz_row, uc_row, idx):
    """Payment truth from bez_ryb; planning/status from uchet when bez empty."""
    out = list(bz_row)
    for field in MERGE_FIELDS_FROM_UCHET:
        if field not in idx:
            continue
        if not norm(out[idx[field]]) and norm(uc_row[idx[field]]):
            out[idx[field]] = uc_row[idx[field]]
    return out


def audit_deals(name, header, data):
    idx = {h: i for i, h in enumerate(header)}
    issues = []
    stats = Counter()

    for n, r in enumerate(data, start=2):
        if not any(norm(c) for c in r):
            continue
        project = norm(r[idx["Навзание проекта"]])
        total = parse_num(r[idx["ИТОГО, реализация за минуоом штрафа"]])
        remaining = parse_num(r[idx["Осталось получить по проекту"]])
        billed = parse_num(r[idx["Начислено с НДС"]])
        penalty = parse_num(r[idx["УДЕРЖАНИЯ, штрафы"]]) or 0

        if not project:
            issues.append({"severity": "error", "code": "EMPTY_PROJECT", "sheet": name, "row": n, "message": "Пустой проект"})
        if not norm(r[idx["Статья ДДС"]]):
            issues.append({"severity": "error", "code": "EMPTY_DDS", "sheet": name, "row": n, "project": project, "message": "Пустая статья ДДС"})

        if remaining is None:
            issues.append({"severity": "error", "code": "BAD_REMAINING", "sheet": name, "row": n, "project": project, "message": "Некорректное «Осталось получить»"})
        elif total is not None and remaining > total + 0.01:
            issues.append({"severity": "error", "code": "REMAINING_GT_TOTAL", "sheet": name, "row": n, "project": project, "message": f"Осталось {remaining} > ИТОГО {total}"})

        if penalty and billed is not None and total is not None and abs(billed - penalty - total) > 0.05:
            issues.append({"severity": "warn", "code": "PENALTY_MATH", "sheet": name, "row": n, "project": project, "message": "ИТОГО != Начислено - штраф"})

        status = norm(r[idx["статус акта"]])
        stats["status_empty" if not status else f"status_{status}"] += 1
        if status == "отказ" and remaining and remaining > 0:
            issues.append({"severity": "critical", "code": "REFUSED_WITH_DEBT", "sheet": name, "row": n, "project": project, "client": norm(r[idx["Клиент"]]), "amount": remaining, "message": f"Отказ + дебиторка {remaining:,.2f}"})

        plan = norm(r[idx["Дата планового поступления ДС"]])
        if remaining and remaining > 0 and not plan:
            issues.append({"severity": "warn", "code": "DEBT_NO_PLAN_DATE", "sheet": name, "row": n, "project": project, "amount": remaining, "message": "Дебиторка без даты плана поступления"})
        if remaining == 0 and plan:
            issues.append({"severity": "info", "code": "PAID_BUT_PLAN_DATE", "sheet": name, "row": n, "project": project, "message": "Оплачено, но дата плана заполнена"})

        for col in ("Срок подписание акта по п 4.6", "Дата окончательного расчета"):
            if "1900" in norm(r[idx[col]]):
                issues.append({"severity": "warn", "code": "PLACEHOLDER_DATE", "sheet": name, "row": n, "project": project, "field": col, "message": f"Placeholder: {r[idx[col]]}"})

        if not norm(r[idx["Менеджер"]]):
            stats["no_manager"] += 1
        if not norm(r[idx["Отсрочка"]]):
            stats["no_deferral"] += 1

    return issues, stats


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    uc_h, uc_d = load("Uchet_sdelok")
    bz_h, bz_d = load("bez_ryb_NK")
    idx = {h: i for i, h in enumerate(uc_h)}
    col_n = len(uc_h)

    i1, _ = audit_deals("Uchet_sdelok", uc_h, uc_d)
    i2, _ = audit_deals("bez_ryb_NK", bz_h, bz_d)
    all_issues = i1 + i2

    uc_by_key = {}
    for i, r in enumerate(uc_d):
        uc_by_key[deal_key(pad(r, col_n), idx)] = (i + 2, pad(r, col_n))

    master_rows = []
    master_meta = []
    seen = set()

    for i, raw in enumerate(bz_d):
        r = pad(raw, col_n)
        k = deal_key(r, idx)
        if k in uc_by_key:
            r = merge_rows(r, uc_by_key[k][1], idx)
            source = f"merge:bez_ryb_NK:{i+2}+Uchet:{uc_by_key[k][0]}"
        else:
            source = f"bez_ryb_NK:{i+2}"
        master_rows.append(r)
        master_meta.append(source)
        seen.add(k)

    added_uc = 0
    for i, raw in enumerate(uc_d):
        r = pad(raw, col_n)
        k = deal_key(r, idx)
        if k not in seen:
            master_rows.append(r)
            master_meta.append(f"Uchet_sdelok:{i+2}")
            seen.add(k)
            added_uc += 1

    # Outputs
    severity_order = {"critical": 0, "error": 1, "warn": 2, "info": 3}
    all_issues.sort(key=lambda x: (severity_order.get(x["severity"], 9), x.get("sheet", ""), x.get("row", 0)))

    with (OUT / "AUDIT_ISSUES.csv").open("w", encoding="utf-8", newline="") as f:
        fields = ["severity", "code", "sheet", "row", "project", "client", "field", "message", "amount"]
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        w.writerows(all_issues)

    extra = ["Регион", "Статус оплаты", "Оплачено", "Источник"]
    with (OUT / "MASTER_JOURNAL_migrated.csv").open("w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(uc_h + extra)
        for r, src in zip(master_rows, master_meta):
            if not norm(r[idx["Навзание проекта"]]) or not norm(r[idx["Статья ДДС"]]):
                continue
            total = parse_num(r[idx["ИТОГО, реализация за минуоом штрафа"]]) or 0
            rem = parse_num(r[idx["Осталось получить по проекту"]])
            if rem is None:
                rem = total  # пустое «осталось» при сумме = полная дебиторка
            st, paid = payment_status(total, rem)
            w.writerow(r + [region_for(r[idx["Навзание проекта"]], r[idx["Клиент"]]), st, f"{paid:.2f}".replace(".", ","), src])

    def pay_stats(data):
        paid = partial = unpaid = 0
        for r in data:
            total = parse_num(r[idx["ИТОГО, реализация за минуоом штрафа"]]) or 0
            rem = parse_num(r[idx["Осталось получить по проекту"]])
            if rem == 0:
                paid += 1
            elif rem and rem < total - 0.01:
                partial += 1
            else:
                unpaid += 1
        return {"paid": paid, "partial": partial, "unpaid": unpaid}

    summary = {
        "uchet_2026": {"rows": len(uc_d), **pay_stats(uc_d)},
        "bez_ryb_nk": {"rows": len(bz_d), **pay_stats(bz_d)},
        "master_journal": {"rows": len(master_rows), **pay_stats(master_rows), "added_from_uchet_only": added_uc, "merged_overlap": len(seen) - added_uc - len(bz_d) + len(set(deal_key(pad(r,col_n),idx) for r in bz_d))},
        "issues": dict(Counter(i["severity"] for i in all_issues)),
        "issues_by_code": dict(Counter(i["code"] for i in all_issues)),
    }
    (OUT / "AUDIT_SUMMARY.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
