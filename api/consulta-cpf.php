<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
error_reporting(0); // Desativa erros para o usuário final

// Configurações
$logFile = __DIR__ . '/cpf_api.log';
$cpf = $_GET['cpf'] ?? '';

function logConsulta($message) {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Validação do CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
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
    // Validação básica
    if (empty($cpf)) {
        throw new Exception("CPF não fornecido");
    }
    
    if (!validarCPF($cpf)) {
        throw new Exception("CPF inválido - formato incorreto ou dígitos verificadores inválidos");
    }

    // Tenta primeiro a ReceitaWS
    $url = "https://www.receitaws.com.br/v1/cpf/" . $cpf;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        
        if (isset($data['nome'])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'cpf' => $cpf,
                    'nome' => $data['nome'],
                    'nascimento' => $data['nascimento'] ?? 'Não informado'
                ],
                'source' => 'receitaws'
            ]);
            exit;
        }
    }

    // Fallback para Minha Receita (serviço alternativo)
    $url = "https://minhareceita.org/" . $cpf;
    curl_setopt($ch, CURLOPT_URL, $url);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        
        if (isset($data['nome'])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'cpf' => $cpf,
                    'nome' => $data['nome'],
                    'nascimento' => $data['data_nascimento'] ?? 'Não informado'
                ],
                'source' => 'minhareceita'
            ]);
            exit;
        }
    }

    throw new Exception("Não foi possível obter dados para este CPF");

} catch (Exception $e) {
    logConsulta("ERRO: " . $e->getMessage() . " | CPF: " . $cpf);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'cpf' => $cpf
    ]);
} finally {
    if (isset($ch)) {
        curl_close($ch);
    }
}