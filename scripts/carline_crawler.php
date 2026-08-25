<?php
/**
 * PHP Crawler for TNEPB Car Line Data
 * Fetches route data for individual vehicles from getcarline API
 * and writes processed output directly to data/routes/
 */

class CarlineCrawler {
    private $apiUrl = 'https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/getcarline';
    private $dataDir;
    private $routesDir;

    public function __construct() {
        $this->dataDir = dirname(__DIR__) . '/data/';
        $this->routesDir = $this->dataDir . 'routes/';

        if (!is_dir($this->routesDir)) {
            mkdir($this->routesDir, 0755, true);
        }
    }

    public function getCarLicenses() {
        $vehiclesFile = $this->dataDir . 'vehicles.json';
        if (!file_exists($vehiclesFile)) {
            throw new Exception("Vehicles data file not found: $vehiclesFile. Run crawler.php first.");
        }

        $vehicles = json_decode(file_get_contents($vehiclesFile), true);
        if (!$vehicles) {
            throw new Exception("Invalid vehicles data format");
        }

        $licenses = [];
        foreach ($vehicles as $car) {
            if (!empty($car['car_licence'])) {
                $licenses[] = [
                    'car_licence' => $car['car_licence'],
                    'cartype' => $car['cartype'] ?? 'N'
                ];
            }
        }

        return array_unique($licenses, SORT_REGULAR);
    }

    public function fetchCarlineData($carLicence, $cartype = 'N', $clearsec = '3') {
        $ch = curl_init();

        $headers = [
            'Accept: */*',
            'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Content-Type: application/json; charset=UTF-8',
            'Origin: https://clean.tnepb.gov.tw',
            'Pragma: no-cache',
            'Priority: u=1, i',
            'Referer: https://clean.tnepb.gov.tw/index.aspx',
            'Sec-CH-UA: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "Linux"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'X-Requested-With: XMLHttpRequest'
        ];

        $cookies = '_ga=GA1.3.256741121.1747286731; ASP.NET_SessionId=v5zdovyrvvylrzq4r4arny3s; _gid=GA1.3.2116117646.1749541163; _gat=1; _ga_LHPJ1Q7RMW=GS2.3.s1749541163$o2$g1$t1749543145$j60$l0$h0';

        $postData = json_encode([
            'car_licence' => $carLicence,
            'clearsec' => $clearsec,
            'cartype' => $cartype
        ]);

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIE => $cookies,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error for $carLicence: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP Error for $carLicence: " . $httpCode);
        }

        return $response;
    }

    private function parseResponse($data) {
        $decodedData = json_decode($data, true);
        if ($decodedData && isset($decodedData['d'])) {
            $innerData = json_decode($decodedData['d'], true);
            if ($innerData) {
                return $innerData;
            }
        }
        return json_decode($data, true);
    }

    public function saveCarlineData($carLicence, $data, $clearsec = '3') {
        $parsedData = $this->parseResponse($data);

        if (!$parsedData || !isset($parsedData['DATA']) || empty($parsedData['DATA'])) {
            return false;
        }

        if (isset($parsedData['DATA'][0]['seq']) && $parsedData['DATA'][0]['seq'] === 'NODATA') {
            return false;
        }

        $linename = $parsedData['DATA'][0]['linename'] ?? 'unknown';
        $linename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $linename);

        $stops = [];
        $areas = [];
        foreach ($parsedData['DATA'] as $stop) {
            $stops[] = [
                'seq' => $stop['seq'] ?? '',
                'area' => $stop['area'] ?? '',
                'village' => $stop['village'] ?? '',
                'caption' => $stop['caption'] ?? '',
                'lng' => isset($stop['x']) && $stop['x'] !== '' ? (float)$stop['x'] : null,
                'lat' => isset($stop['y']) && $stop['y'] !== '' ? (float)$stop['y'] : null,
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
            $this->routesDir . $routeFile,
            json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this->routesDir . $routeFile;
    }

    private function rebuildRoutesIndex() {
        $routeIndex = [];
        foreach (glob($this->routesDir . '*.json') as $file) {
            $routeData = json_decode(file_get_contents($file), true);
            if (!$routeData || !isset($routeData['stops'])) {
                continue;
            }
            $areas = [];
            foreach ($routeData['stops'] as $stop) {
                if (!empty($stop['area'])) {
                    $areas[$stop['area']] = true;
                }
            }
            $routeIndex[] = [
                'linename' => $routeData['linename'],
                'clearsec' => $routeData['clearsec'],
                'file' => 'routes/' . basename($file),
                'stop_count' => $routeData['stop_count'],
                'areas' => array_keys($areas),
            ];
        }

        usort($routeIndex, function ($a, $b) {
            $cmp = strcmp($a['linename'], $b['linename']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['clearsec'], $b['clearsec']);
        });

        file_put_contents(
            $this->dataDir . 'routes.json',
            json_encode($routeIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        echo "routes.json: " . count($routeIndex) . " routes indexed\n";

        // Update meta
        $metaFile = $this->dataDir . 'meta.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
        $meta['route_count'] = count($routeIndex);
        $meta['routes_updated_at'] = date('c');
        file_put_contents(
            $metaFile,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function commitData() {
        $gitDir = $this->dataDir . '.git';
        if (!is_dir($gitDir)) {
            return;
        }
        $metaFile = $this->dataDir . 'meta.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
        $datetime = date('Y-m-d H:i:s');
        chdir($this->dataDir);
        exec('git add -A');
        exec('git diff --cached --quiet', $output, $exitCode);
        if ($exitCode !== 0) {
            exec('git commit -m "Update routes ' . $datetime . '"');
            echo "Data repo committed.\n";
        } else {
            echo "Data repo: no changes to commit.\n";
        }
    }

    public function runAll() {
        try {
            echo "Starting carline crawler...\n";

            $licenses = $this->getCarLicenses();
            echo "Found " . count($licenses) . " unique vehicles\n";

            $successCount = 0;
            $errorCount = 0;
            $clearsecValues = ['1', '2', '3'];

            foreach ($licenses as $index => $carInfo) {
                $carLicence = $carInfo['car_licence'];
                $cartype = $carInfo['cartype'];

                echo "Processing $carLicence (" . ($index + 1) . "/" . count($licenses) . ")...\n";

                foreach ($clearsecValues as $clearsec) {
                    try {
                        echo "  Fetching clearsec=$clearsec for $carLicence...\n";

                        $data = $this->fetchCarlineData($carLicence, $cartype, $clearsec);
                        $filename = $this->saveCarlineData($carLicence, $data, $clearsec);

                        if ($filename === false) {
                            echo "  Skipped: $carLicence clearsec=$clearsec (NODATA)\n";
                        } else {
                            echo "  Saved: $filename\n";
                            $successCount++;
                        }

                        usleep(100000);

                    } catch (Exception $e) {
                        echo "  Error for $carLicence clearsec=$clearsec: " . $e->getMessage() . "\n";
                        $errorCount++;
                    }
                }
            }

            echo "\nCarline crawler completed:\n";
            echo "Success: $successCount\n";
            echo "Errors: $errorCount\n";

            $this->rebuildRoutesIndex();
            $this->commitData();

            return $errorCount === 0;

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function runSingle($carLicence, $cartype = 'N', $clearsec = null) {
        try {
            $clearsecValues = $clearsec ? [$clearsec] : ['1', '2', '3'];

            foreach ($clearsecValues as $currentClearsec) {
                echo "Fetching carline for $carLicence with clearsec=$currentClearsec...\n";

                $data = $this->fetchCarlineData($carLicence, $cartype, $currentClearsec);
                $filename = $this->saveCarlineData($carLicence, $data, $currentClearsec);

                if ($filename === false) {
                    echo "Skipped: $carLicence clearsec=$currentClearsec (NODATA)\n";
                } else {
                    echo "Saved: $filename\n";
                }
            }

            $this->rebuildRoutesIndex();
            $this->commitData();

            return true;

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    $crawler = new CarlineCrawler();

    if ($argc > 1) {
        $carLicence = $argv[1];
        $cartype = isset($argv[2]) ? $argv[2] : 'N';
        $clearsec = isset($argv[3]) ? $argv[3] : null;
        $success = $crawler->runSingle($carLicence, $cartype, $clearsec);
    } else {
        $success = $crawler->runAll();
    }

    exit($success ? 0 : 1);
}
?>
