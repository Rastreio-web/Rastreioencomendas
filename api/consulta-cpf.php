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

// 2. Função de requisição com múltiplas estratégias
function fetchData($url, $data) {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36'
    ];

    // Tentativa com cURL (versão moderna)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        
        $headers = [
            'Content-type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml',
            'X-Requested-With: XMLHttpRequest',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8'
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_COOKIEFILE => '',
            CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)],
            CURLOPT_REFERER => 'https://www.descobreaqui.com',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        logDebug("cURL Debug:\n" . 
                "URL: $url\n" .
                "HTTP Code: $httpCode\n" .
                "Error: " . ($error ?: 'Nenhum') . "\n" .
                "Response Size: " . strlen($response) . " bytes\n" .
                "User-Agent: " . curl_getinfo($ch, CURLINFO_USER_AGENT));
        
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            return [
                'html' => $response,
                'http_code' => $httpCode,
                'method' => 'curl'
            ];
        }
    }

    // Tentativa com file_get_contents (fallback)
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'User-Agent: ' . $userAgents[array_rand($userAgents)],
                'Content-type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml',
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
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    $httpCode = 500;
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
        $httpCode = $matches[1] ?? 500;
    }
    
    logDebug("file_get_contents Debug:\n" .
            "URL: $url\n" .
            "HTTP Code: $httpCode\n" .
            "Response Size: " . strlen($response) . " bytes");
    
    return [
        'html' => $response !== false ? $response : '',
        'http_code' => $httpCode,
        'method' => 'file_get_contents'
    ];
}

// 3. Consulta principal com fallbacks
try {
    $url = "https://www.descobreaqui.com/search";
    $postData = [
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => bin2hex(random_bytes(8))
    ];

    logDebug("Iniciando consulta para CPF: $cpf");
    
    // Primeira tentativa
    $result = fetchData($url, $postData);
    
    // Segunda tentativa se falhar (com pequena variação)
    if ($result['http_code'] != 200 || empty($result['html'])) {
        sleep(1); // Delay para evitar bloqueio
        $postData['token'] = bin2hex(random_bytes(8)); // Novo token
        $result = fetchData($url, $postData);
    }

    // Verificação final
    if ($result['http_code'] != 200) {
        throw new Exception("Servidor retornou HTTP " . $result['http_code']);
    }
    
    if (empty($result['html'])) {
        throw new Exception("Resposta vazia do servidor");
    }

    logDebug("Dados recebidos com sucesso (" . strlen($result['html']) . " bytes)");

    // 4. Análise do HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($result['html'])) {
        $errors = libxml_get_errors();
        $errorMessages = array_map(function($error) {
            return trim($error->message);
        }, $errors);
        libxml_clear_errors();
        throw new Exception("Erro ao analisar HTML: " . implode("; ", $errorMessages));
    }

    $xpath = new DOMXPath($dom);
    
    // 5. Extração de dados com seletores flexíveis
    $data = [
        'nome' => null,
        'nascimento' => null,
        'mae' => null
    ];

    // Tentativa com vários padrões de seletores
    $selectors = [
        'nome' => [
            "//div[contains(@class,'user-info')]//h2",
            "//div[@id='user-data']//h2",
            "//h2[contains(text(),'Nome')]/following-sibling::div"
        ],
        'nascimento' => [
            "//span[contains(@class,'birth-date')]",
            "//div[contains(text(),'Nascimento')]/following-sibling::div",
            "//span[@id='birth-date']"
        ]
    ];

    foreach ($selectors as $field => $xpaths) {
        foreach ($xpaths as $xpathExpr) {
            $node = $xpath->query($xpathExpr)->item(0);
            if ($node && !empty(trim($node->nodeValue))) {
                $data[$field] = trim($node->nodeValue);
                break;
            }
        }
    }

    // 6. Retorno dos resultados
    $response = [
        'success' => true,
        'data' => [
            'cpf' => $cpf,
            'nome' => $data['nome'] ?? 'Não encontrado',
            'nascimento' => $data['nascimento'] ?? 'Não encontrado'
        ],
        'metadata' => [
            'response_size' => strlen($result['html']),
            'http_status' => $result['http_code'],
            'method' => $result['method']
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    logDebug("ERRO CRÍTICO: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na consulta: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug' => [
            'last_http_code' => $result['http_code'] ?? null,
            'method' => $result['method'] ?? null,
            'cpf_consultado' => $cpf
        ]
    ], JSON_UNESCAPED_UNICODE);
}