<?php
// Endpoint de diagnostic — sans inclure _config.php
// Permet de tester PHP même si la connexion BD échoue

header('Content-Type: application/json; charset=utf-8');

$result = [
    'php_version' => PHP_VERSION,
    'server_time' => date('c'),
    'extensions' => [
        'pdo'       => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mysqli'    => extension_loaded('mysqli'),
        'json'      => extension_loaded('json'),
        'openssl'   => extension_loaded('openssl'),
    ],
    'env_vars' => [
        'DB_HOST' => getenv('DB_HOST') ?: 'NON_DEFINI',
        'DB_PORT' => getenv('DB_PORT') ?: 'NON_DEFINI',
        'DB_USER' => getenv('DB_USER') ?: 'NON_DEFINI',
        'DB_NAME' => getenv('DB_NAME') ?: 'NON_DEFINI',
        'DB_PASSWORD_DEFINI' => getenv('DB_PASSWORD') ? 'OUI' : 'NON',
    ]
];

// Test connexion BD si tout est défini
if (extension_loaded('pdo_mysql') && getenv('DB_HOST')) {
    try {
        $pdo = new PDO(
            "mysql:host=" . getenv('DB_HOST') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_NAME') . ";charset=utf8mb4",
            getenv('DB_USER'), getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $count = $pdo->query("SELECT COUNT(*) FROM Produit")->fetchColumn();
        $result['db_test'] = ['status' => 'OK', 'nb_produits' => (int)$count];
    } catch (Exception $e) {
        $result['db_test'] = ['status' => 'KO', 'error' => $e->getMessage()];
    }
}

http_response_code(200);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
