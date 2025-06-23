<?php
header('Content-Type: application/json');

// 1. Validar o CPF recebido
$cpf = isset($_GET['cpf']) ? preg_replace('/[^0-9]/', '', $_GET['cpf']) : '';

if (empty($cpf) || strlen($cpf) !== 11) {
    echo json_encode(['success' => false, 'message' => 'CPF inválido']);
    exit;
}

// 2. Configurar o cabeçalho HTTP
$options = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
    ]
];

try {
    // 3. URL do site de consulta
    $url = "https://www.descobreaqui.com/search";
    
    // 4. Preparar os dados do POST (ajuste conforme o formulário real)
    $postData = http_build_query([
        'cpf' => $cpf,
        'tipo_consulta' => 'pf', // Exemplo - ajuste conforme necessário
        'token' => 'abc123'      // Exemplo - verifique se o site usa token
    ]);
    
    // 5. Configurar o contexto para POST
    $options['http']['method'] = 'POST';
    $options['http']['content'] = $postData;
    $options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $options['http']['header'] .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n";
    $options['http']['header'] .= "Referer: https://www.descobreaqui.com/search\r\n";
    
    $context = stream_context_create($options);
    
    // 6. Fazer a requisição com timeout de 30 segundos
    $html = file_get_contents($url, false, $context);
    
    if ($html === false) {
        throw new Exception("Não foi possível acessar o site de consulta. Tente novamente mais tarde.");
    }
    
    // 7. Analisar o HTML retornado
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // 8. Extrair os dados usando os seletores corretos
    // Encontrar todos os elementos com as classes especificadas
    $infoNodes = $xpath->query("//p[contains(@class, 'font-mono') and contains(@class, 'text-sm') and contains(@class, 'text-gray-500')]");
    
    // Inicializar variáveis
    $nome = null;
    $nascimento = null;
    
    // Processar os nós encontrados
    if ($infoNodes->length >= 2) {
        // Primeiro elemento geralmente é o nome 
        $nome = trim($infoNodes->item(0)->nodeValue);
        $nome = preg_replace('/\*+/', '', $nome); // Remove os asteriscos
        $nome = trim(preg_replace('/\s+/', ' ', $nome)); // Normaliza espaços
        
        // Segundo elemento geralmente é a data (ex: "27/**/****")
        $nascimento = trim($infoNodes->item(1)->nodeValue);
        $nascimento = str_replace(['/**/', '*'], '', $nascimento); // Remove máscaras
    }
    
    // 9. Validar os dados obtidos
    if (empty($nome) {
        throw new Exception("Nome não encontrado na página. A estrutura do site pode ter mudado.");
    }
    
    // 10. Retornar os resultados
    echo json_encode([
        'success' => true,
        'nome' => $nome,
        'nascimento' => !empty($nascimento) ? $nascimento : 'Não encontrado',
        'debug' => false // Mude para true durante desenvolvimento se precisar debugar
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro na consulta: ' . $e->getMessage(),
        'debug_info' => [
            'cpf' => $cpf,
            'html_length' => isset($html) ? strlen($html) : 0
        ]
    ]);
}