#!/usr/bin/env python3
"""
Protected-area boundary builder for the MAZA occurrence viewer.

Fetches named protected-area boundaries from OpenStreetMap via the Overpass
API, simplifies them, and writes a small GeoJSON the map can serve. OSM data
is ODbL-licensed (redistributable with attribution) — the same licence as the
map's base tiles. WDPA/Protected Planet is the authoritative reference, but its
terms restrict redistribution through web maps, so boundaries are drawn from OSM.

Runs offline (needs open network + Overpass). Upload protected_areas.geojson
to the site next to index.html.

Usage:
  pip install requests shapely
  python build_protected_areas.py
"""

import json
import time
import sys

try:
    import requests
except ImportError:
    requests = None
from shapely.geometry import shape, mapping
from shapely.ops import unary_union

# Named protected areas to fetch. Names are used as case-insensitive regex
# against OSM 'name', so partial/core names catch tagging variants
# (e.g. "Vwaza" matches both "Vwaza Marsh Wildlife Reserve" and
# "Vwaza Marsh Game Reserve").
AREAS = [
    {"name": "Vwaza",     "label": "Vwaza Marsh Wildlife Reserve", "country": "Malawi", "kind": "reserve"},
    {"name": "Nyika",     "label": "Nyika National Park",          "country": "Malawi/Zambia", "kind": "park"},
    {"name": "Musalangu", "label": "Musalangu GMA",                "country": "Zambia", "kind": "gma"},
    {"name": "Lundazi",   "label": "Lundazi GMA",                  "country": "Zambia", "kind": "gma"},
    {"name": "Luambe",    "label": "Luambe National Park",         "country": "Zambia", "kind": "park"},
    {"name": "Lukusuzi",  "label": "Lukusuzi National Park",       "country": "Zambia", "kind": "park"},
]

OVERPASS_ENDPOINTS = [
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass.private.coffee/api/interpreter",
]
SIMPLIFY_TOLERANCE = 0.002   # ~200m; keeps files small, shape still readable
OUT = "protected_areas.geojson"

# Headers matter: overpass-api.de now bounces requests that look like bare
# scrapers (406 Not Acceptable). Send a real User-Agent and explicit Accept.
HTTP_HEADERS = {
    "User-Agent": "MAZA-occurrence-viewer/1.0 (protected-area boundary fetch; contact: you@example.com)",
    "Accept": "application/json,*/*",
    "Accept-Language": "en",
}

# MAZA box, to drop anything stray outside the landscape.
LAT_MIN, LAT_MAX = -14.5, -9.5
LON_MIN, LON_MAX = 31.0, 35.0


def overpass_query(name, bbox=None):
    """Query OSM for a protected area by name, or by bounding box as fallback.

    OSM tagging for parks is inconsistent: some use boundary=protected_area,
    some boundary=national_park, some leisure=nature_reserve, and the readable
    name may live in name, official_name, or protection_title. So the name
    query matches across all of these with 'nwr' (node/way/relation).

    If name matching returns nothing, pass a bbox to pull ALL protected areas
    in that box and let the caller pick by name/size.
    """
    if bbox:
        south, west, north, east = bbox
        q = f"""
        [out:json][timeout:120];
        (
          nwr["boundary"="protected_area"]({south},{west},{north},{east});
          nwr["boundary"="national_park"]({south},{west},{north},{east});
          nwr["leisure"="nature_reserve"]({south},{west},{north},{east});
        );
        out geom;
        """
    else:
        # Match the name against name / official_name / alt_name / protection_title,
        # on any protected-area-ish tag, for nodes/ways/relations.
        q = f"""
        [out:json][timeout:120];
        (
          nwr["boundary"="protected_area"]["name"~"{name}",i];
          nwr["boundary"="national_park"]["name"~"{name}",i];
          nwr["leisure"="nature_reserve"]["name"~"{name}",i];
          nwr["boundary"="protected_area"]["official_name"~"{name}",i];
          nwr["boundary"="protected_area"]["protection_title"~"{name}",i];
          nwr["protected_area"]["name"~"{name}",i];
          nwr["leisure"="nature_reserve"]["alt_name"~"{name}",i];
        );
        out geom;
        """
    last_err = None
    for endpoint in OVERPASS_ENDPOINTS:
        try:
            # (connect timeout, read timeout): a mirror that accepts then stalls
            # is abandoned after the read timeout instead of hanging forever.
            r = requests.post(endpoint, data={"data": q},
                              headers=HTTP_HEADERS, timeout=(10, 90))
            if r.status_code == 200:
                return r.json()
            last_err = f"{r.status_code} from {endpoint}"
            time.sleep(1)
        except Exception as e:
            last_err = f"{type(e).__name__} ({endpoint})"
            time.sleep(1)
    raise RuntimeError(last_err or "all Overpass mirrors failed")


def rings_to_polygon(elements):
    """Assemble Overpass geometry into shapely polygons.

    Handles three cases robustly:
      - relation whose boundary is split across many 'way' members that must be
        stitched into closed rings (the common case for large parks),
      - a relation/way that is already a single closed ring,
      - multiple separate polygons (multipolygon).
    Uses shapely.polygonize to close rings from line fragments.
    """
    from shapely.geometry import Polygon, LineString, MultiLineString
    from shapely.ops import polygonize, unary_union

    closed_polys = []   # ways that already close on themselves
    open_lines = []     # fragments to be stitched

    def add_way_geometry(geom_pts):
        coords = [(pt["lon"], pt["lat"]) for pt in geom_pts if "lon" in pt and "lat" in pt]
        if len(coords) < 2:
            return
        if len(coords) >= 4 and coords[0] == coords[-1]:
            try:
                closed_polys.append(Polygon(coords))
                return
            except Exception:
                pass
        open_lines.append(LineString(coords))

    for el in elements:
        t = el.get("type")
        if t == "way" and "geometry" in el:
            add_way_geometry(el["geometry"])
        elif t == "relation":
            for m in el.get("members", []):
                if m.get("type") == "way" and "geometry" in m:
                    # only outer/unspecified roles bound the area
                    if m.get("role") in ("outer", "", None):
                        add_way_geometry(m["geometry"])

    polys = list(closed_polys)

    # Stitch open fragments into rings.
    if open_lines:
        merged = unary_union(open_lines)  # noding: joins touching fragments
        for poly in polygonize(merged):
            polys.append(poly)

    if not polys:
        return None
    try:
        area = unary_union(polys)
        # Keep only the largest component if several disjoint blobs appear
        # (avoids stray slivers); but keep multipolygon if genuinely separate.
        return area
    except Exception:
        # fall back to the single largest polygon
        return max(polys, key=lambda p: p.area)


def in_box(geom):
    c = geom.centroid
    return LON_MIN <= c.x <= LON_MAX and LAT_MIN <= c.y <= LAT_MAX


def _write_output(features):
    """Write the GeoJSON. Called incrementally so completed areas survive an
    interrupt or a later hang, and once more at the end."""
    fc = {
        "type": "FeatureCollection",
        "attribution": "Boundaries \u00a9 OpenStreetMap contributors (ODbL). "
                       "Authoritative reference: WDPA / Protected Planet (UNEP-WCMC & IUCN).",
        "features": features,
    }
    with open(OUT, "w") as f:
        json.dump(fc, f)
    return fc


def main():
    if requests is None:
        raise SystemExit("pip install requests shapely")
    features = []
    seen = set()

    # Shared bbox query for the whole landscape: a fallback for parks whose
    # name tagging differs from our query string, or that only appear by area.
    box_cache = {"data": None}

    def box_elements():
        if box_cache["data"] is None:
            print("Fetching all protected areas in the MAZA box (fallback source)...")
            try:
                box_cache["data"] = overpass_query(
                    None, bbox=(LAT_MIN, LON_MIN, LAT_MAX, LON_MAX)
                ).get("elements", [])
                print(f"  box returned {len(box_cache['data'])} raw elements")
            except Exception as e:
                print(f"  box fetch failed: {e}")
                box_cache["data"] = []
        return box_cache["data"]

    def name_matches(el, needle):
        tags = el.get("tags", {})
        hay = " ".join([
            tags.get("name", ""), tags.get("official_name", ""),
            tags.get("alt_name", ""), tags.get("protection_title", ""),
        ]).lower()
        return needle.lower() in hay

    for area in AREAS:
        name = area["name"]
        label = area.get("label", name)
        print(f"Fetching: {label} (query: '{name}', {area['country']}) ...", flush=True)

        elements = []
        # If the box is already cached, use it first — it's instant and avoids
        # a per-name query that might hang on a slow mirror.
        if box_cache["data"] is not None:
            elements = [el for el in box_cache["data"] if name_matches(el, name)]
            if elements:
                print(f"  found {len(elements)} match(es) in cached box", flush=True)

        # Otherwise try a direct name query (with the mirror fallback + timeouts).
        if not elements:
            try:
                elements = overpass_query(name).get("elements", [])
            except Exception as e:
                print(f"  name query failed: {e}", flush=True)
                elements = []

        # Last resort: load the box (once) and sift it.
        if not elements:
            matched = [el for el in box_elements() if name_matches(el, name)]
            if matched:
                print(f"  found {len(matched)} match(es) via box fallback", flush=True)
                elements = matched

        n_elem = len(elements)
        geom = rings_to_polygon(elements)
        if geom is None or geom.is_empty:
            print(f"  no geometry found ({n_elem} elements)", flush=True)
            continue
        if not in_box(geom):
            print("  outside MAZA box, skipped", flush=True)
            continue
        approx_km2 = geom.area * 111 * 111
        if approx_km2 < 5:
            print(f"  geometry too small (~{approx_km2:.1f} km2), likely a bad ring; skipped", flush=True)
            continue
        geom = geom.simplify(SIMPLIFY_TOLERANCE, preserve_topology=True)
        key = f"{label}:{round(geom.centroid.x,2)}:{round(geom.centroid.y,2)}"
        if key in seen:
            continue
        seen.add(key)
        features.append({
            "type": "Feature",
            "properties": {
                "name": label,
                "country": area["country"],
                "kind": area["kind"],
            },
            "geometry": mapping(geom),
        })
        print(f"  ok (~{approx_km2:.0f} km2, {len(json.dumps(mapping(geom)))} bytes simplified)", flush=True)

        # Write incrementally so completed areas survive an interrupt.
        _write_output(features)
        time.sleep(1)  # be polite to Overpass

    fc = _write_output(features)
    print(f"\nWrote {OUT}: {len(features)} protected areas, "
          f"{round(len(json.dumps(fc))/1024,1)} KB")
    print("Upload protected_areas.geojson next to index.html.")


if __name__ == "__main__":
    main()
