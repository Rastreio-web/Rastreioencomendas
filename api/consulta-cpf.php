<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuração de Ambiente
define('MAX_ATTEMPTS', 3);
define('REQUEST_DELAY', 2);
$logFile = __DIR__ . '/scraper_pro.log';

// Sistema de Logs
function logEvent($message, $level = 'INFO') {
    global $logFile;
    $logMsg = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}

class ProfessionalScraper {
    public static function fetchWithRetry($url, $data) {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < MAX_ATTEMPTS) {
            $attempt++;
            try {
                $result = self::fileGetContentsRequest($url, $data, $attempt);
                
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
                sleep(REQUEST_DELAY * $attempt);
            }
        }
        
        throw new Exception("Todas as tentativas falharam. Último erro: " . $lastError);
    }
    
    private static function fileGetContentsRequest($url, $data, $attempt) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => self::generateHeaders($attempt),
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
                'error' => $error['message'] ?? 'Erro desconhecido'
            ];
        }
        
        // Extrair código HTTP dos headers
        preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0] ?? '', $matches);
        $httpCode = $matches[1] ?? 200;
        
        if ($httpCode == 403 || $httpCode == 429) {
            return [
                'status' => 'error',
                'error' => "Bloqueio detectado (HTTP $httpCode)"
            ];
        }
        
        if ($httpCode != 200 || empty($response)) {
            return [
                'status' => 'error',
                'error' => "HTTP $httpCode - Resposta vazia"
            ];
        }
        
        return [
            'status' => 'success',
            'content' => $response,
            'http_code' => $httpCode
        ];
    }
    
    private static function generateHeaders($attempt) {
        $headers = [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::randomUserAgent(),
            'Referer: https://www.descobreaqui.com',
            'Origin: https://www.descobreaqui.com'
        ];
        
        return implode("\r\n", $headers) . "\r\n";
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

// Validação de CPF (mesma implementação)
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

// Processamento Principal (mesma implementação)
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