<?php
/**
 * PHP Crawler for TNEPB Clean Vehicle Data
 * Fetches data from https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/NewgetCarsinfo
 * and writes processed output directly to data/
 */

class TNEPBCrawler {
    private $apiUrl = 'https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/NewgetCarsinfo';
    private $dataDir;

    public function __construct() {
        $this->dataDir = dirname(__DIR__) . '/data/';
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function fetchData() {
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

        $cookies = '_ga=GA1.3.256741121.1747286731; ASP.NET_SessionId=v5zdovyrvvylrzq4r4arny3s; _gid=GA1.3.2116117646.1749541163; _gat=1; _ga_LHPJ1Q7RMW=GS2.3.s1749541163$o2$g1$t1749541737$j60$l0$h0';

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
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
            throw new Exception("cURL Error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: " . $httpCode);
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

    private function saveData($parsedData) {
        $vehicles = $parsedData['DATA'] ?? [];

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
            $this->dataDir . 'vehicles.json',
            json_encode($cleanVehicles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        echo "vehicles.json: " . count($cleanVehicles) . " vehicles\n";

        // Build GeoJSON
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

        file_put_contents(
            $this->dataDir . 'vehicles.geojson',
            json_encode([
                'type' => 'FeatureCollection',
                'features' => $features,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        echo "vehicles.geojson: " . count($features) . " features\n";

        // Update meta
        $metaFile = $this->dataDir . 'meta.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
        $meta['vehicle_count'] = count($cleanVehicles);
        $meta['source'] = 'https://clean.tnepb.gov.tw';
        $meta['vehicles_updated_at'] = date('c');

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
            $metaFile,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return count($cleanVehicles);
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
            exec('git commit -m "Update vehicles ' . $datetime . '"');
            echo "Data repo committed.\n";
        } else {
            echo "Data repo: no changes to commit.\n";
        }
    }

    public function run() {
        try {
            echo "Starting TNEPB data crawler...\n";

            $data = $this->fetchData();
            echo "Data fetched successfully (" . strlen($data) . " bytes)\n";

            $parsedData = $this->parseResponse($data);
            $count = $this->saveData($parsedData);
            echo "Records count: $count\n";

            $this->commitData();

            return true;

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    $crawler = new TNEPBCrawler();
    $success = $crawler->run();
    exit($success ? 0 : 1);
}
?>
