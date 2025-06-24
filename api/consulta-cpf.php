<?php
phpinfo();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verifica extensões necessárias
if (!extension_loaded('fileinfo')) {
    die('Erro: A extensão fileinfo não está habilitada no seu servidor!');
}
if (!extension_loaded('dom')) {
    die('Erro: A extensão DOM não está habilitada no seu servidor!');
}

// Função para limpar e validar o CPF
function limparCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    return (strlen($cpf)) === 11 ? $cpf : false;
}

// Função para realizar a pesquisa com tratamento avançado
function pesquisarCPF($cpf) {
    $url = 'https://pesquisacpf.com.br/';
    
    try {
        // Configuração do contexto para file_get_contents
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                          . "Accept: text/html,application/xhtml+xml\r\n"
                          . "Accept-Language: pt-BR,pt;q=0.9\r\n"
                          . "Referer: $url\r\n",
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        $context = stream_context_create($options);
        
        // Primeira requisição para obter o token CSRF
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('Erro na conexão: Não foi possível acessar o site');
        }

        // Verifica se foi redirecionado para página de bloqueio
        if (strpos($response, 'Cloudflare') !== false || strpos($response, 'DDoS protection') !== false) {
            throw new Exception('O site está bloqueando o acesso (proteção Cloudflare)');
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        
        // Extrai o token CSRF com validação
        $tokenNodes = $xpath->query('//input[@name="csrf_token"]/@value');
        if ($tokenNodes->length === 0) {
            throw new Exception('Token CSRF não encontrado - estrutura do site pode ter mudado');
        }
        $token = $tokenNodes->item(0)->nodeValue;

        // Prepara os dados para o POST
        $postData = http_build_query([
            'cpf' => $cpf,
            'csrf_token' => $token,
            'acao' => 'pesquisar'
        ]);

        // Configuração para o POST
        $options['http']['method'] = 'POST';
        $options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"
                                   . "X-Requested-With: XMLHttpRequest\r\n"
                                   . "Content-Length: " . strlen($postData) . "\r\n";
        $options['http']['content'] = $postData;
        
        $context = stream_context_create($options);

        // Executa a pesquisa
        $resultado = file_get_contents($url, false, $context);
        
        if ($resultado === false) {
            throw new Exception('Erro ao enviar os dados para pesquisa');
        }

        // Analisa o resultado
        @$dom->loadHTML($resultado);
        $xpath = new DOMXPath($dom);
        
        // Extrai os dados com seletores mais robustos
        $resultadoDiv = $xpath->query('//div[contains(@class, "resultado")]');
        if ($resultadoDiv->length === 0) {
            throw new Exception('Div de resultados não encontrada - layout do site pode ter mudado');
        }

        // Extrai nome completo com tratamento de erro
        $nome = 'Não encontrado';
        $nomeNodes = $xpath->query('.//p[contains(., "Nome:")]', $resultadoDiv->item(0));
        if ($nomeNodes->length > 0) {
            $nome = trim(str_replace(['Nome:', '⠀'], '', $nomeNodes->item(0)->nodeValue));
        }

        // Extrai data de nascimento com tratamento de erro
        $nascimento = 'Não encontrado';
        $nascimentoNodes = $xpath->query('.//p[contains(., "Nascimento:")]', $resultadoDiv->item(0));
        if ($nascimentoNodes->length > 0) {
            $nascimento = trim(str_replace(['Nascimento:', '⠀'], '', $nascimentoNodes->item(0)->nodeValue));
        }

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
    
    if (!$cpf) {
        $resultado = [
            'status' => 'error',
            'message' => 'CPF inválido. Deve conter exatamente 11 dígitos.'
        ];
    } else {
        $resultado = pesquisarCPF($cpf);
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de CPF</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            background-color: #f5f5f5;
        }
        .container {
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 30px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        label {
            font-weight: bold;
            color: #34495e;
        }
        input[type="text"] {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border 0.3s;
        }
        input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
        }
        button {
            background: #3498db;
            color: white;
            padding: 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: background 0.3s;
        }
        button:hover {
            background: #2980b9;
        }
        .resultado {
            margin-top: 30px;
            padding: 20px;
            border-radius: 6px;
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .success {
            background: #e8f8f5;
            border-left: 5px solid #2ecc71;
        }
        .error {
            background: #fdedec;
            border-left: 5px solid #e74c3c;
        }
        .info {
            margin-top: 40px;
            font-size: 14px;
            color: #7f8c8d;
            border-top: 1px solid #ecf0f1;
            padding-top: 20px;
        }
        .debug {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px dashed #ddd;
            font-family: monospace;
            font-size: 13px;
            display: none;
        }
        .debug-toggle {
            color: #3498db;
            cursor: pointer;
            font-size: 13px;
            user-select: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Consulta de CPF</h1>
        
        <form method="POST">
            <div class="form-group">
                <label for="cpf">Digite o CPF:</label>
                <input type="text" id="cpf" name="cpf" placeholder="000.000.000-00" required
                       pattern="\d{3}\.?\d{3}\.?\d{3}-?\d{2}" title="Formato: 000.000.000-00">
            </div>
            <button type="submit">Consultar</button>
        </form>
        
        <?php if (!empty($resultado)): ?>
            <div class="resultado <?php echo $resultado['status']; ?>">
                <?php if ($resultado['status'] === 'success'): ?>
                    <h3>Resultado da Consulta</h3>
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($resultado['nome']); ?></p>
                    <p><strong>Data de Nascimento:</strong> <?php echo htmlspecialchars($resultado['nascimento']); ?></p>
                <?php else: ?>
                    <h3>Erro na Consulta</h3>
                    <p><?php echo htmlspecialchars($resultado['message']); ?></p>
                    <?php if (strpos($resultado['message'], 'Cloudflare') !== false): ?>
                        <p><small>Solução: Tente novamente mais tarde ou use uma conexão diferente.</small></p>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($_POST['debug'])): ?>
                    <div class="debug-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                        ▼ Mostrar detalhes técnicos
                    </div>
                    <div class="debug">
                        <strong>Debug Info:</strong><br>
                        CPF pesquisado: <?php echo htmlspecialchars($_POST['cpf']); ?><br>
                        Timestamp: <?php echo date('Y-m-d H:i:s'); ?><br>
                        IP: <?php echo $_SERVER['REMOTE_ADDR']; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="info">
            <p><strong>Aviso Legal:</strong> Este serviço utiliza a API pública do pesquisacpf.com.br apenas para fins educacionais e de demonstração técnica. Não armazenamos os dados pesquisados e não nos responsabilizamos pelo uso indevido desta ferramenta.</p>
        </div>
    </div>
    
    <script>
        // Máscara para o CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length > 3) {
                value = value.substring(0, 3) + '.' + value.substring(3);
            }
            if (value.length > 7) {
                value = value.substring(0, 7) + '.' + value.substring(7);
            }
            if (value.length > 11) {
                value = value.substring(0, 11) + '-' + value.substring(11);
            }
            
            e.target.value = value.substring(0, 14);
        });
        
        // Validação do formulário
        document.querySelector('form').addEventListener('submit', function(e) {
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            if (cpf.length !== 11) {
                alert('CPF deve conter 11 dígitos numéricos');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>