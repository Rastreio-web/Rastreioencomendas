<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Configuração Avançada de Log
$logFile = __DIR__ . '/scraper_advanced.log';
file_put_contents($logFile, "\n=== NOVA CONSULTA ".date('Y-m-d H:i:s')." ===\n", FILE_APPEND);

function logDebug($message, $level = 'INFO') {
    global $logFile;
    $logMessage = "[".date('H:i:s')."][$level] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// 2. Validação Reforçada do CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    // Cálculo dos dígitos verificadores
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

$cpf = $_GET['cpf'] ?? '';
if (!validarCPF($cpf)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CPF inválido ou mal formatado',
        'received' => $cpf
    ]);
    exit;
}

// 3. Classe de Requisição Avançada
class AdvancedRequest {
    private static $instanceCount = 0;
    
    public static function makeRequest($url, $data) {
        self::$instanceCount++;
        logDebug("Tentativa #".self::$instanceCount." para $url", "REQUEST");
        
        $methods = ['curl', 'file_get_contents'];
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36'
        ];
        
        foreach ($methods as $method) {
            try {
                $result = self::{"tryWith$method"}($url, $data, $userAgents);
                
                if ($result['http_code'] == 200 && !empty($result['html'])) {
                    logDebug("Sucesso com método $method", "SUCCESS");
                    return $result;
                }
                
                logDebug("Método $method falhou. HTTP: ".$result['http_code'], "WARNING");
            } catch (Exception $e) {
                logDebug("Erro no método $method: ".$e->getMessage(), "ERROR");
            }
            
            sleep(1); // Delay entre tentativas
        }
        
        throw new Exception("Todos os métodos falharam");
    }
    
    private static function tryWithcurl($url, $data, $userAgents) {
        if (!function_exists('curl_init')) {
            throw new Exception("cURL não disponível");
        }
        
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml',
            'X-Requested-With: XMLHttpRequest',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
            'Origin: https://www.descobreaqui.com',
            'Referer: https://www.descobreaqui.com/consulta'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)],
            CURLOPT_COOKIEJAR => __DIR__ . '/cookies.txt',
            CURLOPT_COOKIEFILE => __DIR__ . '/cookies.txt',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        logDebug("cURL Info: ".json_encode([
            'url' => $info['url'],
            'http_code' => $httpCode,
            'total_time' => $info['total_time'],
            'size' => $info['size_download'],
            'content_type' => $info['content_type']
        ]), "DEBUG");
        
        return [
            'html' => $response,
            'http_code' => $httpCode,
            'method' => 'curl',
            'info' => $info
        ];
    }
    
    private static function tryWithfile_get_contents($url, $data, $userAgents) {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'User-Agent: ' . $userAgents[array_rand($userAgents)],
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: text/html,application/xhtml+xml',
                    'X-Requested-With: XMLHttpRequest',
                    'Connection: keep-alive'
                ]),
                'content' => http_build_query($data),
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        
        $httpCode = 500;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
            $httpCode = $matches[1] ?? 500;
        }
        
        return [
            'html' => $response !== false ? $response : '',
            'http_code' => $httpCode,
            'method' => 'file_get_contents'
        ];
    }
}

// 4. Processamento Principal
try {
    $url = "https://www.descobreaqui.com/search";
    $postData = [
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => bin2hex(random_bytes(8)),
        'source' => 'web-query-v3'
    ];
    
    logDebug("Iniciando consulta para CPF: $cpf", "PROCESS");
    
    // Primeira tentativa
    $result = AdvancedRequest::makeRequest($url, $postData);
    
    // Verificação crítica
    if ($result['http_code'] != 200) {
        throw new Exception("Servidor retornou HTTP ".$result['http_code']);
    }
    
    if (empty($result['html'])) {
        throw new Exception("Resposta vazia do servidor");
    }
    
    logDebug("Resposta recebida (".strlen($result['html'])." bytes)", "DEBUG");
    
    // 5. Análise do HTML com DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($result['html'])) {
        $errors = libxml_get_errors();
        $errorMessages = array_map(function($error) {
            return $error->message;
        }, $errors);
        libxml_clear_errors();
        throw new Exception("Erro ao parsear HTML: ".implode(" | ", $errorMessages));
    }
    
    $xpath = new DOMXPath($dom);
    
    // 6. Extração de Dados com Padrões Múltiplos
    $extractors = [
        'nome' => [
            "//div[contains(@class,'user-name')]",
            "//h2[contains(text(),'Nome')]/following-sibling::div",
            "//div[@id='user-data']/h2"
        ],
        'nascimento' => [
            "//span[contains(@class,'birth-date')]",
            "//div[contains(text(),'Nascimento')]/following-sibling::div",
            "//div[@id='birth-data']"
        ]
    ];
    
    $responseData = ['cpf' => $cpf];
    
    foreach ($extractors as $field => $expressions) {
        foreach ($expressions as $expr) {
            $node = $xpath->query($expr)->item(0);
            if ($node && trim($node->nodeValue) != '') {
                $responseData[$field] = trim($node->nodeValue);
                break;
            }
        }
        
        if (!isset($responseData[$field])) {
            $responseData[$field] = 'Não encontrado';
        }
    }
    
    // 7. Retorno de Sucesso
    echo json_encode([
        'success' => true,
        'data' => $responseData,
        'metadata' => [
            'response_size' => strlen($result['html']),
            'http_status' => $result['http_code'],
            'method' => $result['method'],
            'timestamp' => time()
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logDebug("ERRO: ".$e->getMessage(), "ERROR");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Falha na consulta: '.$e->getMessage(),
        'error_code' => $e->getCode(),
        'debug' => [
            'cpf_consultado' => $cpf,
            'timestamp' => time(),
            'system' => php_uname()
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}