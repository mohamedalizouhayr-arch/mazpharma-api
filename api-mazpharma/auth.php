<?php
require_once __DIR__ . '/_config.php';

$action = q('action', 'login');

if (method() === 'POST' && $action === 'login') {
    $body = jsonBody();
    $login = trim($body['login'] ?? '');
    $pwd   = $body['password'] ?? '';
    if (!$login || !$pwd) jsonResponse(['error' => 'Login et mot de passe requis'], 400);

    $stmt = $pdo->prepare("SELECT * FROM Compte WHERE Nom_d_utilisateur = :u AND actif = 1 LIMIT 1");
    $stmt->execute([':u' => $login]);
    $compte = $stmt->fetch();

    // Pour la démo : on accepte aussi 'demo123' en clair tant que le hash bcrypt
    // dans la BD n'a pas été régénéré. À supprimer en production.
    $ok = false;
    if ($compte) {
        if (password_verify($pwd, $compte['Mots_de_passe'])) $ok = true;
        elseif ($pwd === 'demo123') $ok = true;
    }

    if (!$ok) jsonResponse(['error' => 'Identifiants invalides'], 401);

    $pdo->prepare("UPDATE Compte SET derniere_connexion = NOW() WHERE id_compte = ?")
        ->execute([$compte['id_compte']]);

    jsonResponse([
        'token' => makeToken($compte),
        'user'  => [
            'id'    => (int)$compte['id_compte'],
            'login' => $compte['Nom_d_utilisateur'],
            'role'  => $compte['Role'],
            'email' => $compte['email']
        ]
    ]);
}

if (method() === 'GET' && $action === 'me') {
    $payload = requireRole(['SUPERADMIN','ADMIN','USER']);
    jsonResponse(['user' => $payload]);
}

jsonResponse(['error' => 'Action inconnue'], 404);
