<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuração de logs
$logFile = __DIR__ . '/scraper_debug.log';
file_put_contents($logFile, "\n=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

function logDebug($message) {
    global $logFile;
    file_put_contents($logFile, "[DEBUG] $message\n", FILE_APPEND);
}

// 1. Validação do CPF
$cpfInput = $_GET['cpf'] ?? '';
$cpf = preg_replace('/[^0-9]/', '', $cpfInput);

logDebug("CPF recebido (original): $cpfInput");
logDebug("CPF limpo: $cpf");

if (strlen($cpf) !== 11 || !ctype_digit($cpf)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CPF inválido. Deve conter exatamente 11 dígitos numéricos.',
        'received' => $cpfInput,
        'cleaned' => $cpf
    ]);
    exit;
}

// 2. Função de requisição melhorada
function fetchData($url, $data) {
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
                'Content-type: application/x-www-form-urlencoded',
                'Accept: */*',
                'Connection: keep-alive',
                'X-Requested-With: XMLHttpRequest'
            ]),
            'content' => http_build_query($data),
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    // Tentativa com cURL (mais robusta)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
                'Content-type: application/x-www-form-urlencoded',
                'Accept: */*',
                'X-Requested-With: XMLHttpRequest'
            ],
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_COOKIEFILE => '',
            CURLOPT_VERBOSE => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        logDebug("cURL Info: " . print_r(curl_getinfo($ch), true));
        if ($error) logDebug("cURL Error: $error");
        
        curl_close($ch);

        return [
            'html' => $response,
            'http_code' => $httpCode,
            'method' => 'curl'
        ];
    }

    // Fallback para file_get_contents
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

// 3. Tentativa de consulta com tratamento melhorado
try {
    $url = "https://www.descobreaqui.com/search";
    $postData = [
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => bin2hex(random_bytes(8))
    ];

    logDebug("Iniciando requisição para: $url");
    logDebug("Dados enviados: " . print_r($postData, true));
    
    $result = fetchData($url, $postData);

    logDebug("Resultado bruto: " . print_r($result, true));
    
    if ($result['http_code'] == 500) {
        throw new Exception("O servidor remoto retornou um erro interno (500)");
    }
    
    if (empty($result['html'])) {
        throw new Exception("Resposta vazia. HTTP Code: " . $result['http_code']);
    }

    logDebug("Resposta recebida (tamanho): " . strlen($result['html']));
    logDebug("Método utilizado: " . $result['method']);

    // 4. Análise do HTML com tratamento de erros
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($result['html'])) {
        $errors = libxml_get_errors();
        $errorMessages = array_map(function($error) {
            return trim($error->message);
        }, $errors);
        libxml_clear_errors();
        throw new Exception("Falha ao parsear HTML: " . implode("; ", $errorMessages));
    }

    $xpath = new DOMXPath($dom);
    
    // 5. Extração de dados com seletores mais tolerantes
    $nomeNode = $xpath->query("//div[contains(@class,'user-info')]//h2")->item(0);
    $nome = $nomeNode ? trim($nomeNode->nodeValue) : null;
    
    $nascimentoNode = $xpath->query("//span[contains(@class,'birth-date')]")->item(0);
    $nascimento = $nascimentoNode ? trim($nascimentoNode->nodeValue) : null;

    // 6. Retorno dos resultados
    $response = [
        'success' => true,
        'data' => [
            'cpf' => $cpf,
            'nome' => $nome ?: 'Não encontrado',
            'nascimento' => $nascimento ?: 'Não encontrado'
        ],
        'debug' => [
            'html_size' => strlen($result['html']),
            'http_code' => $result['http_code'],
            'method' => $result['method']
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    logDebug("ERRO: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na consulta: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug_info' => isset($result) ? [
            'http_code' => $result['http_code'] ?? null,
            'method' => $result['method'] ?? null
        ] : null
    ], JSON_UNESCAPED_UNICODE);
}