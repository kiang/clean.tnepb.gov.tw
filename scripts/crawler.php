<?php
/**
 * PHP Crawler for TNEPB Clean Vehicle Data
 * Backs up data from https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/NewgetCarsinfo
 */

class TNEPBCrawler {
    private $apiUrl = 'https://clean.tnepb.gov.tw/WebService/WsSkyeyes.asmx/NewgetCarsinfo';
    private $backupDir;
    
    public function __construct() {
        // Auto-detect absolute path to raw directory
        $this->backupDir = dirname(__DIR__) . '/raw/';
        
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }
    
    /**
     * Fetch data from the API
     */
    public function fetchData() {
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
        
        // Set cookies (note: these may need to be updated periodically)
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
    
    /**
     * Save data to backup file
     */
    public function saveBackup($data) {
        $filename = $this->backupDir . "NewgetCarsinfo.json";
        
        $decodedData = json_decode($data, true);
        
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
            throw new Exception("Failed to save backup file: " . $filename);
        }
        
        return $filename;
    }
    
    /**
     * Run the crawler
     */
    public function run() {
        try {
            echo "Starting TNEPB data crawler...\n";
            
            $data = $this->fetchData();
            echo "Data fetched successfully (" . strlen($data) . " bytes)\n";
            
            $filename = $this->saveBackup($data);
            echo "Data saved to: " . $filename . "\n";
            
            // Display summary if data is JSON
            $jsonData = json_decode($data, true);
            if ($jsonData && isset($jsonData['d'])) {
                $innerData = json_decode($jsonData['d'], true);
                if ($innerData && isset($innerData['DATA']) && is_array($innerData['DATA'])) {
                    echo "Records count: " . count($innerData['DATA']) . "\n";
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Run the crawler if script is executed directly
if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    $crawler = new TNEPBCrawler();
    $success = $crawler->run();
    if ($success) {
        require_once __DIR__ . '/build_data.php';
    }
    exit($success ? 0 : 1);
}
?>