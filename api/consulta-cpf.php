<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verifica extensão necessária
if (!extension_loaded('dom')) {
    die('Erro: A extensão DOM não está habilitada no seu servidor!');
}

// Função para limpar e validar o CPF
function limparCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    return (strlen($cpf) === 11 ? $cpf : false;
}

// Função para obter conteúdo com múltiplos fallbacks
function getUrlContent($url, $postData = null, $headers = []) {
    // Método 1: cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer: https://pesquisacpf.com.br/'
            ], $headers),
            CURLOPT_COOKIEJAR => 'cookie.txt',
            CURLOPT_COOKIEFILE => 'cookie.txt',
            CURLOPT_ENCODING => 'gzip, deflate, br'
        ];
        
        if ($postData) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postData;
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response !== false && $httpCode === 200) {
            return $response;
        }
    }
    
    // Método 2: file_get_contents
    if (ini_get('allow_url_fopen')) {
        $contextOptions = [
            'http' => [
                'method' => $postData ? 'POST' : 'GET',
                'header' => implode("\r\n", array_merge([
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Referer: https://pesquisacpf.com.br/'
                ], $headers)),
                'timeout' => 30
            ]
        ];
        
        if ($postData) {
            $contextOptions['http']['content'] = $postData;
        }
        
        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            return $response;
        }
    }
    
    throw new Exception('Falha ao carregar a URL. Verifique sua conexão ou tente novamente mais tarde.');
}

// Função para pesquisar CPF com tratamento melhorado
function pesquisarCPF($cpf) {
    try {
        // Primeira requisição para obter o token CSRF
        $url = 'https://pesquisacpf.com.br/';
        
        // Adiciona delay aleatório para evitar bloqueio
        sleep(rand(1, 3));
        
        $response = getUrlContent($url);
        
        // Salva para debug
        if (isset($_POST['debug'])) {
            file_put_contents('debug_get.html', $response);
        }

        // Verifica se foi bloqueado pelo Cloudflare
        if (strpos($response, 'Cloudflare') !== false || 
            strpos($response, 'Checking your browser') !== false ||
            strpos($response, 'jschl_vc') !== false ||
            strpos($response, 'DDoS protection by Cloudflare') !== false) {
            throw new Exception('Acesso bloqueado pelo Cloudflare. Tente novamente mais tarde ou use uma conexão diferente.');
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        
        // Tenta encontrar o token CSRF de várias formas
        $token = null;
        
        // Tentativa 1: Procurando por input com name="csrf_token"
        $tokenNodes = $xpath->query('//input[@name="csrf_token"]/@value');
        if ($tokenNodes->length > 0) {
            $token = $tokenNodes->item(0)->nodeValue;
        }
        
        // Tentativa 2: Procurando em meta tags
        if (!$token) {
            $metaNodes = $xpath->query('//meta[@name="csrf-token"]/@content');
            if ($metaNodes->length > 0) {
                $token = $metaNodes->item(0)->nodeValue;
            }
        }
        
        // Tentativa 3: Procurando em scripts
        if (!$token) {
            $scriptNodes = $xpath->query('//script[contains(text(), "csrf_token")]');
            foreach ($scriptNodes as $script) {
                if (preg_match('/csrf_token\s*:\s*["\']([^"\']+)["\']/', $script->nodeValue, $matches)) {
                    $token = $matches[1];
                    break;
                }
            }
        }
        
        if (!$token) {
            throw new Exception('Token CSRF não encontrado na página. Estrutura do site pode ter mudado.');
        }

        // Segunda requisição com os dados
        $postData = http_build_query([
            'cpf' => $cpf,
            'csrf_token' => $token,
            'acao' => 'pesquisar'
        ]);

        // Adiciona delay antes da segunda requisição
        sleep(rand(1, 2));
        
        $resultado = getUrlContent($url, $postData, [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://pesquisacpf.com.br'
        ]);
        
        // Salva para debug
        if (isset($_POST['debug'])) {
            file_put_contents('debug_post.html', $resultado);
        }

        // Analisa o resultado
        @$dom->loadHTML($resultado);
        $xpath = new DOMXPath($dom);
        
        // Extrai os dados - várias tentativas de localização
        $dados = ['nome' => 'Não encontrado', 'nascimento' => 'Não encontrado'];
        
        // Tentativa 1: Div com classe "resultado"
        $resultadoDiv = $xpath->query('//div[contains(@class, "resultado")]');
        if ($resultadoDiv->length > 0) {
            // Nome
            $nomeNodes = $xpath->query('.//p[contains(., "Nome:")]', $resultadoDiv->item(0));
            if ($nomeNodes->length > 0) {
                $dados['nome'] = trim(str_replace(['Nome:', '⠀'], '', $nomeNodes->item(0)->nodeValue));
            }
            
            // Nascimento
            $nascimentoNodes = $xpath->query('.//p[contains(., "Nascimento:")]', $resultadoDiv->item(0));
            if ($nascimentoNodes->length > 0) {
                $dados['nascimento'] = trim(str_replace(['Nascimento:', '⠀'], '', $nascimentoNodes->item(0)->nodeValue));
            }
        }
        
        // Tentativa 2: Tabela de resultados
        if ($dados['nome'] === 'Não encontrado') {
            $tableRows = $xpath->query('//table//tr');
            foreach ($tableRows as $row) {
                $cells = $xpath->query('.//td', $row);
                if ($cells->length === 2) {
                    $label = trim($cells->item(0)->nodeValue);
                    $value = trim($cells->item(1)->nodeValue);
                    
                    if (stripos($label, 'Nome') !== false) {
                        $dados['nome'] = $value;
                    } elseif (stripos($label, 'Nascimento') !== false) {
                        $dados['nascimento'] = $value;
                    }
                }
            }
        }

        return [
            'nome' => $dados['nome'],
            'nascimento' => $dados['nascimento'],
            'status' => 'success',
            'debug' => isset($_POST['debug']) ? [
                'get' => 'debug_get.html',
                'post' => 'debug_post.html'
            ] : null
        ];
        
    } catch (Exception $e) {
        return [
            'nome' => '',
            'nascimento' => '',
            'status' => 'error',
            'message' => $e->getMessage(),
            'debug' => isset($_POST['debug']) ? [
                'get' => file_exists('debug_get.html') ? 'debug_get.html' : null,
                'post' => file_exists('debug_post.html') ? 'debug_post.html' : null
            ] : null
        ];
    }
}

// Processa o formulário
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
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #2980b9;
        }
        .resultado {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        .resultado.success {
            background-color: #dff0d8;
            border: 1px solid #d6e9c6;
            color: #3c763d;
        }
        .resultado.error {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
        }
        .info {
            margin-top: 30px;
            padding: 15px;
            background-color: #e7f4ff;
            border: 1px solid #b8daff;
            border-radius: 4px;
            font-size: 14px;
        }
        .debug-info {
            margin-top: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
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
            <div class="form-group">
                <label>
                    <input type="checkbox" name="debug" value="1"> Modo debug
                </label>
            </div>
            <button type="submit">Consultar</button>
        </form>
        
        <?php if (!empty($resultado)): ?>
            <div class="resultado <?php echo $resultado['status']; ?>">
                <?php if ($resultado['status'] === 'success'): ?>
                    <h3>Resultado da Consulta</h3>
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($resultado['nome']); ?></p>
                    <p><strong>Data de Nascimento:</strong> <?php echo htmlspecialchars($resultado['nascimento']); ?></p>
                    
                    <?php if (isset($resultado['debug'])): ?>
                        <div class="debug-info">
                            <h4>Informações de Debug</h4>
                            <p>Arquivos salvos para análise:</p>
                            <ul>
                                <li><a href="<?php echo $resultado['debug']['get']; ?>" target="_blank">Requisição GET inicial</a></li>
                                <li><a href="<?php echo $resultado['debug']['post']; ?>" target="_blank">Requisição POST com resultados</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <h3>Erro na Consulta</h3>
                    <p><?php echo htmlspecialchars($resultado['message']); ?></p>
                    
                    <?php if (isset($resultado['debug'])): ?>
                        <div class="debug-info">
                            <h4>Informações de Debug</h4>
                            <p>Arquivos salvos para análise:</p>
                            <ul>
                                <?php if (isset($resultado['debug']['get']) && file_exists($resultado['debug']['get'])): ?>
                                    <li><a href="<?php echo $resultado['debug']['get']; ?>" target="_blank">Requisição GET inicial</a></li>
                                <?php endif; ?>
                                <?php if (isset($resultado['debug']['post']) && file_exists($resultado['debug']['post'])): ?>
                                    <li><a href="<?php echo $resultado['debug']['post']; ?>" target="_blank">Requisição POST com resultados</a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (strpos($resultado['message'], 'Cloudflare') !== false): ?>
                        <p><small>Solução: Tente novamente mais tarde ou use uma conexão diferente.</small></p>
                    <?php endif; ?>
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
            var value = e.target.value.replace(/\D/g, '');
            
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
    </script>
</body>
</html> 