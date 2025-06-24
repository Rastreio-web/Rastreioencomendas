<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// Restante do seu código...
// Verifica se o formulário foi submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cpf'])) {
    // Remove caracteres não numéricos do CPF
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
    
    // Validação básica do CPF
    if (strlen($cpf) !== 11) {
        die('CPF inválido. Deve conter 11 dígitos.');
    }
    
    // URL do site de consulta
    $url = 'https://pesquisacpf.com.br/';
    
    // Inicializa o cURL
    $ch = curl_init();
    
    // Configura as opções do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // Configuração para simular um navegador (alguns sites bloqueiam solicitações sem User-Agent)
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    
    // Executa a requisição para obter cookies e tokens necessários
    $response = curl_exec($ch);
    
    // Verifica se a requisição foi bem-sucedida
    if ($response === false) {
        die('Erro ao acessar o site de consulta: ' . curl_error($ch));
    }
    
    // Prepara os dados para o POST (ajuste conforme necessário)
    $postData = [
        'cpf' => $cpf,
        // Adicione outros campos necessários que você identificar no formulário do site
    ];
    
    // Configura a requisição POST
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    
    // Executa a consulta
    $result = curl_exec($ch);
    
    // Fecha a conexão cURL
    curl_close($ch);
    
    // Verifica se obteve resposta
    if ($result === false) {
        die('Erro ao consultar o CPF');
    }
    
    // Processa o resultado (usando expressões regulares para extrair os dados)
    $nome = '';
    $dataNascimento = '';
    
    // Padrão para extrair o nome (ajuste conforme o HTML retornado)
    if (preg_match('/<span class="nome">(.*?)<\/span>/', $result, $matches)) {
        $nome = htmlspecialchars(trim($matches[1]));
    }
    
    // Padrão para extrair a data de nascimento (ajuste conforme o HTML retornado)
    if (preg_match('/<span class="nascimento">(.*?)<\/span>/', $result, $matches)) {
        $dataNascimento = htmlspecialchars(trim($matches[1]));
    }
    
    // Exibe os resultados
    echo '<h2>Resultado da Consulta</h2>';
    echo '<p><strong>CPF:</strong> ' . htmlspecialchars($cpf) . '</p>';
    
    if (!empty($nome)) {
        echo '<p><strong>Nome:</strong> ' . $nome . '</p>';
    } else {
        echo '<p>Nome não encontrado</p>';
    }
    
    if (!empty($dataNascimento)) {
        echo '<p><strong>Data de Nascimento:</strong> ' . $dataNascimento . '</p>';
    } else {
        echo '<p>Data de nascimento não encontrada</p>';
    }
    
    exit;
}