<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
header('Content-Type: text/html; charset=utf-8');

// Verifica se o formulário foi submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cpf'])) {
    // Remove caracteres não numéricos do CPF
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
    
    // Validação do CPF
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        die(json_encode(['error' => 'CPF inválido']));
    }
    
    // URL do site de consulta
    $url = 'https://pesquisacpf.com.br/';
    
    // Configuração do cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_REFERER => $url,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        CURLOPT_COOKIEJAR => 'cookies.txt',
        CURLOPT_COOKIEFILE => 'cookies.txt',
    ]);
    
    // Primeira requisição para obter cookies/tokens
    $response = curl_exec($ch);
    if ($response === false) {
        die(json_encode(['error' => 'Erro ao acessar o site: ' . curl_error($ch)]));
    }
    
    // Configura a requisição POST com o CPF
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['cpf' => $cpf]));
    
    // Executa a consulta
    $result = curl_exec($ch);
    curl_close($ch);
    
    if ($result === false) {
        die(json_encode(['error' => 'Erro na consulta']));
    }
    
    // Extrai os dados (ATUALIZE ESTES PADRÕES CONFORME O SITE)
    $data = [];
    if (preg_match('/<div[^>]*class="nome"[^>]*>(.*?)<\/div>/is', $result, $matches)) {
        $data['nome'] = trim(strip_tags($matches[1]));
    }
    
    if (preg_match('/<div[^>]*class="nascimento"[^>]*>(.*?)<\/div>/is', $result, $matches)) {
        $data['nascimento'] = trim(strip_tags($matches[1]));
    }
    
    // Se não encontrou dados, verifica se há CAPTCHA
    if (empty($data)) {
        if (strpos($result, 'captcha') !== false) {
            die(json_encode(['error' => 'O site exige CAPTCHA. Não é possível automatizar.']));
        }
        die(json_encode(['error' => 'Dados não encontrados no HTML']));
    }
    
    // Retorna os dados em JSON
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de CPF</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
        #resultado { margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Consulta de CPF</h1>
    <form id="consultaForm">
        <label for="cpf">CPF (somente números):</label>
        <input type="text" id="cpf" name="cpf" placeholder="Digite o CPF" required maxlength="11">
        <button type="submit">Consultar</button>
    </form>
    
    <div id="resultado" style="display:none;"></div>
    
    <script>
    document.getElementById('consultaForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
        const resultadoDiv = document.getElementById('resultado');
        
        resultadoDiv.style.display = 'none';
        resultadoDiv.innerHTML = '';
        
        try {
            const response = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `cpf=${encodeURIComponent(cpf)}`
            });
            
            const data = await response.json();
            
            if (data.error) {
                resultadoDiv.innerHTML = `<p class="error">Erro: ${data.error}</p>`;
            } else {
                resultadoDiv.innerHTML = `
                    <h2>Resultado</h2>
                    <p><strong>CPF:</strong> ${cpf}</p>
                    <p><strong>Nome:</strong> ${data.nome || 'Não encontrado'}</p>
                    <p><strong>Nascimento:</strong> ${data.nascimento || 'Não encontrado'}</p>
                `;
            }
            resultadoDiv.style.display = 'block';
            
        } catch (error) {
            resultadoDiv.innerHTML = `<p class="error">Erro na requisição: ${error.message}</p>`;
            resultadoDiv.style.display = 'block';
        }
    });
    </script>
</body>
</html>