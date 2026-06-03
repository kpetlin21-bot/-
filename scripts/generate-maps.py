#!/usr/bin/env python3
"""Generate map-{slug}.html from templates/map-template.html using geocode data."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE_PATH = ROOT / "templates" / "map-template.html"

ZK = [
    ("kolpino", 2, "ЖК Новое Колпино", "ЖК Новое Колпино Санкт-Петербург"),
    ("astrid", 1, "ЖК Астрид", "ЖК Астрид Санкт-Петербург"),
    ("kurortny", 4, "ЖК Курортный", "ЖК Курортный Санкт-Петербург"),
    ("kosmonavtov-11", 6, "ЖК Космонавтов 11", "ЖК Космонавтов 11 Екатеринбург"),
    ("utes", 9, "ЖК Утёс", "ЖК Утёс Екатеринбург"),
    ("aston-dvizhenie", 10, "ЖК Астон Движение", "ЖК Астон Движение Екатеринбург"),
    ("aston-reforma", 11, "ЖК Астон.Реформа", "ЖК Астон Реформа Екатеринбург"),
    ("noksa-park", 12, "Нокса Парк", "Нокса Парк Казань"),
    ("tvoya-privilegiya", 13, "ЖК Твоя Привилегия", "ЖК Твоя Привилегия Екатеринбург"),
    ("mily-dom", 14, "ЖК Дом Милый дом", "ЖК Дом Милый Дом Екатеринбург"),
    ("river-park", 15, "River Park", "River Park Екатеринбург"),
]

# Pre-computed from Nominatim (+ reverse geocode where search returned nothing)
GEOCODE = {
    "kolpino": {"bbox": (59.772, 30.597, 59.777, 30.609), "center": (59.7747, 30.6027), "source": "search"},
    "astrid": {"bbox": (59.745, 30.602, 59.752, 30.609), "center": (59.7456, 30.6052), "source": "reverse@home"},
    "kurortny": {"bbox": (60.111, 30.195, 60.124, 30.210), "center": (60.1170, 30.2022), "source": "reverse@home+expand"},
    "kosmonavtov-11": {"bbox": (56.863, 60.604, 56.868, 60.610), "center": (56.8656, 60.6073), "source": "search"},
    "utes": {"bbox": (56.785, 60.655, 56.787, 60.661), "center": (56.7856, 60.6576), "source": "search"},
    "aston-dvizhenie": {"bbox": (56.874, 60.539, 56.876, 60.541), "center": (56.8753, 60.5401), "source": "search"},
    "aston-reforma": {"bbox": (56.822, 60.651, 56.828, 60.658), "center": (56.8252, 60.6544), "source": "reverse@home+expand"},
    "noksa-park": {"bbox": (55.805, 49.222, 55.808, 49.227), "center": (55.8071, 49.2243), "source": "search"},
    "tvoya-privilegiya": {"bbox": (56.764, 60.531, 56.767, 60.538), "center": (56.7658, 60.5357), "source": "search"},
    "mily-dom": {"bbox": (56.791, 60.581, 56.806, 60.597), "center": (56.7984, 60.5888), "source": "reverse@home+expand"},
    "river-park": {"bbox": (56.832, 60.613, 56.846, 60.628), "center": (56.8389, 60.6200), "source": "reverse@home+expand"},
}


def expand_bbox(south, west, north, east, min_lat=0.014, min_lon=0.014):
    r3 = lambda x: round(x, 3)
    lat_span = north - south
    lon_span = east - west
    if lat_span < min_lat:
        pad = (min_lat - lat_span) / 2
        south -= pad
        north += pad
    if lon_span < min_lon:
        pad = (min_lon - lon_span) / 2
        west -= pad
        east += pad
    return tuple(r3(x) for x in (south, west, north, east))


def overpass_bbox_str(bbox):
    s, w, n, e = bbox
    return f"{s},{w},{n},{e}"


def render_map(slug, project_id, name, bbox, center):
    tpl = TEMPLATE_PATH.read_text(encoding="utf-8")
    bb = overpass_bbox_str(bbox)
    lat, lon = center
    dash = "index.html" if slug == "kolpino" else f"{slug}.html"
    out = tpl.replace("{{ZK_NAME}}", name)
    out = out.replace("{{PROJECT_ID}}", str(project_id))
    out = out.replace("{{BBOX}}", bb)
    out = out.replace("{{CENTER_LAT}}", str(lat))
    out = out.replace("{{CENTER_LON}}", str(lon))
    out = out.replace("{{SLUG}}", slug)
    out = out.replace("{{DASHBOARD}}", dash)
    path = ROOT / f"map-{slug}.html"
    path.write_text(out, encoding="utf-8")
    return path


def main():
    created = []
    for slug, pid, name, _q in ZK:
        g = GEOCODE[slug]
        bbox = expand_bbox(*g["bbox"]) if "expand" in g["source"] else g["bbox"]
        if slug in ("astrid", "utes", "aston-dvizhenie", "noksa-park", "tvoya-privilegiya", "kosmonavtov-11"):
            bbox = expand_bbox(*bbox)
        p = render_map(slug, pid, name, bbox, g["center"])
        created.append(p.name)
    print("Created:", ", ".join(created))


if __name__ == "__main__":
    main()
