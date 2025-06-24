<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Verifica se foi recebido um CPF via GET ou POST
$requestMethod = $_SERVER['REQUEST_METHOD'];
$cpf = $requestMethod === 'POST' ? ($_POST['cpf'] ?? '') : ($_GET['cpf'] ?? '');

if (empty($cpf)) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF não fornecido']);
    exit;
}

// Remove caracteres não numéricos do CPF
$cpf = preg_replace('/[^0-9]/', '', $cpf);

// Validação do CPF
if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido']);
    exit;
}

/**
 * SIMULAÇÃO DE RESPOSTA - PARA DESENVOLVIMENTO
 * 
 * ATENÇÃO: Em produção, substitua por uma consulta real a um serviço autorizado
 * como Receita WS (https://receitaws.com.br/) ou outra API oficial
 */
$nomes = [
    'Ana Carolina Silva', 
    'Bruno Oliveira Santos', 
    'Carlos Eduardo Pereira',
    'Daniela Rodrigues Almeida'
];

$data = [
    'success' => true,
    'cpf' => $cpf,
    'nome' => $nomes[array_rand($nomes)],
    'nascimento' => sprintf("%02d/%02d/%04d", rand(1, 28), rand(1, 12), rand(1950, 2000)),
    'situacao_cadastral' => (rand(0, 1) ? 'Regular' : 'Irregular'),
    'digito_verificador' => substr($cpf, -2),
    'consulta_realizada_em' => date('d/m/Y H:i:s'),
    'mensagem' => 'Dados simulados para desenvolvimento'
];

// Simula tempo de resposta da API
sleep(rand(1, 3));

// Retorna os dados em JSON
echo json_encode($data);