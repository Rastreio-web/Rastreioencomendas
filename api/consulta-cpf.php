<?php
// index.php - Web Scraping para pesquisa de CPF

// Função para limpar e validar o CPF
function limparCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    return $cpf;
}

// Função para realizar a pesquisa
function pesquisarCPF($cpf) {
    $url = 'https://pesquisacpf.com.br/';
    
    try {
        // Inicializa o cURL
        $ch = curl_init();
        
        // Configura as opções do cURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        // Executa a requisição para obter o token CSRF
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Erro ao acessar o site: ' . curl_error($ch));
        }
        
        // Extrai o token CSRF
        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        $token = $xpath->query('//input[@name="csrf_token"]/@value')->item(0)->nodeValue;
        
        if (!$token) {
            throw new Exception('Não foi possível obter o token CSRF');
        }
        
        // Prepara os dados para o POST
        $postData = [
            'cpf' => $cpf,
            'csrf_token' => $token,
            'acao' => 'pesquisar'
        ];
        
        // Configura o cURL para enviar os dados via POST
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        
        // Executa a pesquisa
        $resultado = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Erro ao pesquisar CPF: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        // Analisa o resultado
        @$dom->loadHTML($resultado);
        $xpath = new DOMXPath($dom);
        
        // Extrai o nome completo
        $nomeNode = $xpath->query('//div[contains(@class, "resultado")]//p[contains(text(), "Nome:")]');
        $nome = $nomeNode->length > 0 ? trim(str_replace('Nome:', '', $nomeNode->item(0)->nodeValue)) : 'Não encontrado';
        
        // Extrai a data de nascimento
        $nascimentoNode = $xpath->query('//div[contains(@class, "resultado")]//p[contains(text(), "Nascimento:")]');
        $nascimento = $nascimentoNode->length > 0 ? trim(str_replace('Nascimento:', '', $nascimentoNode->item(0)->nodeValue)) : 'Não encontrado';
        
        return [
            'nome' => $nome,
            'nascimento' => $nascimento,
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        return [
            'nome' => '',
            'nascimento' => '',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// Processa o formulário se enviado
$resultado = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cpf'])) {
    $cpf = limparCPF($_POST['cpf']);
    
    if (strlen($cpf) !== 11) {
        $resultado = [
            'status' => 'error',
            'message' => 'CPF inválido. Deve conter 11 dígitos.'
        ];
    } else {
        $resultado = pesquisarCPF($cpf);
    }
}
?>