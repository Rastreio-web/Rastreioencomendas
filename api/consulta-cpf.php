<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

// Configurações
$timeout = 30;
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

// Função para fazer scraping real
function consultarCPF($cpf) {
    $url = "https://pesquisacpf.com.br/consulta/?cpf=" . urlencode($cpf);
    
    // Inicializa cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $html = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return ['error' => 'Erro na conexão: ' . curl_error($ch)];
    }
    
    curl_close($ch);
    
    // Analisa o HTML retornado
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Extrai informações (ajuste os seletores conforme o layout atual do site)
    $result = [
        'nome' => '',
        'data_nascimento' => '',
        'situacao' => ''
    ];
    
    // Exemplo de como extrair dados (os seletores precisam ser verificados)
    $nomeNodes = $xpath->query("//div[contains(@class, 'nome')]");
    if ($nomeNodes->length > 0) {
        $result['nome'] = trim($nomeNodes->item(0)->textContent);
    }
    
    $nascimentoNodes = $xpath->query("//div[contains(@class, 'nascimento')]");
    if ($nascimentoNodes->length > 0) {
        $result['data_nascimento'] = trim($nascimentoNodes->item(0)->textContent);
    }
    
    $situacaoNodes = $xpath->query("//div[contains(@class, 'situacao')]");
    if ($situacaoNodes->length > 0) {
        $result['situacao'] = trim($situacaoNodes->item(0)->textContent);
    }
    
    return $result;
}

// Processa a requisição
$cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
$cep = preg_replace('/[^0-9]/', '', $_POST['cep'] ?? '');

if(empty($cpf) || strlen($cpf) != 11){
    http_response_code(400);
    die(json_encode(['error' => 'CPF inválido']));
}

if(empty($cep) || strlen($cep) < 8){
    http_response_code(400);
    die(json_encode(['error' => 'CEP inválido']));
}

// Consulta os dados do CPF
$dadosCpf = consultarCPF($cpf);

if(isset($dadosCpf['error'])) {
    http_response_code(500);
    die(json_encode(['error' => $dadosCpf['error']]));
}

// Simulação de banco de dados de rastreio
$database = [
    '11827304774' => [
        '28390000' => [
            [
                'codigo' => 'ABC123456789BR',
                'status' => 'Em trânsito',
                'ultima_atualizacao' => date('Y-m-d H:i:s'),
                'historico' => [
                    ['data' => date('Y-m-d', strtotime('-5 days')), 'status' => 'Postado'],
                    ['data' => date('Y-m-d', strtotime('-3 days')), 'status' => 'Em processamento'],
                    ['data' => date('Y-m-d', strtotime('-1 day')), 'status' => 'Saiu para entrega']
                ],
                'previsao_entrega' => date('Y-m-d', strtotime('+3 days'))
            ]
        ]
    ]
];

$response = [
    'success' => true,
    'cpf' => $cpf,
    'cep' => $cep,
    'dados_cadastrais' => $dadosCpf,
    'consulta_realizada_em' => date('d/m/Y H:i:s')
];

if(isset($database[$cpf][$cep])) {
    $response['encomendas'] = $database[$cpf][$cep];
} else {
    $response['encomendas'] = [];
    $response['info'] = 'Nenhuma encomenda encontrada para este CPF e CEP';
}

echo json_encode($response);
?>