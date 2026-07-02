#!/usr/bin/env python3
"""Apply safe Overpass fetch + bbox expansion to all map-*.html files."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIN_SPAN = 0.014

OLD_FETCH = """async function fetchOverpassQuery(query) {
  const url = '/api/overpass.php?data=' + encodeURIComponent(query);
  const r = await fetch(url);
  if (!r.ok) throw new Error('Overpass HTTP ' + r.status);
  return r.json();
}"""

NEW_FETCH = """async function fetchOverpassQuery(query) {
  console.log('Overpass query:', query);
  const url = '/api/overpass.php?data=' + encodeURIComponent(query);
  const r = await fetch(url);
  const text = await r.text();
  if (!r.ok) throw new Error('Overpass HTTP ' + r.status);
  const trimmed = text.trim();
  if (!trimmed.startsWith('{')) {
    const errMatch = trimmed.match(/<strong[^>]*>Error<\\/strong>:\\s*([^<]+)/i);
    const msg = errMatch ? errMatch[1].trim() : 'Overpass вернул не-JSON (возможно сервер перегружен)';
    throw new Error(msg);
  }
  return JSON.parse(trimmed);
}"""

BBOX_RE = re.compile(
    r"(\[out:json\]\[timeout:25\];way\[(?:building|highway~\"footway\|path\|service\|residential\")\])\(([\d.]+),([\d.]+),([\d.]+),([\d.]+)\)"
)


def round3(x: float) -> float:
    return round(x, 3)


def expand_bbox(south, west, north, east):
    lat_span = north - south
    lon_span = east - west
    if lat_span < MIN_SPAN:
        pad = (MIN_SPAN - lat_span) / 2
        south -= pad
        north += pad
    if lon_span < MIN_SPAN:
        pad = (MIN_SPAN - lon_span) / 2
        west -= pad
        east += pad
    return tuple(round3(x) for x in (south, west, north, east))


def bbox_from_content(content: str):
    m = BBOX_RE.search(content)
    if not m:
        return None
    return tuple(float(x) for x in m.groups()[1:5])


def apply_bbox(content: str, new_bbox_str: str) -> str:
    def repl(m):
        prefix = m.group(1)
        return f"{prefix}({new_bbox_str})"

    return BBOX_RE.sub(repl, content)


def normalize_overpass_constants(content: str, bbox_str: str) -> str:
    """Replace inline OVERPASS_QUERY lines with OVERPASS_BBOX pattern if not kolpino-style."""
    if "const OVERPASS_BBOX" in content:
        content = re.sub(
            r"const OVERPASS_BBOX = '[^']+';",
            f"const OVERPASS_BBOX = '{bbox_str}';",
            content,
        )
        return content

    content = re.sub(
        r"const OVERPASS_QUERY = '\[out:json\]\[timeout:25\];way\[building\]\([^)]+\);out body;>;out skel qt;';\n"
        r"const OVERPASS_HIGHWAYS_QUERY = '\[out:json\]\[timeout:25\];way\[highway~\"footway\|path\|service\|residential\"\]\([^)]+\);out body;>;out skel qt;';\n",
        f"const OVERPASS_BBOX = '{bbox_str}';\n"
        f"const OVERPASS_QUERY = '[out:json][timeout:25];way[building](' + OVERPASS_BBOX + ');out body;>;out skel qt;';\n"
        f"const OVERPASS_HIGHWAYS_QUERY = '[out:json][timeout:25];way[highway~\"footway|path|service|residential\"](' + OVERPASS_BBOX + ');out body;>;out skel qt;';\n",
        content,
    )
    return content


def process_file(path: Path) -> dict:
    content = path.read_text(encoding="utf-8")
    old_bbox = bbox_from_content(content)
    if not old_bbox:
        return {"file": path.name, "error": "bbox not found"}

    south, west, north, east = old_bbox
    new_bbox = expand_bbox(south, west, north, east)
    new_bbox_str = f"{new_bbox[0]},{new_bbox[1]},{new_bbox[2]},{new_bbox[3]}"
    old_bbox_str = f"{round3(south)},{round3(west)},{round3(north)},{round3(east)}"

    content = apply_bbox(content, new_bbox_str)
    content = normalize_overpass_constants(content, new_bbox_str)

    if NEW_FETCH not in content:
        if OLD_FETCH in content:
            content = content.replace(OLD_FETCH, NEW_FETCH)
        elif "console.log('Overpass query:'" not in content:
            # kolpino variant already has fix
            pass

    path.write_text(content, encoding="utf-8")
    changed = old_bbox_str != new_bbox_str
    return {
        "file": path.name,
        "old": old_bbox_str,
        "new": new_bbox_str if changed else "без изменений",
        "changed": changed,
    }


def main():
    files = sorted(ROOT.glob("map-*.html"))
    # iset-park may only be in remote-html
    iset = ROOT / "remote-html" / "map-iset-park.html"
    if iset.exists() and not (ROOT / "map-iset-park.html").exists():
        dest = ROOT / "map-iset-park.html"
        dest.write_text(iset.read_text(encoding="utf-8"), encoding="utf-8")
        files.append(dest)

    rows = []
    for f in files:
        if f.name == "map-kolpino.html":
            # refresh bbox only, fetch already fixed
            rows.append(process_file(f))
            continue
        rows.append(process_file(f))

    template = ROOT / "templates" / "map-template.html"
    tcontent = template.read_text(encoding="utf-8")
    tcontent = tcontent.replace(OLD_FETCH, NEW_FETCH)
    # template uses {{BBOX}} placeholder - document in generate script note
    template.write_text(tcontent, encoding="utf-8")

    print("| файл | старый bbox | новый bbox |")
    print("|------|-------------|------------|")
    for r in rows:
        if "error" in r:
            print(f"| {r['file']} | ERROR | {r['error']} |")
        else:
            print(f"| {r['file']} | {r['old']} | {r['new']} |")


if __name__ == "__main__":
    main()
