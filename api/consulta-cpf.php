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

if (strlen($cpf) !== 11 || !is_numeric($cpf)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CPF inválido. Deve conter exatamente 11 dígitos numéricos.',
        'received' => $cpfInput,
        'cleaned' => $cpf
    ]);
    exit;
}

// 2. Função de requisição com fallback
function fetchData($url, $data) {
    // Primeiro tenta com cURL
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
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest'
            ],
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_COOKIEFILE => '',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$error && $httpCode === 200) {
            return [
                'html' => $response,
                'http_code' => $httpCode,
                'method' => 'curl'
            ];
        }
    }

    // Fallback para file_get_contents se cURL falhar
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
                'Content-type: application/x-www-form-urlencoded',
            ]),
            'content' => http_build_query($data),
            'timeout' => 30
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    return [
        'html' => $response,
        'http_code' => $response !== false ? 200 : 500,
        'method' => 'file_get_contents'
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

    logDebug("Iniciando requisição para: $url");
    $result = fetchData($url, $postData);

    if (empty($result['html']) {
        throw new Exception("Resposta vazia. HTTP Code: " . $result['http_code']);
    }

    logDebug("Resposta recebida (tamanho): " . strlen($result['html']));
    logDebug("Método utilizado: " . $result['method']);

    // 4. Análise do HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    if (!$dom->loadHTML($result['html'])) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        throw new Exception("Falha ao parsear HTML: " . json_encode($errors));
    }

    $xpath = new DOMXPath($dom);
    
    // 5. Extração de dados com seletores mais específicos
    $nome = $xpath->query("//div[@class='user-info']/h2")->item(0)->nodeValue ?? null;
    $nascimento = $xpath->query("//span[@class='birth-date']")->item(0)->nodeValue ?? null;

    // Limpeza dos dados
    $nome = $nome ? trim(preg_replace('/\s+/', ' ', $nome)) : null;
    $nascimento = $nascimento ? trim($nascimento) : null;

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

    echo json_encode($response);

} catch (Exception $e) {
    logDebug("ERRO: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro na consulta: ' . $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}