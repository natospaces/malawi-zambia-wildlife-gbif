#!/usr/bin/env python3
"""
Border builder for the MAZA occurrence viewer.

Fetches full national outlines (ADM0) and provinces (ADM1) for Malawi and
Zambia from geoBoundaries (CC-BY 4.0, redistributable), simplifies them, and
writes two small GeoJSON files:

  borders.geojson    - full country outlines (Malawi, Zambia)
  provinces.geojson  - ADM1 provinces/regions, for faint orientation lines

The map uses these to make the two countries stand out (a soft dim over the
surrounding countries + a thin solid national border) rather than drawing a
heavy dashed line. The whole outline is kept - NOT clipped to a box - so the
shape is the true country border, not a rectangle-sliced fragment.

Runs offline (open network). Upload both files next to index.html.

Usage:
  pip install requests shapely
  python build_borders.py
"""

import json
import sys

try:
    import requests
except ImportError:
    requests = None
from shapely.geometry import shape, mapping
from shapely.ops import unary_union

COUNTRIES = {"MWI": "Malawi", "ZMB": "Zambia"}

ADM0_SIMPLIFY = 0.008   # ~800m; country outline needs only modest detail
ADM1_SIMPLIFY = 0.012   # provinces can be coarser still

GEOB_API = "https://www.geoboundaries.org/api/current/gbOpen/{iso}/{adm}/"


def http_get(url):
    r = requests.get(url, timeout=90, headers={"User-Agent": "MAZA-viewer/1.0"})
    r.raise_for_status()
    return r


def fetch_level(iso, adm):
    """Return the list of GeoJSON features for a country at an admin level."""
    meta = http_get(GEOB_API.format(iso=iso, adm=adm)).json()
    gj_url = meta.get("simplifiedGeometryGeoJSON") or meta.get("gjDownloadURL")
    if not gj_url:
        raise RuntimeError(f"no geojson url for {iso} {adm}")
    return http_get(gj_url).json().get("features", [])


def build_adm0():
    features = []
    for iso, name in COUNTRIES.items():
        print(f"Fetching outline: {name} ({iso}) ADM0 ...")
        try:
            feats = fetch_level(iso, "ADM0")
            geom = unary_union([shape(f["geometry"]) for f in feats])
            geom = geom.simplify(ADM0_SIMPLIFY, preserve_topology=True)
            features.append({
                "type": "Feature",
                "properties": {"name": name, "iso": iso},
                "geometry": mapping(geom),
            })
            print(f"  ok ({len(json.dumps(mapping(geom)))} bytes)")
        except Exception as e:
            print(f"  failed: {e}")
    return features


def build_adm1():
    features = []
    for iso, name in COUNTRIES.items():
        print(f"Fetching provinces: {name} ({iso}) ADM1 ...")
        try:
            feats = fetch_level(iso, "ADM1")
            for f in feats:
                geom = shape(f["geometry"]).simplify(ADM1_SIMPLIFY, preserve_topology=True)
                features.append({
                    "type": "Feature",
                    "properties": {
                        "name": f.get("properties", {}).get("shapeName", ""),
                        "country": name, "iso": iso,
                    },
                    "geometry": mapping(geom),
                })
            print(f"  ok ({len(feats)} provinces)")
        except Exception as e:
            print(f"  failed: {e}")
    return features


def write(path, features, attribution):
    fc = {"type": "FeatureCollection", "attribution": attribution, "features": features}
    with open(path, "w") as f:
        json.dump(fc, f)
    print(f"Wrote {path}: {len(features)} features, {round(len(json.dumps(fc))/1024,1)} KB")


def main():
    if requests is None:
        raise SystemExit("pip install requests shapely")
    attr = "Boundaries \u00a9 geoBoundaries (CC-BY 4.0), William & Mary geoLab."
    adm0 = build_adm0()
    write("borders.geojson", adm0, attr)
    adm1 = build_adm1()
    write("provinces.geojson", adm1, attr)
    print("\nUpload borders.geojson and provinces.geojson next to index.html.")


if __name__ == "__main__":
    main()
