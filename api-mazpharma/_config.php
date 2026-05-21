<?php
/**
 * MAZ-Pharma API  —  Configuration partagée
 * Version robuste qui marche quelle que soit la version PHP/Nixpacks.
 */

// --- DEBUG temporaire ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// --- Headers JSON + CORS ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Lecture .env local ---
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1], " \t\n\r\0\x0B\"'"));
        }
    }
}

// --- Variables de connexion ---
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$db   = getenv('DB_NAME');

$missing = [];
if (!$host) $missing[] = 'DB_HOST';
if (!$port) $missing[] = 'DB_PORT';
if (!$user) $missing[] = 'DB_USER';
if (!$pass) $missing[] = 'DB_PASSWORD';
if (!$db)   $missing[] = 'DB_NAME';

if (!empty($missing)) {
    http_response_code(503);
    echo json_encode([
        'error'    => 'Variables d environnement manquantes',
        'missing'  => $missing,
        'hint'     => 'Configurez ces variables dans Railway > Variables du service mazpharma-api'
    ], JSON_PRETTY_PRINT);
    exit;
}

// --- Vérification du driver mysql ---
$drivers = PDO::getAvailableDrivers();
if (!in_array('mysql', $drivers, true)) {
    http_response_code(503);
    echo json_encode([
        'error'   => 'Driver PDO mysql absent',
        'drivers' => $drivers,
        'php_version' => PHP_VERSION,
        'hint'    => 'Le driver pdo_mysql n est pas installe sur cette image PHP'
    ], JSON_PRETTY_PRINT);
    exit;
}

// --- Connexion PDO (SANS MYSQL_ATTR_INIT_COMMAND qui peut etre absente) ---
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5
        ]
    );
    // Forcer utf8mb4 par requete SQL (independant de la constante)
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode([
        'error'  => 'Echec connexion BD',
        'detail' => $e->getMessage(),
        'connection_attempt' => [
            'host' => $host,
            'port' => $port,
            'db'   => $db,
            'user' => $user
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// =====================================================================
// HELPERS génériques
// =====================================================================

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

function method(): string { return $_SERVER['REQUEST_METHOD']; }

function q(string $name, $default = null) { return $_GET[$name] ?? $default; }

function requireRole(array $roles): array {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);
    if (!$token) jsonResponse(['error' => 'Token manquant'], 401);

    $payload = json_decode(base64_decode($token), true);
    if (!$payload || !isset($payload['role'])) jsonResponse(['error' => 'Token invalide'], 401);
    if (!in_array($payload['role'], $roles, true)) jsonResponse(['error' => 'Role insuffisant'], 403);
    return $payload;
}

function makeToken(array $compte): string {
    return base64_encode(json_encode([
        'id'   => (int)$compte['id_compte'],
        'user' => $compte['Nom_d_utilisateur'],
        'role' => $compte['Role'],
        'iat'  => time()
    ]));
}

function newBcRef(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM Commande WHERE YEAR(Date_et_heure_de_la_commande) = " . $year);
    $n = (int)$stmt->fetchColumn() + 1;
    return sprintf('BC-%s-%05d', $year, $n);
}

function newBlRef(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM Bon_de_livraison WHERE YEAR(date_reception) = " . $year);
    $n = (int)$stmt->fetchColumn() + 1;
    return sprintf('BL-%s-%05d', $year, $n);
}
