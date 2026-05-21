<?php
/**
 * MAZ-Pharma API  —  Configuration partagée
 * Toutes les routes incluent ce fichier en première instruction.
 *
 * Le mot de passe N'EST JAMAIS écrit en dur ici : il vient de l'environnement
 * Railway (Variables → DB_PASSWORD). En local, créez un fichier .env (voir
 * .env.example) ou exportez les variables avant de lancer PHP.
 */

// --- 1) Affichage des erreurs (à désactiver en production) ---
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// --- 2) Headers JSON + CORS ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Token');

// OPTIONS preflight (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- 3) Lecture .env si présent (utile en local) ---
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
        if ($k && $v !== null) putenv(trim($k) . '=' . trim($v, " \t\n\r\0\x0B\"'"));
    }
}

// --- 4) Variables de connexion Railway (variables d'environnement) ---
$host = getenv('DB_HOST') ?: 'hopper.proxy.rlwy.net';
$port = (int)(getenv('DB_PORT') ?: 41587);
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$db   = getenv('DB_NAME') ?: 'Stage2026';

// --- 5) Connexion PDO MySQL ---
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'DB unavailable', 'detail' => $e->getMessage()]);
    exit;
}

// =====================================================================
// HELPERS génériques
// =====================================================================

/** Réponse JSON propre + arrêt */
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lecture du body JSON */
function jsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

/** Méthode HTTP courante */
function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}

/** Récupère un paramètre GET sécurisé */
function q(string $name, $default = null) {
    return $_GET[$name] ?? $default;
}

/**
 * Vérifie le token API.
 * Token simple = bcrypt(role|user_id|salt) en base64.
 * Pour la démo on accepte également le rôle en clair dans le header X-Api-Role.
 */
function requireRole(array $roles): array {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);
    if (!$token) jsonResponse(['error' => 'Token manquant'], 401);

    $payload = json_decode(base64_decode($token), true);
    if (!$payload || !isset($payload['role'])) jsonResponse(['error' => 'Token invalide'], 401);
    if (!in_array($payload['role'], $roles, true)) jsonResponse(['error' => 'Rôle insuffisant'], 403);

    return $payload;
}

/** Génère un token simple à partir d'un compte */
function makeToken(array $compte): string {
    return base64_encode(json_encode([
        'id'   => (int)$compte['id_compte'],
        'user' => $compte['Nom_d_utilisateur'],
        'role' => $compte['Role'],
        'iat'  => time()
    ]));
}

/** Génère une référence BC unique */
function newBcRef(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM Commande WHERE YEAR(Date_et_heure_de_la_commande) = " . $year);
    $n = (int)$stmt->fetchColumn() + 1;
    return sprintf('BC-%s-%05d', $year, $n);
}

/** Génère une référence BL unique */
function newBlRef(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM Bon_de_livraison WHERE YEAR(date_reception) = " . $year);
    $n = (int)$stmt->fetchColumn() + 1;
    return sprintf('BL-%s-%05d', $year, $n);
}
