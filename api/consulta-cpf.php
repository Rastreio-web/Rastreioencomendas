<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

// Configurações básicas
$logFile = __DIR__ . '/scraper_test.log';
file_put_contents($logFile, "Teste iniciado em: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

try {
    $cpf = $_GET['cpf'] ?? '';
    
    // Simulação de resposta bem-sucedida para teste
    $response = [
        'success' => true,
        'data' => [
            'cpf' => $cpf,
            'nome' => 'Fulano de Tal',
            'nascimento' => '01/01/1980'
        ],
        'metadata' => [
            'http_code' => 200,
            'method' => 'Test',
            'source' => 'simulated'
        ]
    ];
    
    file_put_contents($logFile, "Resposta simulada enviada\n", FILE_APPEND);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    file_put_contents($logFile, "ERRO: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro de teste: ' . $e->getMessage()
    ]);
}