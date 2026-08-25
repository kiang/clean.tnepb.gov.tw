# clean.tnepb.gov.tw

台南市環保局清潔車輛追蹤資料抓取與儲存系統。

Crawls vehicle position and route data from the [Tainan EPA clean vehicle tracking system](https://clean.tnepb.gov.tw) and stores processed results in a separate `data/` git repository.

## Repository structure

```
clean.tnepb.gov.tw/          # scripts repo (git@github.com:kiang/clean.tnepb.gov.tw.git)
├── scripts/
│   ├── crawler.php           # fetches vehicle positions → data/vehicles.json, data/vehicles.geojson
│   ├── carline_crawler.php   # fetches route/stop data  → data/routes/*.json, data/routes.json
│   └── cron.sh               # scheduler entry point
├── data/                     # data repo (git@github.com:kiang/clean.tnepb.gov.tw_data.git)
│   ├── vehicles.json         # all vehicle positions (cleaned)
│   ├── vehicles.geojson      # GeoJSON FeatureCollection of vehicle positions
│   ├── routes.json           # index of all routes with metadata
│   ├── routes/               # one JSON file per route per clearsec
│   │   ├── 中區-3_2.json
│   │   ├── 中區-3_3.json
│   │   └── ...
│   └── meta.json             # build metadata (timestamps, counts)
├── .gitignore                # excludes data/ from scripts repo
└── README.md
```

The two repos are independent — the scripts repo tracks code, the data repo tracks output.

## Data sources

| API endpoint | Script | Output |
|---|---|---|
| `WsSkyeyes.asmx/NewgetCarsinfo` | `crawler.php` | `vehicles.json`, `vehicles.geojson` |
| `WsSkyeyes.asmx/getcarline` | `carline_crawler.php` | `routes/*.json`, `routes.json` |

## Schedule

| Script | Frequency | Description |
|---|---|---|
| `crawler.php` | Every 5 minutes | Fetches current vehicle positions |
| `carline_crawler.php` | Once per Tuesday | Fetches all route/stop data (runs once, skips remaining Tuesday executions via lock file) |

## Setup

### 1. Clone

```bash
git clone git@github.com:kiang/clean.tnepb.gov.tw.git
cd clean.tnepb.gov.tw
git clone git@github.com:kiang/clean.tnepb.gov.tw_data.git data
```

### 2. Crontab

Add the following entry to run every 5 minutes:

```crontab
*/5 * * * * /home/kiang/public_html/clean.tnepb.gov.tw/scripts/cron.sh
```

### 3. Verify

```bash
# Manual test run
./scripts/cron.sh

# Check data repo
cd data && git log --oneline -5
```

## How cron.sh works

1. `git pull` the scripts repo to get latest code changes
2. Run `crawler.php` — fetches vehicle data, writes to `data/`, auto-commits
3. On Tuesdays only (first run of the day):
   - Run `carline_crawler.php` — fetches all route data, writes to `data/`, auto-commits
   - Creates `data/.carline_done` lock file to prevent re-runs
   - Lock file is removed on the next non-Tuesday execution
4. Push the data repo to remote

## Data format

### vehicles.json

```json
[
  {
    "car_licence": "531-US",
    "caption": "自強里 富農街一段153巷52號(資源回收處)",
    "dt": "2025-06-10 16:35:49",
    "lng": 120.233802,
    "lat": 22.98225,
    "direction": "→",
    "status": "0",
    "cartype": "N",
    "car_id": "976594163",
    "rcar_licence": ""
  }
]
```

### routes/{linename}_{clearsec}.json

```json
{
  "linename": "中區-3",
  "clearsec": "3",
  "stop_count": 46,
  "stops": [
    {
      "seq": "13880",
      "area": "中西區",
      "village": "赤嵌里",
      "caption": "民權路二段141號",
      "lng": 120.204342,
      "lat": 22.996138,
      "task_type": "沿街",
      "estimated_time": "18:44~18:54",
      "days": "二.六.",
      "car_licence": "KEA-1001"
    }
  ]
}
```

### meta.json

```json
{
  "vehicle_count": 141,
  "route_count": 333,
  "source": "https://clean.tnepb.gov.tw",
  "data_date": "2026-08-25",
  "vehicles_updated_at": "2026-08-25T07:49:25+00:00",
  "routes_updated_at": "2026-08-25T07:51:49+00:00"
}
```

## Troubleshooting

### Cookies expired

Both crawlers use hardcoded session cookies. If the API starts returning errors, visit https://clean.tnepb.gov.tw/index.aspx in a browser, copy fresh cookies from DevTools, and update the `$cookies` variable in both `crawler.php` and `carline_crawler.php`.

### Force re-run carline crawler

Delete the lock file and run cron.sh again (must be Tuesday, or run the crawler directly):

```bash
rm -f data/.carline_done
php scripts/carline_crawler.php
```

### Check data repo status

```bash
cd data
git log --oneline -10
cat meta.json
```
