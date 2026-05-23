<?php
require_once __DIR__ . '/_config.php';

// POST /push_token.php  { token: "ExponentPushToken[...]" }
// Enregistre ou met à jour le push token Expo du compte connecté
if (method() === 'POST') {
    $payload = requireRole(['USER', 'ADMIN', 'SUPERADMIN']);
    $b       = jsonBody();
    $token   = trim($b['token'] ?? '');

    if (!$token || !str_starts_with($token, 'ExponentPushToken')) {
        jsonResponse(['error' => 'Token invalide'], 400);
    }

    $pdo->prepare("
        INSERT INTO Push_Token(id_compte, token)
        VALUES(:id, :t)
        ON DUPLICATE KEY UPDATE token = :t, date_maj = NOW()
    ")->execute([':id' => $payload['id'], ':t' => $token]);

    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
