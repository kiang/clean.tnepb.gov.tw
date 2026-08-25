<?php
/**
 * Build structured data repository from raw crawler data
 *
 * Reads raw/ and produces data/ with:
 *   data/vehicles.json          - all vehicle positions (from NewgetCarsinfo)
 *   data/vehicles.geojson       - GeoJSON FeatureCollection of vehicle positions
 *   data/routes/{linename}.json - route stop data per line (from carline)
 *   data/routes.json            - index of all routes with metadata
 *   data/meta.json              - build metadata (timestamp, counts)
 */

$rawDir = dirname(__DIR__) . '/raw/';
$dataDir = dirname(__DIR__) . '/data/';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
if (!is_dir($dataDir . 'routes/')) {
    mkdir($dataDir . 'routes/', 0755, true);
}

$carsFile = $rawDir . 'NewgetCarsinfo.json';
if (!file_exists($carsFile)) {
    echo "Error: $carsFile not found. Run crawler.php first.\n";
    exit(1);
}

// Build vehicles.json
$carsData = json_decode(file_get_contents($carsFile), true);
$vehicles = $carsData['DATA'] ?? [];

$cleanVehicles = [];
foreach ($vehicles as $v) {
    $cleanVehicles[] = [
        'car_licence' => $v['car_licence'],
        'caption' => $v['caption'],
        'dt' => $v['dt'],
        'lng' => (float)$v['x'],
        'lat' => (float)$v['y'],
        'direction' => $v['direct'],
        'status' => $v['status'],
        'cartype' => $v['cartype'],
        'car_id' => $v['car_id'],
        'rcar_licence' => $v['rcar_licence'],
    ];
}

file_put_contents(
    $dataDir . 'vehicles.json',
    json_encode($cleanVehicles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "vehicles.json: " . count($cleanVehicles) . " vehicles\n";

// Build vehicles.geojson
$features = [];
foreach ($cleanVehicles as $v) {
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$v['lng'], $v['lat']],
        ],
        'properties' => [
            'car_licence' => $v['car_licence'],
            'caption' => $v['caption'],
            'dt' => $v['dt'],
            'direction' => $v['direction'],
            'status' => $v['status'],
            'cartype' => $v['cartype'],
        ],
    ];
}

$geojson = [
    'type' => 'FeatureCollection',
    'features' => $features,
];
file_put_contents(
    $dataDir . 'vehicles.geojson',
    json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "vehicles.geojson: " . count($features) . " features\n";

// Build routes from carline data
$routeIndex = [];
$routeCount = 0;
$clearsecDirs = ['clearsec1', 'clearsec2', 'clearsec3'];

foreach ($clearsecDirs as $clearsecDir) {
    $carlineDir = $rawDir . 'carline/' . $clearsecDir . '/';
    if (!is_dir($carlineDir)) {
        continue;
    }

    $clearsec = str_replace('clearsec', '', $clearsecDir);

    foreach (glob($carlineDir . '*.json') as $file) {
        $linename = basename($file, '.json');
        $rawData = json_decode(file_get_contents($file), true);

        if (!$rawData || !isset($rawData['DATA']) || empty($rawData['DATA'])) {
            continue;
        }

        $stops = [];
        $areas = [];
        foreach ($rawData['DATA'] as $stop) {
            $stops[] = [
                'seq' => $stop['seq'] ?? '',
                'area' => $stop['area'] ?? '',
                'village' => $stop['village'] ?? '',
                'caption' => $stop['caption'] ?? '',
                'lng' => isset($stop['wgs_x']) && $stop['wgs_x'] !== '' ? (float)$stop['wgs_x'] : null,
                'lat' => isset($stop['wgs_y']) && $stop['wgs_y'] !== '' ? (float)$stop['wgs_y'] : null,
                'task_type' => $stop['task_type'] ?? '',
                'estimated_time' => $stop['estimatedtime'] ?? '',
                'days' => $stop['g_day'] ?? '',
                'car_licence' => $stop['car_licence'] ?? '',
            ];
            if (!empty($stop['area'])) {
                $areas[$stop['area']] = true;
            }
        }

        $routeFile = $linename . '_' . $clearsec . '.json';
        $routeData = [
            'linename' => $linename,
            'clearsec' => $clearsec,
            'stop_count' => count($stops),
            'stops' => $stops,
        ];

        file_put_contents(
            $dataDir . 'routes/' . $routeFile,
            json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $routeIndex[] = [
            'linename' => $linename,
            'clearsec' => $clearsec,
            'file' => 'routes/' . $routeFile,
            'stop_count' => count($stops),
            'areas' => array_keys($areas),
        ];
        $routeCount++;
    }
}

usort($routeIndex, function ($a, $b) {
    $cmp = strcmp($a['linename'], $b['linename']);
    if ($cmp !== 0) return $cmp;
    return strcmp($a['clearsec'], $b['clearsec']);
});

file_put_contents(
    $dataDir . 'routes.json',
    json_encode($routeIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "routes.json: $routeCount routes indexed\n";

// Build metadata
$meta = [
    'built_at' => date('c'),
    'vehicle_count' => count($cleanVehicles),
    'route_count' => $routeCount,
    'source' => 'https://clean.tnepb.gov.tw',
];

$firstDt = null;
foreach ($cleanVehicles as $v) {
    if (!empty($v['dt'])) {
        if ($firstDt === null || $v['dt'] < $firstDt) {
            $firstDt = $v['dt'];
        }
    }
}
if ($firstDt) {
    $meta['data_date'] = substr($firstDt, 0, 10);
}

file_put_contents(
    $dataDir . 'meta.json',
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "meta.json: built at " . $meta['built_at'] . "\n";
echo "\nDone. Data repository built in data/\n";
