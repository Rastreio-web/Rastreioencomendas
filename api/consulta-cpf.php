<?php
// 0. Buffer de saída para evitar problemas com headers
ob_start();

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 0); // Desativamos a exibição de erros na saída

// 1. Configurações avançadas
define('MAX_ATTEMPTS', 3);
define('REQUEST_DELAY', 5); // Valor inteiro agora
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
        'primary' => 'https://pesquisacpf.com.br/consulta',
        'secondary' => 'https://api.pesquisacpf.com.br/v1/query',
        'fallback' => 'https://www.receitaws.com.br/v1/cpf/'
    ];
    
    private static $proxyList = [
        '45.61.123.219:3128',
        '103.156.144.5:8080',
        '200.105.215.22:33630'
    ];
    
    public static function fetchData($cpf) {
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
            
            sleep((int) REQUEST_DELAY); // Conversão explícita para int
        }
        
        throw new Exception("Todos os endpoints falharam");
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
            
            sleep((int) (REQUEST_DELAY / 2)); // Conversão explícita
        }
        
        throw new Exception("Todos os métodos falharam. Último erro: $lastError");
    }

    // ... [restante dos métodos permanecem inalterados]
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
        ob_end_clean(); // Limpa qualquer saída antes dos headers
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
    
    // Análise do HTML
    $dom = new DOMDocument();
    @$dom->loadHTML($result['content']);
    $xpath = new DOMXPath($dom);
    
    $nome = $xpath->query("//div[contains(@class,'nome')]")->item(0)->nodeValue ?? 'Não encontrado';
    $nascimento = $xpath->query("//span[contains(@class,'data-nascimento')]")->item(0)->nodeValue ?? 'Não encontrado';
    
    ob_end_clean(); // Limpa o buffer antes da saída JSON
    echo json_encode([
        'success' => true,
        'data' => [
            'cpf' => $cpf,
            'nome' => trim($nome),
            'nascimento' => trim($nascimento)
        ],
        'metadata' => [
            'http_code' => $result['http_code'],
            'method' => 'AdvancedScraper',
            'source' => 'pesquisacpf.com.br'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logEvent("Falha crítica: " . $e->getMessage(), "CRITICAL");
    
    ob_end_clean(); // Limpa o buffer antes da saída JSON
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