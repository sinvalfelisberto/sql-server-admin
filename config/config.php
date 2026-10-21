<?php
// Configuração padrão. Para sobrescrever valores localmente, crie config.local.php
// retornando um array com as chaves desejadas (esse arquivo não é versionado).
return [
    'app_name'    => 'SQL Web Manager',
    'version'     => '1.0.0',

    // Servidor sugerido na tela de login (ex.: "localhost,1433" ou "host:1433").
    'default_server' => '',

    // Lista de servidores permitidos. Vazia = qualquer servidor pode ser informado.
    // Recomendado preencher em ambientes expostos para evitar uso como proxy de rede.
    'allowed_servers' => [],

    // Conexão
    'trust_server_certificate' => true,  // valor inicial do checkbox no login
    'encrypt'                  => null,  // null = padrão do driver; true/false para forçar
    'login_timeout'            => 10,    // segundos

    // Execução
    'query_timeout' => 120,    // segundos
    'max_rows'      => 10000,  // limite rígido de linhas retornadas por resultado

    // Sessão
    'session_timeout' => 3600, // segundos de inatividade até desconectar
];
