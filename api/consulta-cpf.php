<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Função para debug
function debug($data) {
    file_put_contents('debug.log', print_r($data, true), FILE_APPEND);
}
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");  // Importante para requisições frontend

// 1. Validar o CPF recebido
$cpf = isset($_GET['cpf']) ? preg_replace('/[^0-9]/', '', $_GET['cpf']) : '';

if (empty($cpf) || strlen($cpf) !== 11) {
    echo json_encode(['success' => false, 'message' => 'CPF inválido']);
    exit;
}

// 2. Configurar o cabeçalho HTTP
$options = [
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.descobreaqui.com',
            'Referer: https://www.descobreaqui.com/search',
            'Connection: keep-alive'
        ]),
        'content' => http_build_query(['cpf' => $cpf]),
        'timeout' => 30,
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
];

try {
    // 3. URL do site de consulta
    $url = "https://www.descobreaqui.com/search";
    
    // 4. Preparar os dados do POST
    $postData = http_build_query([
        'cpf' => $cpf,
        'tipo_consulta' => 'pf',
        'token' => 'abc123'
    ]);
    
    // 5. Configurar o contexto para POST
    $options['http']['method'] = 'POST';
    $options['http']['content'] = $postData;
    $options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $options['http']['header'] .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n";
    $options['http']['header'] .= "Referer: https://www.descobreaqui.com/search\r\n";
    
    $context = stream_context_create($options);
    
    // 6. Fazer a requisição
    $html = @file_get_contents($url, false, $context);
    
    if ($html === false) {
        throw new Exception("Não foi possível acessar o site de consulta. Tente novamente mais tarde.");
    }
    
    // 7. Analisar o HTML retornado
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suprime erros de HTML malformado
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // 8. Extrair os dados - SELETORES ATUALIZADOS (verifique no site atual)
    $infoNodes = $xpath->query("//p[contains(@class, 'font-mono') and contains(@class, 'text-sm') and contains(@class, 'text-gray-500')]");
    
    // Validação da estrutura
    if (!$infoNodes || $infoNodes->length < 2) {
        throw new Exception("Estrutura do site alterada. Não foi possível encontrar os dados.");
    }
    
    // Processamento dos dados
    $nome = trim(preg_replace('/\*+/', '', $infoNodes->item(0)->nodeValue));
    $nascimento = trim(str_replace(['/**/', '*'], '', $infoNodes->item(1)->nodeValue));
    
    // 9. Validação dos dados
    if (empty($nome)) {
        throw new Exception("Nome não encontrado na página.");
    }
    
    // 10. Retornar os resultados
    echo json_encode([
        'success' => true,
        'nome' => $nome,
        'nascimento' => !empty($nascimento) ? $nascimento : 'Não encontrado'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}