<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(0);

// Configurações
$logFile = __DIR__ . '/cpf_api.log';
$cpf = preg_replace('/[^0-9]/', '', $_GET['cpf'] ?? '');

function logConsulta($message) {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

function validarCPF($cpf) {
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

try {
    // Validações
    if (empty($cpf)) {
        throw new Exception("CPF não fornecido", 400);
    }
    
    if (!validarCPF($cpf)) {
        throw new Exception("CPF inválido", 400);
    }

    // Inicializa cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_FOLLOWLOCATION => true
    ]);

    // Tentativa 1: ReceitaWS
    $url1 = "https://www.receitaws.com.br/v1/cpf/$cpf";
    curl_setopt($ch, CURLOPT_URL, $url1);
    $response = curl_exec($ch);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['nome'])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'cpf' => $cpf,
                    'nome' => $data['nome'],
                    'nascimento' => $data['nascimento'] ?? null,
                    'situacao' => $data['situacao'] ?? null
                ],
                'source' => 'receitaws'
            ]);
            exit;
        }
    }

    // Tentativa 2: MinhaReceita
    $url2 = "https://minhareceita.org/$cpf";
    curl_setopt($ch, CURLOPT_URL, $url2);
    $response = curl_exec($ch);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['nome'])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'cpf' => $cpf,
                    'nome' => $data['nome'],
                    'nascimento' => $data['data_nascimento'] ?? null,
                    'situacao' => $data['situacao_cadastral'] ?? null
                ],
                'source' => 'minhareceita'
            ]);
            exit;
        }
    }

    // Tentativa 3: Dados de teste se estiver em localhost
    if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['SERVER_ADDR'] === '127.0.0.1') {
        echo json_encode([
            'success' => true,
            'data' => [
                'cpf' => $cpf,
                'nome' => "Fulano de Tal (Dados de Teste)",
                'nascimento' => "01/01/1980",
                'situacao' => "Regular"
            ],
            'source' => 'localhost',
            'warning' => 'Dados simulados para ambiente de desenvolvimento'
        ]);
        exit;
    }

    throw new Exception("Nenhuma fonte de dados disponível no momento", 503);

} catch (Exception $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'cpf' => $cpf,
        'error_code' => $code
    ]);
    
    logConsulta("ERRO: " . $e->getMessage() . " | CPF: $cpf | Código: $code");
} finally {
    if (isset($ch)) {
        curl_close($ch);
    }
}