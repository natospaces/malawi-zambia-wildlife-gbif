#!/usr/bin/env python3
"""
Settlement builder for the MAZA occurrence viewer.

Fetches named populated places (villages, hamlets, towns) in the MAZA box from
OpenStreetMap via Overpass, and writes a small JSON the ETL uses to label each
occurrence record with its NEAREST named village. Conflict happens at a village,
so records need a village name, not a province.

OSM place nodes (ODbL, redistributable) carry name + place type. This is a
single bounded-area query (not per-point lookups), so it respects usage limits.
For very dense areas Overpass can be heavy; the box here is small enough.

Alternative source: HDX "Populated Places (OpenStreetMap Export)" for Malawi
and Zambia (data.humdata.org) — pre-made downloads if Overpass is unavailable.

Usage:
  pip install requests
  python build_settlements.py

Output: settlements.json  (list of {name, place, lat, lon})
"""

import json
import sys
import time

try:
    import requests
except ImportError:
    requests = None

LAT_MIN, LAT_MAX = -14.5, -9.5
LON_MIN, LON_MAX = 31.0, 35.0
OUT = "settlements.json"

OVERPASS_ENDPOINTS = [
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass.private.coffee/api/interpreter",
]
HTTP_HEADERS = {
    "User-Agent": "MAZA-occurrence-viewer/1.0 (settlement fetch; contact: you@example.com)",
    "Accept": "application/json,*/*",
}

def overpass(query):
    last = None
    for ep in OVERPASS_ENDPOINTS:
        try:
            r = requests.post(ep, data={"data": query}, headers=HTTP_HEADERS, timeout=(10, 180))
            if r.status_code == 200:
                return r.json()
            last = f"{r.status_code} from {ep}"
            time.sleep(1)
        except Exception as e:
            last = f"{type(e).__name__} ({ep})"
            time.sleep(1)
    raise RuntimeError(last or "all Overpass mirrors failed")

def main():
    if requests is None:
        raise SystemExit("pip install requests")
    # Named populated places in the box. Nodes carry a well-defined centre.
    q = f"""
    [out:json][timeout:180];
    (
      node["place"~"^(city|town|village|hamlet|isolated_dwelling)$"]["name"]
        ({LAT_MIN},{LON_MIN},{LAT_MAX},{LON_MAX});
    );
    out body;
    """
    print("Fetching named settlements in the MAZA box from OSM...")
    data = overpass(q)
    places = []
    for el in data.get("elements", []):
        if el.get("type") != "node":
            continue
        tags = el.get("tags", {})
        name = tags.get("name")
        if not name:
            continue
        places.append({
            "name": name,
            "place": tags.get("place", "settlement"),
            "lat": round(el["lat"], 5),
            "lon": round(el["lon"], 5),
        })
    # Sort by place importance so ties prefer the larger settlement.
    rank = {"city": 0, "town": 1, "village": 2, "hamlet": 3, "isolated_dwelling": 4}
    places.sort(key=lambda p: rank.get(p["place"], 5))

    out = {
        "attribution": "Settlement names \u00a9 OpenStreetMap contributors (ODbL).",
        "count": len(places),
        "places": places,
    }
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False)
    print(f"Wrote {OUT}: {len(places)} named settlements, "
          f"{round(len(json.dumps(out))/1024,1)} KB")
    # quick breakdown
    from collections import Counter
    for k, v in Counter(p["place"] for p in places).most_common():
        print(f"  {k}: {v}")

if __name__ == "__main__":
    main()
