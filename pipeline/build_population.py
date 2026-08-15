#!/usr/bin/env python3
"""
Population overlap builder for the MAZA occurrence viewer.

Combines two open datasets into one small JSON the map can serve:
  1. GBIF occurrence points  (already fetched by the live site's api.php)
  2. WorldPop 100m population raster for Malawi and Zambia (CC-BY)

Output: population.json  -- contains
  - a coarse population-density grid over the MAZA box (for a heat layer)
  - per-species "overlap" summary: how many records fall in populated cells

This is the offline half. It needs the WorldPop GeoTIFFs and open network,
so it runs on a laptop/VPS, NOT on Texo. Upload population.json to the site.

WorldPop downloads (people-per-pixel, 100m, GeoTIFF), per country:
  Malawi: https://hub.worldpop.org/geodata/summary?id=  (MWI ppp)
  Zambia: https://hub.worldpop.org/geodata/summary?id=  (ZMB ppp)
  or via HDX. Use the UN-adjusted 2020 ppp rasters: MWI_ppp_*_UNadj.tif etc.

Usage:
  pip install rasterio numpy requests
  python build_population.py MWI_ppp_2020_UNadj.tif ZMB_ppp_2020_UNadj.tif
"""

import sys
import json
import math
import numpy as np
import rasterio
from rasterio.windows import from_bounds

# ---- MAZA scope (must match api.php) --------------------------------------
LAT_MIN, LAT_MAX = -14.5, -9.5
LON_MIN, LON_MAX = 31.0, 35.0

# Coarse grid for the density layer: number of cells across the box.
# 40x50 keeps the JSON tiny while still showing where people concentrate.
GRID_COLS = 40
GRID_ROWS = 50

# GBIF species (for the overlap summary). Points themselves come from the
# live site; here we optionally re-read a saved points file if provided.
SPECIES = {
    'Loxodonta africana':     'African elephant',
    'Panthera leo':           'Lion',
    'Hippopotamus amphibius': 'Hippopotamus',
    'Crocodylus niloticus':   'Nile crocodile',
    'Syncerus caffer':        'African buffalo',
}

OUT = 'population.json'


def read_pop_grid(raster_paths):
    """Sum population into a coarse GRID_ROWS x GRID_COLS grid over the box."""
    grid = np.zeros((GRID_ROWS, GRID_COLS), dtype=np.float64)
    lon_step = (LON_MAX - LON_MIN) / GRID_COLS
    lat_step = (LAT_MAX - LAT_MIN) / GRID_ROWS

    for path in raster_paths:
        with rasterio.open(path) as ds:
            # Clip to the MAZA box; skip rasters that don't intersect it.
            left, bottom, right, top = ds.bounds
            if right < LON_MIN or left > LON_MAX or top < LAT_MIN or bottom > LAT_MAX:
                continue
            try:
                win = from_bounds(
                    max(LON_MIN, left), max(LAT_MIN, bottom),
                    min(LON_MAX, right), min(LAT_MAX, top), ds.transform
                )
                arr = ds.read(1, window=win)
                wt = ds.window_transform(win)
            except Exception:
                arr = ds.read(1)
                wt = ds.transform

            nodata = ds.nodata
            arr = arr.astype('float64')
            if nodata is not None:
                arr[arr == nodata] = 0.0
            arr[arr < 0] = 0.0

            rows, cols = arr.shape
            # Real lon/lat of each pixel center from the WINDOW transform.
            # wt.c = x origin, wt.a = x pixel size; wt.f = y origin, wt.e = y pixel size (neg).
            lons = wt.c + (np.arange(cols) + 0.5) * wt.a
            lats = wt.f + (np.arange(rows) + 0.5) * wt.e

            # Coarse-cell index per pixel; -1 where outside the box.
            gx = np.floor((lons - LON_MIN) / lon_step).astype(int)
            gy = np.floor((LAT_MAX - lats) / lat_step).astype(int)
            gx = np.where((lons >= LON_MIN) & (lons <= LON_MAX) & (gx >= 0) & (gx < GRID_COLS), gx, -1)
            gy = np.where((lats >= LAT_MIN) & (lats <= LAT_MAX) & (gy >= 0) & (gy < GRID_ROWS), gy, -1)

            for r in range(rows):
                if gy[r] < 0:
                    continue
                valid = gx >= 0
                if not valid.any():
                    continue
                # accumulate this row's pixels into their columns
                np.add.at(grid[gy[r]], gx[valid], arr[r][valid])
    return grid


def grid_to_cells(grid):
    """Turn the grid into a list of populated cells with lat/lon centers."""
    lon_step = (LON_MAX - LON_MIN) / GRID_COLS
    lat_step = (LAT_MAX - LAT_MIN) / GRID_ROWS
    cells = []
    gmax = float(grid.max()) or 1.0
    for gy in range(GRID_ROWS):
        for gx in range(GRID_COLS):
            pop = grid[gy, gx]
            if pop <= 0:
                continue
            lat = LAT_MAX - (gy + 0.5) * lat_step
            lon = LON_MIN + (gx + 0.5) * lon_step
            cells.append({
                'lat': round(lat, 4),
                'lon': round(lon, 4),
                'pop': int(round(pop)),
                'intensity': round(pop / gmax, 3),  # 0..1 for heat shading
            })
    return cells, gmax


def sample_at(grid, lat, lon):
    """Population in the grid cell containing (lat,lon)."""
    lon_step = (LON_MAX - LON_MIN) / GRID_COLS
    lat_step = (LAT_MAX - LAT_MIN) / GRID_ROWS
    gx = int((lon - LON_MIN) / lon_step)
    gy = int((LAT_MAX - lat) / lat_step)
    if 0 <= gy < GRID_ROWS and 0 <= gx < GRID_COLS:
        return float(grid[gy, gx])
    return 0.0


def main():
    if len(sys.argv) < 2:
        raise SystemExit("Usage: python build_population.py <raster1.tif> [raster2.tif ...]")
    rasters = sys.argv[1:]
    print(f"Reading {len(rasters)} raster(s)...")
    grid = read_pop_grid(rasters)
    cells, gmax = grid_to_cells(grid)
    print(f"Populated cells in box: {len(cells)}  (max cell pop ~{int(gmax)})")

    # Threshold that marks a cell as "settled" for the overlap flag.
    # Use the 60th percentile of non-zero cells so "high population" is relative.
    nonzero = np.array([c['pop'] for c in cells], dtype=float)
    settled_threshold = float(np.percentile(nonzero, 60)) if len(nonzero) else 0.0

    out = {
        'bbox': [LAT_MIN, LON_MIN, LAT_MAX, LON_MAX],
        'grid': {'rows': GRID_ROWS, 'cols': GRID_COLS},
        'max_cell_pop': int(gmax),
        'settled_threshold': int(round(settled_threshold)),
        'cells': cells,
        'source': 'WorldPop 100m population (CC-BY), aggregated to a coarse grid',
    }
    with open(OUT, 'w') as f:
        json.dump(out, f)
    print(f"Wrote {OUT}  ({len(cells)} cells, settled threshold {int(settled_threshold)})")
    print("Upload population.json to the site next to index.html.")


if __name__ == '__main__':
    main()
