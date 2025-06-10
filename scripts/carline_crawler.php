<?php
/**
 * PHP Crawler for TNEPB Car Line Data
 * Fetches route data for individual vehicles from getcarline API
 */

class CarlineCrawler {
    private $apiUrl = 'https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/getcarline';
    private $carlineBaseDir;
    private $carsDataFile;
    
    public function __construct() {
        // Auto-detect absolute paths
        $rawDir = dirname(__DIR__) . '/raw/';
        $this->carlineBaseDir = $rawDir . 'carline/';
        $this->carsDataFile = $rawDir . 'NewgetCarsinfo.json';
        
        // Create carline base directory if it doesn't exist
        if (!is_dir($this->carlineBaseDir)) {
            mkdir($this->carlineBaseDir, 0755, true);
        }
        
        // Create subdirectories for each clearsec value
        for ($i = 1; $i <= 3; $i++) {
            $subDir = $this->carlineBaseDir . "clearsec$i/";
            if (!is_dir($subDir)) {
                mkdir($subDir, 0755, true);
            }
        }
    }
    
    /**
     * Get all car licenses from the main cars data file
     */
    public function getCarLicenses() {
        if (!file_exists($this->carsDataFile)) {
            throw new Exception("Cars data file not found: " . $this->carsDataFile);
        }
        
        $jsonData = json_decode(file_get_contents($this->carsDataFile), true);
        if (!$jsonData || !isset($jsonData['DATA'])) {
            throw new Exception("Invalid cars data format");
        }
        
        $licenses = [];
        foreach ($jsonData['DATA'] as $car) {
            if (!empty($car['car_licence'])) {
                $licenses[] = [
                    'car_licence' => $car['car_licence'],
                    'cartype' => $car['cartype'] ?? 'N'
                ];
            }
        }
        
        return array_unique($licenses, SORT_REGULAR);
    }
    
    /**
     * Fetch carline data for a specific vehicle
     */
    public function fetchCarlineData($carLicence, $cartype = 'N', $clearsec = '3') {
        $ch = curl_init();
        
        // Set headers to match the original request
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
        
        // Set cookies
        $cookies = '_ga=GA1.3.256741121.1747286731; ASP.NET_SessionId=v5zdovyrvvylrzq4r4arny3s; _gid=GA1.3.2116117646.1749541163; _gat=1; _ga_LHPJ1Q7RMW=GS2.3.s1749541163$o2$g1$t1749543145$j60$l0$h0';
        
        // Prepare POST data
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
    
    /**
     * Save carline data to file
     */
    public function saveCarlineData($carLicence, $data, $clearsec = '3') {
        $decodedData = json_decode($data, true);
        
        // Extract linename from the data for filename
        $linename = 'unknown';
        if ($decodedData && isset($decodedData['d'])) {
            $innerData = json_decode($decodedData['d'], true);
            if ($innerData && isset($innerData['DATA']) && is_array($innerData['DATA']) && !empty($innerData['DATA'])) {
                // Check if this is a NODATA response
                if (isset($innerData['DATA'][0]['seq']) && $innerData['DATA'][0]['seq'] === 'NODATA') {
                    // Skip saving if response is NODATA
                    return false;
                }
                $linename = $innerData['DATA'][0]['linename'] ?? 'unknown';
            }
        }
        
        // Sanitize filename (only replace filesystem-unsafe characters)
        $linename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $linename);
        
        // Create filename with clearsec subdirectory
        $subDir = $this->carlineBaseDir . "clearsec$clearsec/";
        $filename = $subDir . $linename . '.json';
        
        if ($decodedData && isset($decodedData['d'])) {
            // Parse the 'd' property which contains JSON string
            $innerData = json_decode($decodedData['d'], true);
            if ($innerData) {
                $jsonContent = json_encode($innerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                // If 'd' property can't be parsed, save the raw 'd' content
                $jsonContent = $decodedData['d'];
            }
        } else {
            // Fallback to original data
            $jsonContent = $data;
        }
        
        $result = file_put_contents($filename, $jsonContent);
        
        if ($result === false) {
            throw new Exception("Failed to save carline file: " . $filename);
        }
        
        return $filename;
    }
    
    /**
     * Run the carline crawler for all vehicles
     */
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
                
                // Fetch data for each clearsec value
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
                        
                        // Small delay to avoid overwhelming the server
                        usleep(100000); // 0.1 second
                        
                    } catch (Exception $e) {
                        echo "  Error for $carLicence clearsec=$clearsec: " . $e->getMessage() . "\n";
                        $errorCount++;
                    }
                }
            }
            
            echo "\nCarline crawler completed:\n";
            echo "Success: $successCount\n";
            echo "Errors: $errorCount\n";
            
            return $errorCount === 0;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Run carline crawler for a single vehicle
     */
    public function runSingle($carLicence, $cartype = 'N', $clearsec = null) {
        try {
            // If no clearsec specified, fetch all values
            $clearsecValues = $clearsec ? [$clearsec] : ['1', '2', '3'];
            
            foreach ($clearsecValues as $currentClearsec) {
                echo "Fetching carline for $carLicence with clearsec=$currentClearsec...\n";
                
                $data = $this->fetchCarlineData($carLicence, $cartype, $currentClearsec);
                $filename = $this->saveCarlineData($carLicence, $data, $currentClearsec);
                
                if ($filename === false) {
                    echo "Skipped: $carLicence clearsec=$currentClearsec (NODATA)\n";
                } else {
                    echo "Saved: $filename\n";
                    
                    // Display summary if data contains route points
                    $jsonData = json_decode($data, true);
                    if ($jsonData && isset($jsonData['d'])) {
                        $innerData = json_decode($jsonData['d'], true);
                        if ($innerData && isset($innerData['DATA']) && is_array($innerData['DATA'])) {
                            echo "Route points: " . count($innerData['DATA']) . "\n";
                        }
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Handle command line arguments
if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    $crawler = new CarlineCrawler();
    
    // Check for command line arguments
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