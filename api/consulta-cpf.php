<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Configurações melhoradas
define('MAX_ATTEMPTS', 3);
define('REQUEST_DELAY', 5); // Aumentado para 5 segundos
$logFile = __DIR__ . '/scraper_ultimate.log';

// 2. Sistema de Logs Aprimorado
function logEvent($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMsg = "[$timestamp][$level] $message\n";
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}

// 3. Verificador de URL
function verifyEndpoint($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $headers = @get_headers($url, 1, $context);
    
    if ($headers === false) {
        return [
            'status' => 'error',
            'error' => 'URL não acessível ou bloqueada'
        ];
    }
    
    $statusCode = (int)substr($headers[0], 9, 3);
    
    return [
        'status' => $statusCode === 200 ? 'success' : 'error',
        'http_code' => $statusCode,
        'headers' => $headers
    ];
}

// 4. Classe de Requisição com Fallbacks
class UltimateScraper {
    public static function fetchData($url, $data) {
        // Primeiro verifica se o endpoint está acessível
        $verification = verifyEndpoint($url);
        
        if ($verification['status'] !== 'success') {
            throw new Exception("Endpoint inacessível. Código HTTP: " . ($verification['http_code'] ?? 'N/A'));
        }
        
        logEvent("Endpoint verificado. Iniciando requisições...", "INFO");
        
        // Tenta múltiplos métodos
        $methods = ['fileGetContentsEnhanced', 'curlIfAvailable'];
        $lastError = null;
        
        foreach ($methods as $method) {
            try {
                $result = self::$method($url, $data);
                
                if ($result['status'] === 'success') {
                    return $result;
                }
                
                $lastError = $result['error'];
                logEvent("Método $method falhou: $lastError", "WARNING");
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                logEvent("Erro no método $method: $lastError", "ERROR");
            }
            
            sleep(REQUEST_DELAY);
        }
        
        throw new Exception("Todos os métodos falharam. Último erro: $lastError");
    }
    
    private static function fileGetContentsEnhanced($url, $data) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => self::generateHeaders(),
                'content' => http_build_query($data),
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            return [
                'status' => 'error',
                'error' => $error['message'] ?? 'Erro desconhecido no file_get_contents'
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
    
    private static function curlIfAvailable($url, $data) {
        if (!function_exists('curl_init')) {
            throw new Exception("cURL não disponível");
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => self::generateHeaders(),
            CURLOPT_USERAGENT => self::randomUserAgent(),
            CURLOPT_REFERER => 'https://www.descobreaqui.com',
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return [
                'status' => 'error',
                'error' => "cURL error: $error"
            ];
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
        return implode("\r\n", [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::randomUserAgent(),
            'Referer: https://www.descobreaqui.com',
            'Origin: https://www.descobreaqui.com',
            'Connection: keep-alive'
        ]) . "\r\n";
    }
    
    private static function randomUserAgent() {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/113.0',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1'
        ];
        
        return $agents[array_rand($agents)];
    }
}

// 5. Validação de CPF (corrigida)
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

// 6. Processamento Principal com Tratamento Aprimorado
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
    
    $result = UltimateScraper::fetchData($url, $postData);
    
    // Análise do HTML
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
            'method' => 'UltimateScraper'
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
            'php_version' => PHP_VERSION,
            'attempts' => MAX_ATTEMPTS
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}