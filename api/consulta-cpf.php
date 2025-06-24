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
    return (strlen($cpf)) === 11 ? $cpf : false;
}

// Função ultra-robusta para requisições HTTP
function getUrlContent($url, $postData = null, $headers = []) {
    // Método 1: Tentativa com cURL (recomendado)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9'
            ], $headers)
        ];
        
        if ($postData) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postData;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response !== false) {
            return $response;
        }
    }
    
    // Método 2: Tentativa com file_get_contents (se habilitado)
    if (ini_get('allow_url_fopen')) {
        $contextOptions = [
            'http' => [
                'method' => $postData ? 'POST' : 'GET',
                'header' => implode("\r\n", array_merge([
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: pt-BR,pt;q=0.9'
                ], $headers)),
                'timeout' => 20,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        if ($postData) {
            $contextOptions['http']['content'] = $postData;
            $contextOptions['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        }
        
        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            return $response;
        }
    }
    
    // Método 3: Tentativa com sockets (fallback final)
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'];
    $path = $parsedUrl['path'] ?? '/';
    $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
    $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
    
    $fp = @fsockopen(
        $parsedUrl['scheme'] === 'https' ? 'ssl://' . $host : $host, 
        $port, 
        $errno, 
        $errstr, 
        20
    );
    
    if ($fp) {
        $out = ($postData ? "POST $path$query HTTP/1.1\r\n" : "GET $path$query HTTP/1.1\r\n") .
               "Host: $host\r\n" .
               implode("\r\n", array_merge([
                   'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                   'Accept: text/html,application/xhtml+xml',
                   'Accept-Language: pt-BR,pt;q=0.9',
                   'Connection: Close'
               ], $headers)) . "\r\n\r\n";
        
        if ($postData) {
            $out .= $postData;
        }
        
        fwrite($fp, $out);
        $response = '';
        
        while (!feof($fp)) {
            $response .= fgets($fp, 128);
        }
        
        fclose($fp);
        
        // Extrai o corpo da resposta HTTP
        $parts = explode("\r\n\r\n", $response);
        if (count($parts) > 1) {
            return $parts[1];
        }
    }
    
    throw new Exception('Não foi possível conectar ao servidor remoto. Métodos tentados: ' .
                       (function_exists('curl_init') ? 'cURL, ' : '') .
                       (ini_get('allow_url_fopen') ? 'file_get_contents, ' : '') .
                       'sockets. Último erro: ' . ($error ?? $errstr ?? 'Desconhecido'));
}

// Função para realizar a pesquisa
function pesquisarCPF($cpf) {
    $url = 'https://pesquisacpf.com.br/';
    
    try {
        // Primeira requisição para obter o token CSRF
        $response = getUrlContent($url);
        
        if (strpos($response, 'Cloudflare') !== false) {
            throw new Exception('O site está bloqueando o acesso (proteção Cloudflare)');
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        
        // Extrai o token CSRF
        $tokenNodes = $xpath->query('//input[@name="csrf_token"]/@value');
        if ($tokenNodes->length === 0) {
            throw new Exception('Token CSRF não encontrado');
        }
        $token = $tokenNodes->item(0)->nodeValue;

        // Prepara os dados para o POST
        $postData = http_build_query([
            'cpf' => $cpf,
            'csrf_token' => $token,
            'acao' => 'pesquisar'
        ]);

        // Segunda requisição com os dados
        $resultado = getUrlContent($url, $postData, [
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $url
        ]);

        // Analisa o resultado
        @$dom->loadHTML($resultado);
        $xpath = new DOMXPath($dom);
        
        // Extrai os dados
        $resultadoDiv = $xpath->query('//div[contains(@class, "resultado")]');
        if ($resultadoDiv->length === 0) {
            throw new Exception('Div de resultados não encontrada');
        }

        // Extrai nome completo
        $nome = 'Não encontrado';
        $nomeNodes = $xpath->query('.//p[contains(., "Nome:")]', $resultadoDiv->item(0));
        if ($nomeNodes->length > 0) {
            $nome = trim(str_replace(['Nome:', '⠀'], '', $nomeNodes->item(0)->nodeValue));
        }

        // Extrai data de nascimento
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

<!-- [O restante do HTML permanece igual] -->

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de CPF</title>
    <style>
        /* [Seu CSS existente permanece igual] */
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
                                <?php if (file_exists('debug_get.html')): ?>
                                    <li><a href="debug_get.html" target="_blank">Requisição GET inicial</a></li>
                                <?php endif; ?>
                                <?php if (file_exists('debug_post.html')): ?>
                                    <li><a href="debug_post.html" target="_blank">Requisição POST com resultados</a></li>
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
        // [Seu JavaScript existente permanece igual]
    </script>
</body>
</html>