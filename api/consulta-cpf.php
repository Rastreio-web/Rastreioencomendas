<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Configuração de Ambiente
define('MAX_ATTEMPTS', 3);
define('REQUEST_DELAY', 2);
$logFile = __DIR__ . '/scraper_pro.log';

// 2. Sistema de Logs Aprimorado
function logEvent($message, $level = 'INFO') {
    global $logFile;
    $logMsg = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}

// 3. Classe de Requisição com Proxy e Rotação
class ProfessionalScraper {
    private static $proxyList = [
        // Adicione proxies válidos aqui (exemplo educativo)
        '45.61.123.219:3128',
        '103.156.144.5:8080'
    ];
    
    public static function fetchWithRetry($url, $data) {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < MAX_ATTEMPTS) {
            $attempt++;
            try {
                $result = self::smartRequest($url, $data, $attempt);
                
                if ($result['status'] === 'success') {
                    return $result;
                }
                
                $lastError = $result['error'];
                logEvent("Tentativa $attempt falhou: " . $lastError, "WARNING");
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                logEvent("Erro na tentativa $attempt: " . $lastError, "ERROR");
            }
            
            if ($attempt < MAX_ATTEMPTS) {
                sleep(REQUEST_DELAY * $attempt); // Backoff exponencial
            }
        }
        
        throw new Exception("Todas as tentativas falharam. Último erro: " . $lastError);
    }
    
    private static function smartRequest($url, $data, $attempt) {
        $methods = ['curlPro', 'guzzlePro'];
        $method = $methods[$attempt % count($methods)];
        
        return self::$method($url, $data, $attempt);
    }
    
    private static function curlPro($url, $data, $attempt) {
        $ch = curl_init();
        
        // Configuração de Proxy (se disponível)
        if (!empty(self::$proxyList)) {
            $proxy = self::$proxyList[$attempt % count(self::$proxyList)];
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
        
        // Configuração avançada
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_COOKIEFILE => __DIR__ . '/cookies.txt',
            CURLOPT_COOKIEJAR => __DIR__ . '/cookies.txt',
            CURLOPT_HTTPHEADER => self::generateHeaders($attempt),
            CURLOPT_USERAGENT => self::randomUserAgent(),
            CURLOPT_REFERER => 'https://www.descobreaqui.com',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode == 403 || $httpCode == 429) {
            return [
                'status' => 'error',
                'error' => "Bloqueio detectado (HTTP $httpCode)"
            ];
        }
        
        if ($httpCode != 200 || empty($response)) {
            return [
                'status' => 'error',
                'error' => "HTTP $httpCode - " . substr($error, 0, 100)
            ];
        }
        
        return [
            'status' => 'success',
            'content' => $response,
            'http_code' => $httpCode
        ];
    }
    
    private static function guzzlePro($url, $data, $attempt) {
        if (!class_exists('GuzzleHttp\Client')) {
            throw new Exception("Guzzle não instalado");
        }
        
        $client = new \GuzzleHttp\Client([
            'timeout' => 15,
            'verify' => false,
            'headers' => self::generateHeaders($attempt),
            'proxy' => !empty(self::$proxyList) ? 
                self::$proxyList[$attempt % count(self::$proxyList)] : null
        ]);
        
        try {
            $response = $client->post($url, [
                'form_params' => $data
            ]);
            
            return [
                'status' => 'success',
                'content' => $response->getBody()->getContents(),
                'http_code' => $response->getStatusCode()
            ];
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return [
                'status' => 'error',
                'error' => "Guzzle: " . $e->getMessage()
            ];
        }
    }
    
    private static function generateHeaders($attempt) {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Requested-With' => 'XMLHttpRequest',
            'Origin' => 'https://www.descobreaqui.com',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache'
        ];
        
        // Rotaciona headers baseado na tentativa
        if ($attempt % 2 == 0) {
            $headers['Accept'] = 'application/json, text/javascript';
            $headers['X-Forwarded-For'] = self::randomIp();
        }
        
        return array_map(function($k, $v) { return "$k: $v"; }, 
                       array_keys($headers), $headers);
    }
    
    private static function randomUserAgent() {
        $agents = [
            // Chrome Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
            // Firefox Mac
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/113.0',
            // Safari iOS
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1'
        ];
        
        return $agents[array_rand($agents)];
    }
    
    private static function randomIp() {
        return mt_rand(1,255) . '.' . mt_rand(0,255) . '.' . mt_rand(0,255) . '.' . mt_rand(0,255);
    }
}

// 4. Validação de CPF (implementação completa)
function validateCpf($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

// 5. Processamento Principal
try {
    $cpf = $_GET['cpf'] ?? '';
    
    if (!validateCpf($cpf)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'CPF inválido',
            'received' => $cpf
        ]);
        exit;
    }
    
    logEvent("Iniciando consulta para CPF: $cpf", "INFO");
    
    $url = "https://www.descobreaqui.com/search";
    $postData = [
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => bin2hex(random_bytes(8)),
        'rand' => mt_rand(100000, 999999)
    ];
    
    $result = ProfessionalScraper::fetchWithRetry($url, $postData);
    
    // Análise do HTML (simplificada para exemplo)
    $dom = new DOMDocument();
    @$dom->loadHTML($result['content']);
    $xpath = new DOMXPath($dom);
    
    $nome = $xpath->query("//div[contains(@class,'user-name')]")->item(0)->nodeValue ?? 'Não encontrado';
    $nascimento = $xpath->query("//span[contains(@class,'birth-date')]")->item(0)->nodeValue ?? 'Não encontrado';
    
    echo json_encode([
        'success' => true,
        'data' => [
            'cpf' => $cpf,
            'nome' => trim($nome),
            'nascimento' => trim($nascimento)
        ],
        'metadata' => [
            'http_code' => $result['http_code'],
            'attempts' => MAX_ATTEMPTS
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logEvent("Falha crítica: " . $e->getMessage(), "CRITICAL");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na consulta: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug' => [
            'cpf' => $cpf ?? null,
            'system' => php_uname(),
            'timestamp' => time()
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}