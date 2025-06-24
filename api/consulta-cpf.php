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

// Função melhorada para obter conteúdo de URL
function getUrlContent($url, $context = null) {
    $allowUrlFopen = ini_get('allow_url_fopen');
    $curlEnabled = function_exists('curl_init');
    
    if (!$allowUrlFopen && !$curlEnabled) {
        throw new Exception('Nenhum método disponível para acessar URLs externas');
    }

    // Tenta com file_get_contents primeiro
    if ($allowUrlFopen) {
        $content = @file_get_contents($url, false, $context);
        if ($content !== false) return $content;
    }

    // Fallback para cURL
    if ($curlEnabled) {
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ];
        
        if ($context && is_resource($context)) {
            $optionsContext = stream_context_get_options($context);
            if (isset($optionsContext['http']['header'])) {
                $headers = array_filter(array_map('trim', explode("\r\n", $optionsContext['http']['header'])));
                $parsedHeaders = [];
                foreach ($headers as $header) {
                    if (strpos($header, ':') !== false) {
                        $parsedHeaders[] = $header;
                    }
                }
                if (!empty($parsedHeaders)) {
                    $options[CURLOPT_HTTPHEADER] = $parsedHeaders;
                }
            }
            
            if (isset($optionsContext['http']['method']) && strtoupper($optionsContext['http']['method']) === 'POST') {
                $options[CURLOPT_POST] = true;
                if (isset($optionsContext['http']['content'])) {
                    $options[CURLOPT_POSTFIELDS] = $optionsContext['http']['content'];
                }
            }
        }
        
        curl_setopt_array($ch, $options);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($content === false) {
            throw new Exception("Erro cURL: $error (HTTP Code: $httpCode)");
        }
        
        return $content;
    }

    throw new Exception('Todos os métodos de requisição falharam');
}

// Função para realizar a pesquisa com tratamento avançado
function pesquisarCPF($cpf) {
    $url = 'https://pesquisacpf.com.br/';
    
    try {
        // Configuração do contexto para a primeira requisição (GET)
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
        $response = getUrlContent($url, $context);
        
        // Debug: Verificar o conteúdo da resposta
        if (isset($_POST['debug'])) {
            file_put_contents('debug_get.html', $response);
        }

        // Verifica bloqueio Cloudflare
        if (strpos($response, 'Cloudflare') !== false || strpos($response, 'DDoS protection') !== false) {
            throw new Exception('O site está bloqueando o acesso (proteção Cloudflare)');
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        
        // Extrai o token CSRF
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
        $resultado = getUrlContent($url, $context);
        
        // Debug: Verificar o conteúdo da resposta
        if (isset($_POST['debug'])) {
            file_put_contents('debug_post.html', $resultado);
        }

        // Analisa o resultado
        @$dom->loadHTML($resultado);
        $xpath = new DOMXPath($dom);
        
        // Extrai os dados
        $resultadoDiv = $xpath->query('//div[contains(@class, "resultado")]');
        if ($resultadoDiv->length === 0) {
            throw new Exception('Div de resultados não encontrada - verifique debug_post.html');
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
            'status' => 'success',
            'debug' => isset($_POST['debug']) ? ['get' => 'debug_get.html', 'post' => 'debug_post.html'] : null
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