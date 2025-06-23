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
$cpf = preg_replace('/[^0-9]/', $_GET['cpf'] ?? '');
logDebug("CPF recebido: $cpf");

if (strlen($cpf) !== 11) {
    echo json_encode(['success' => false, 'message' => 'CPF inválido']);
    exit;
}

// 2. Configuração da requisição CURL (mais confiável que file_get_contents)
function fetchWithCurl($url, $data) {
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
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest'
        ],
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_COOKIEFILE => '', // Ativa cookie handling
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'html' => $response,
        'http_code' => $httpCode,
        'error' => $error
    ];
}

// 3. Tentativa de consulta
try {
    $url = "https://www.descobreaqui.com/search";
    $postData = [
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => bin2hex(random_bytes(8))
    ];

    logDebug("Iniciando requisição CURL para: $url");
    $result = fetchWithCurl($url, $postData);

    if ($result['error']) {
        throw new Exception("Erro CURL: " . $result['error']);
    }

    if ($result['http_code'] !== 200) {
        throw new Exception("HTTP Code: " . $result['http_code']);
    }

    logDebug("Resposta recebida (tamanho): " . strlen($result['html']));

    // 4. Análise do HTML (com fallback)
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($result['html'])) {
        throw new Exception("Falha ao parsear HTML");
    }

    $xpath = new DOMXPath($dom);
    
    // 5. Extração de dados com seletores alternativos
    $nome = extractData($xpath, [
        "//p[contains(@class, 'font-mono')]",
        "//div[contains(@class, 'user-name')]",
        "//span[@id='nome-completo']"
    ]);

    $nascimento = extractData($xpath, [
        "//p[contains(@class, 'birth-date')]",
        "//span[@id='data-nascimento']"
    ]);

    // 6. Retorno dos resultados
    echo json_encode([
        'success' => true,
        'nome' => $nome ?: 'Não encontrado',
        'nascimento' => $nascimento ?: 'Não encontrado',
        'debug' => [
            'html_size' => strlen($result['html']),
            'http_code' => $result['http_code']
        ]
    ]);

} catch (Exception $e) {
    logDebug("ERRO: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}

// Função auxiliar para extração de dados
function extractData($xpath, $selectors) {
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes && $nodes->length > 0) {
            return trim(preg_replace('/[\/\*]+/', '', $nodes->item(0)->nodeValue));
        }
    }
    return null;
}