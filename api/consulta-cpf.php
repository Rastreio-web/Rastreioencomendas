<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Configurações avançadas
define('MAX_ATTEMPTS', 3);
define('REQUEST_DELAY', 5);
$logFile = __DIR__ . '/scraper_advanced.log';

// 2. Sistema de Logs Aprimorado
function logEvent($message, $level = 'INFO') {
    global $logFile;
    $logMsg = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}

// 3. Classe de Requisição com Múltiplas Estratégias
class AdvancedScraper {
    private static $endpoints = [
        'primary' => 'https://www.descobreaqui.com/search',
        'secondary' => 'https://consulta.descobreaqui.com/api',
        'tertiary' => 'https://api.descobreaqui.com/v1/query'
    ];
    
    private static $proxyList = [
        // Proxies gratuitos (podem precisar ser atualizados)
        '45.61.123.219:3128',
        '103.156.144.5:8080',
        '200.105.215.22:33630'
    ];
    
    public static function fetchData($cpf) {
        // Primeiro verifica se o site está online
        if (!self::isSiteAvailable()) {
            throw new Exception("Site principal indisponível ou bloqueando requisições");
        }
        
        // Tenta em todos os endpoints
        foreach (self::$endpoints as $type => $url) {
            try {
                logEvent("Tentando endpoint: $type ($url)", "INFO");
                $result = self::tryEndpoint($url, $cpf);
                
                if ($result['status'] === 'success') {
                    return $result;
                }
                
                logEvent("Endpoint $type falhou: " . $result['error'], "WARNING");
                
            } catch (Exception $e) {
                logEvent("Erro no endpoint $type: " . $e->getMessage(), "ERROR");
            }
            
            sleep(REQUEST_DELAY);
        }
        
        throw new Exception("Todos os endpoints falharam");
    }
    
    private static function isSiteAvailable() {
        $testUrl = 'https://www.descobreaqui.com';
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 10,
                'user_agent' => self::randomUserAgent()
            ],
            'ssl' => [
                'verify_peer' => false
            ]
        ]);
        
        $headers = @get_headers($testUrl, 1, $context);
        
        if ($headers === false) {
            logEvent("Falha ao acessar o site principal", "ERROR");
            return false;
        }
        
        $statusCode = (int)substr($headers[0], 9, 3);
        $available = ($statusCode === 200 || $statusCode === 302);
        
        if (!$available) {
            logEvent("Site retornou código HTTP $statusCode", "WARNING");
        }
        
        return $available;
    }
    
    private static function tryEndpoint($url, $cpf) {
        $methods = ['curlRequest', 'fileGetRequest'];
        $lastError = null;
        
        foreach ($methods as $method) {
            try {
                $result = self::$method($url, $cpf);
                
                if ($result['status'] === 'success') {
                    return $result;
                }
                
                $lastError = $result['error'];
                logEvent("Método $method falhou: $lastError", "DEBUG");
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                logEvent("Erro no método $method: $lastError", "ERROR");
            }
            
            sleep(REQUEST_DELAY / 2);
        }
        
        throw new Exception("Todos os métodos falharam. Último erro: $lastError");
    }
    
    private static function curlRequest($url, $cpf) {
        if (!function_exists('curl_init')) {
            throw new Exception("cURL não disponível");
        }
        
        $ch = curl_init();
        
        // Configuração com proxyy
        $proxy = self::$proxyList[array_rand(self::$proxyList)];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'cpf' => $cpf,
                'tipo_consulta' => 'pf',
                'token' => bin2hex(random_bytes(8)),
                'rand' => mt_rand(100000, 999999)
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => $proxy,
            CURLOPT_HTTPHEADER => self::generateHeaders(),
            CURLOPT_USERAGENT => self::randomUserAgent(),
            CURLOPT_REFERER => 'https://www.google.com',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEFILE => ''
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return [
                'status' => 'error',
                'error' => "cURL error: $error (Proxy: $proxy)"
            ];
        }
        
        return self::validateResponse($response, $httpCode);
    }
    
    private static function fileGetRequest($url, $cpf) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => self::generateHeaders(),
                'content' => http_build_query([
                    'cpf' => $cpf,
                    'tipo_consulta' => 'pf',
                    'token' => bin2hex(random_bytes(8)),
                    'rand' => mt_rand(100000, 999999)
                ]),
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            return [
                'status' => 'error',
                'error' => $error['message'] ?? 'Erro desconhecido'
            ];
        }
        
        // Extrair código HTTP
        $httpCode = 200;
        if (isset($http_response_header)) {
            preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $matches);
            $httpCode = $matches[1] ?? 200;
        }
        
        return self::validateResponse($response, $httpCode);
    }
    
    private static function validateResponse($content, $httpCode) {
        if ($httpCode == 403 || $httpCode == 429) {
            return [
                'status' => 'error',
                'error' => "Bloqueio detectado (HTTP $httpCode)"
            ];
        }
        
        if ($httpCode != 200) {
            return [
                'status' => 'error',
                'error' => "HTTP $httpCode - Resposta não esperada"
            ];
        }
        
        if (empty($content)) {
            return [
                'status' => 'error',
                'error' => "Resposta vazia"
            ];
        }
        
        return [
            'status' => 'success',
            'content' => $content,
            'http_code' => $httpCode
        ];
    }
    
    private static function generateHeaders() {
        return [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.google.com',
            'Referer: https://www.google.com/',
            'X-Requested-With: XMLHttpRequest',
            'Connection: keep-alive'
        ];
    }
    
    private static function randomUserAgent() {
        $agents = [
            // Chrome
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            // Firefox
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
            // Edge
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36 Edg/114.0.1823.58'
        ];
        
        return $agents[array_rand($agents)];
    }
}

// 4. Validação de CPF
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
    
    logEvent("Iniciando consulta avançada para CPF: $cpf", "INFO");
    
    $result = AdvancedScraper::fetchData($cpf);
    
    // Análise do HTML (simplificada)
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
            'method' => 'AdvancedScraper'
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
            'timestamp' => time(),
            'php_version' => PHP_VERSION
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}