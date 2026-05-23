<?php
require_once __DIR__ . '/_config.php';

// ----- GET : liste des messages -----
if (method() === 'GET') {
    $payload      = requireRole(['ADMIN', 'USER']);
    $id_pharmacie = getPharmacieId($pdo, $payload);
    if (!$id_pharmacie) jsonResponse(['error' => 'Pharmacie introuvable'], 400);

    $stmt = $pdo->prepare("
        SELECT m.id_message, m.contenu, m.date_envoi, m.id_compte,
               COALESCE(p.Prenom, a.prenom, c.Nom_d_utilisateur) AS prenom,
               COALESCE(p.Nom,    a.nom,    '')                   AS nom,
               c.Role
          FROM Message m
          JOIN Compte   c ON c.id_compte = m.id_compte
          LEFT JOIN Personnel p ON p.id_compte = m.id_compte
          LEFT JOIN Admin     a ON a.id_compte = m.id_compte
         WHERE m.Id_pharmacie = :ph
         ORDER BY m.date_envoi ASC
         LIMIT 100
    ");
    $stmt->execute([':ph' => $id_pharmacie]);

    // nom de la pharmacie
    $nomStmt = $pdo->prepare("SELECT Nom FROM Pharmacie WHERE Id_pharmacie = :ph LIMIT 1");
    $nomStmt->execute([':ph' => $id_pharmacie]);
    $nom_pharmacie = $nomStmt->fetchColumn() ?: '';

    jsonResponse([
        'messages'      => $stmt->fetchAll(),
        'id_compte'     => $payload['id'],
        'nom_pharmacie' => $nom_pharmacie,
    ]);
}

// ----- POST : envoyer un message -----
if (method() === 'POST') {
    $payload      = requireRole(['ADMIN', 'USER']);
    $id_pharmacie = getPharmacieId($pdo, $payload);
    if (!$id_pharmacie) jsonResponse(['error' => 'Pharmacie introuvable'], 400);

    $raw     = file_get_contents('php://input');
    $b       = (is_array(json_decode($raw, true))) ? json_decode($raw, true) : [];
    $contenu = trim($b['contenu'] ?? '');

    if (!$contenu)              jsonResponse(['error' => 'Message vide'], 400);
    if (mb_strlen($contenu) > 1000) jsonResponse(['error' => 'Message trop long (max 1000 caractères)'], 400);

    $pdo->prepare("
        INSERT INTO Message(id_compte, Id_pharmacie, contenu)
        VALUES(:id, :ph, :contenu)
    ")->execute([
        ':id'      => $payload['id'],
        ':ph'      => $id_pharmacie,
        ':contenu' => $contenu,
    ]);
    $id_message = (int)$pdo->lastInsertId();

    // Répondre immédiatement au client avant de faire le push
    ob_clean();
    http_response_code(201);
    echo json_encode(['id_message' => $id_message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Fermer la connexion HTTP → le client reçoit la réponse maintenant
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        header('Content-Length: ' . ob_get_length());
        header('Connection: close');
        ob_end_flush();
        flush();
    }

    // Push notification envoyé APRÈS la réponse → ne bloque plus le client
    try {
        $stmtNom = $pdo->prepare("
            SELECT COALESCE(p.Prenom, a.prenom, c.Nom_d_utilisateur) AS prenom,
                   COALESCE(p.Nom, a.nom, '') AS nom
              FROM Compte c
              LEFT JOIN Personnel p ON p.id_compte = c.id_compte
              LEFT JOIN Admin     a ON a.id_compte = c.id_compte
             WHERE c.id_compte = :id LIMIT 1
        ");
        $stmtNom->execute([':id' => $payload['id']]);
        $exp    = $stmtNom->fetch();
        $nomExp = trim(($exp['prenom'] ?? '') . ' ' . ($exp['nom'] ?? '')) ?: 'Quelqu\'un';

        $stmtTok = $pdo->prepare("
            SELECT pt.token FROM Push_Token pt
             WHERE pt.id_compte IN (
                 SELECT id_compte FROM Personnel WHERE Id_pharmacie = :ph
                 UNION
                 SELECT id_compte FROM Admin WHERE Id_pharmacie = :ph
             )
               AND pt.id_compte <> :moi
        ");
        $stmtTok->execute([':ph' => $id_pharmacie, ':moi' => $payload['id']]);
        $tokens = array_column($stmtTok->fetchAll(), 'token');

        if (!empty($tokens)) {
            $payload_push = array_map(function($t) use ($nomExp, $contenu) {
                return [
                    'to'    => $t,
                    'title' => "💬 " . $nomExp,
                    'body'  => mb_strlen($contenu) > 100 ? mb_substr($contenu, 0, 97) . '...' : $contenu,
                    'sound' => 'default',
                    'data'  => ['screen' => 'messagerie'],
                ];
            }, $tokens);

            $ch = curl_init('https://exp.host/--/api/v2/push/send');
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload_push));
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json', 'Accept: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Exception $e) { /* silencieux */ }

    exit;
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
