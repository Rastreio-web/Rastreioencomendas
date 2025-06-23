<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuração avançada de logs
$logFile = __DIR__ . '/scraper_debug.log';
file_put_contents($logFile, "\n\n=== NOVA CONSULTA EM " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

function logDebug($message) {
    global $logFile;
    file_put_contents($logFile, "[DEBUG] " . $message . "\n", FILE_APPEND);
}

// 1. Validação do CPF
$cpf = isset($_GET['cpf']) ? preg_replace('/[^0-9]/', '', $_GET['cpf']) : '';
logDebug("CPF recebido: $cpf");

if (empty($cpf) || strlen($cpf) !== 11) {
    logDebug("CPF inválido");
    echo json_encode(['success' => false, 'message' => 'CPF inválido']);
    exit;
}

// 2. Configuração da requisição
$options = [
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.descobreaqui.com',
            'Referer: https://www.descobreaqui.com/search',
            'Connection: keep-alive',
            'X-Requested-With: XMLHttpRequest'
        ]),
        'content' => http_build_query([
            'cpf' => $cpf,
            'tipo_consulta' => 'pf',
            'token' => bin2hex(random_bytes(8)) // Token aleatório
        ]),
        'timeout' => 30,
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
];

// 3. Tentativa de requisição
try {
    $url = "https://www.descobreaqui.com/search";
    logDebug("Iniciando requisição para: $url");
    
    $context = stream_context_create($options);
    
    // Primeira tentativa com file_get_contents
    $html = @file_get_contents($url, false, $context);
    
    // Fallback para cURL se falhar
    if ($html === false && function_exists('curl_init')) {
        logDebug("Fallback para cURL");
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $options['http']['content'],
            CURLOPT_HTTPHEADER => explode("\r\n", $options['http']['header']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Falha no cURL. Código HTTP: $httpCode");
        }
    }
    
    if ($html === false) {
        $error = error_get_last();
        logDebug("Erro na requisição: " . print_r($error, true));
        throw new Exception("Não foi possível acessar o site de consulta");
    }
    
    logDebug("Resposta recebida (primeiros 200 chars): " . substr($html, 0, 200));
    
    // 4. Análise do HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($html)) {
        $libxmlErrors = libxml_get_errors();
        logDebug("Erros DOMDocument: " . print_r($libxmlErrors, true));
        libxml_clear_errors();
        throw new Exception("Falha ao analisar o HTML");
    }
    
    $xpath = new DOMXPath($dom);
    
    // 5. Extração de dados com múltiplos seletores de fallback
    $selectors = [
        'nome' => [
            "//p[contains(@class, 'font-mono') and contains(@class, 'text-gray-500')]",
            "//div[contains(@class, 'user-name')]",
            "//span[@id='nome-completo']"
        ],
        'nascimento' => [
            "//p[contains(@class, 'birth-date')]",
            "//span[@id='data-nascimento']"
        ]
    ];
    
    $resultado = [];
    
    foreach ($selectors as $campo => $xpaths) {
        foreach ($xpaths as $xpathExpr) {
            $nodes = $xpath->query($xpathExpr);
            if ($nodes && $nodes->length > 0) {
                $value = trim($nodes->item(0)->nodeValue);
                $resultado[$campo] = preg_replace('/[\/\*]+/', '', $value);
                break;
            }
        }
    }
    
    if (empty($resultado['nome'])) {
        throw new Exception("Dados não encontrados no HTML");
    }
    
    // 6. Retorno dos resultados
    $response = [
        'success' => true,
        'nome' => $resultado['nome'] ?? 'Não encontrado',
        'nascimento' => $resultado['nascimento'] ?? 'Não encontrado',
        'debug' => [
            'html_length' => strlen($html),
            'selector_used' => $selectors
        ]
    ];
    
    logDebug("Resultado final: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    $errorResponse = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'request_info' => [
            'url' => $url ?? null,
            'method' => $options['http']['method'] ?? null
        ]
    ];
    
    logDebug("ERRO: " . json_encode($errorResponse));
    echo json_encode($errorResponse);
}