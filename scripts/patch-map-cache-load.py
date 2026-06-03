#!/usr/bin/env python3
"""Add MAP_SLUG + cache-first loadBuildings to all map-*.html files."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

LOAD_BLOCK = """
function renderRoads(rdData) {
  drawHighways(parseOverpassHighways(rdData));
}

function renderBuildings(bldData) {
  drawBuildings(parseOverpass(bldData));
}

async function loadBuildingsFromOverpass() {
  setStatus('⏳ Здания OSM (Overpass)…');
  const osm = await fetchOverpass();
  setStatus('⏳ Дороги (Overpass)…');
  const hwData = await fetchOverpassQuery(OVERPASS_HIGHWAYS_QUERY);
  renderRoads(hwData);
  renderBuildings(osm);
}

async function loadBuildings() {
  try {
    const [bldResp, rdResp] = await Promise.all([
      fetch('/data/buildings/' + MAP_SLUG + '-buildings.json'),
      fetch('/data/buildings/' + MAP_SLUG + '-roads.json')
    ]);
    if (bldResp.ok && rdResp.ok) {
      const bld = await bldResp.json();
      const rd = await rdResp.json();
      renderRoads(rd);
      renderBuildings(bld);
      return;
    }
  } catch (e) {}
  console.warn('Кеш недоступен, запрос к Overpass...');
  await loadBuildingsFromOverpass();
}
"""

OLD_INIT_OSM = """    setStatus('⏳ Здания OSM…');
    const osm = await fetchOverpass();
    const polys = parseOverpass(osm);
    setStatus('⏳ Дороги и тропинки…');
    const hwData = await fetchOverpassQuery(OVERPASS_HIGHWAYS_QUERY);
    drawHighways(parseOverpassHighways(hwData));
    setStatus('⏳ Отрисовка…');
    drawBuildings(polys);"""

NEW_INIT_OSM = """    setStatus('⏳ Карта OSM…');
    await loadBuildings();"""


def slug_from_path(path: Path) -> str:
    return path.stem.replace("map-", "", 1)


def patch_file(path: Path) -> bool:
    content = path.read_text(encoding="utf-8")
    if "async function loadBuildings()" in content:
        return False

    slug = slug_from_path(path)
    if "const MAP_SLUG" not in content:
        content = content.replace(
            "const OVERPASS_HIGHWAYS_QUERY = ",
            f"const MAP_SLUG = '{slug}';\nconst OVERPASS_HIGHWAYS_QUERY = ",
            1,
        )

    anchor = "async function fetchHouseBreakdown()"
    if anchor not in content:
        print(f"SKIP {path.name}: anchor not found")
        return False
    content = content.replace(anchor, LOAD_BLOCK + "\nasync function fetchHouseBreakdown()")

    if OLD_INIT_OSM not in content:
        print(f"SKIP {path.name}: init block not found")
        return False
    content = content.replace(OLD_INIT_OSM, NEW_INIT_OSM)

    path.write_text(content, encoding="utf-8")
    return True


def main():
    for path in sorted(ROOT.glob("map-*.html")):
        if patch_file(path):
            print("patched", path.name)
    tpl = ROOT / "templates" / "map-template.html"
    if tpl.exists():
        content = tpl.read_text(encoding="utf-8")
        if "async function loadBuildings()" not in content:
            content = content.replace(
                "const OVERPASS_HIGHWAYS_QUERY = ",
                "const MAP_SLUG = '{{SLUG}}';\nconst OVERPASS_HIGHWAYS_QUERY = ",
                1,
            )
            content = content.replace(
                "async function fetchHouseBreakdown()",
                LOAD_BLOCK + "\nasync function fetchHouseBreakdown()",
            )
            content = content.replace(OLD_INIT_OSM, NEW_INIT_OSM)
            tpl.write_text(content, encoding="utf-8")
            print("patched map-template.html")


if __name__ == "__main__":
    main()
