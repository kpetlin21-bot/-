#!/usr/bin/env python3
"""Generate per-ЖК dashboard HTML from dashboard-template-v1 index.html."""

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE = ROOT / "template-index.html"

ZK = [
    ("astrid.html", 1, "astrid", "Астрид"),
    ("kurortny.html", 4, "kurortny", "Курортный"),
    ("kosmonavtov-11.html", 6, "kosmonavtov-11", "Космонавтов 11"),
    ("iset-park.html", 7, "iset-park", "Исеть парк"),
    ("utes.html", 9, "utes", "Утес"),
    ("aston-dvizhenie.html", 10, "aston-dvizhenie", "Астон.Движение"),
    ("aston-reforma.html", 11, "aston-reforma", "Астон.Реформа"),
    ("noksa-park.html", 12, "noksa-park", "Нокса парк"),
    ("tvoya-privilegiya.html", 13, "tvoya-privilegiya", "Твоя Привилегия"),
    ("mily-dom.html", 14, "mily-dom", "Дом Милый дом"),
    ("river-park.html", 15, "river-park", "River Park"),
]

HOME_BTN = """      <a href="/home.html" style="font-size:12px;padding:6px 12px;border-radius:6px;border:0.5px solid var(--border-2);text-decoration:none;color:var(--text);font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;">← Все объекты</a>
"""

PROJECT_JS = """const TB_PROJECT_ID={pid};
const TB_SLUG='{slug}';
function PROXY(qs){{
  return 'https://api.cleansyst.ru/proxy.php?project='+TB_PROJECT_ID+'&'+(qs||'').replace(/^\\?/,'');
}}
"""


def build_html(name: str, pid: int, slug: str, title_name: str) -> str:
    html = TEMPLATE.read_text(encoding="utf-8")
    html = html.replace(
        "<title>Дашборд руководителя — ЖК Новое Колпино</title>",
        f"<title>Дашборд руководителя — ЖК «{title_name}»</title>",
    )
    html = html.replace(
        "<h1 class=\"title\">Дашборд руководителя — ЖК «Новое Колпино»</h1>",
        f"<h1 class=\"title\">Дашборд руководителя — ЖК «{title_name}»</h1>",
    )
    html = html.replace(
        "const PROXY='https://api.cleansyst.ru/proxy.php';",
        PROJECT_JS.format(pid=pid, slug=slug),
    )
    html = html.replace("PROXY+'?", "PROXY('")
    html = html.replace(
        "<span id=\"save-status\" class=\"status\"></span>",
        "<span id=\"save-status\" class=\"status\"></span>\n" + HOME_BTN,
    )
    return fix_proxy_calls(html)


def fix_proxy_calls(html: str) -> str:
  """Close PROXY(...) before fetchWithTimeout timeout argument."""
  html = html.replace(
      "encodeURIComponent(dateParam), 180000)",
      "encodeURIComponent(dateParam)), 180000)",
  )
  html = html.replace(
      "encodeURIComponent(dateParam), 120000)",
      "encodeURIComponent(dateParam)), 120000)",
  )
  html = html.replace(
      "encodeURIComponent(dateParam), timeoutMs)",
      "encodeURIComponent(dateParam)), timeoutMs)",
  )
  html = html.replace("expand=zones', 120000)", "expand=zones'), 120000)")
  html = html.replace("expand=zones', 180000)", "expand=zones'), 180000)")
  html = html.replace("expand=tasks', 120000)", "expand=tasks'), 120000)")
  html = html.replace(
      "encodeURIComponent(statusGroup)+\n      '&date='+encodeURIComponent(dateParam), 180000)",
      "encodeURIComponent(statusGroup)+\n      '&date='+encodeURIComponent(dateParam)), 180000)",
  )
  html = html.replace(
      "PROXY('action=history&days=30', 120000)",
      "PROXY('action=history&days=30'), 120000)",
  )
  html = re.sub(
      r"fetch\(PROXY\('action='\+action\+'&date='\+dateParam\);",
      "fetch(PROXY('action='+action+'&date='+dateParam));",
      html,
  )
  html = re.sub(
      r"fetch\(PROXY\('action=day_tasks&date='\+encodeURIComponent\(dateParam\)\);",
      "fetch(PROXY('action=day_tasks&date='+encodeURIComponent(dateParam)));",
      html,
  )
  return html


def main():
    for filename, pid, slug, title_name in ZK:
        out = ROOT / filename
        out.write_text(build_html(filename, pid, slug, title_name), encoding="utf-8")
        print("wrote", filename, pid, slug)


if __name__ == "__main__":
    main()
