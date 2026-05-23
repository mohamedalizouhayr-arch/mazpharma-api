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

    // Récupérer le nom de l'expéditeur
    $stmtNom = $pdo->prepare("
        SELECT COALESCE(p.Prenom, a.prenom, c.Nom_d_utilisateur) AS prenom,
               COALESCE(p.Nom, a.nom, '') AS nom
          FROM Compte c
          LEFT JOIN Personnel p ON p.id_compte = c.id_compte
          LEFT JOIN Admin     a ON a.id_compte = c.id_compte
         WHERE c.id_compte = :id LIMIT 1
    ");
    $stmtNom->execute([':id' => $payload['id']]);
    $exp  = $stmtNom->fetch();
    $nomExp = trim(($exp['prenom'] ?? '') . ' ' . ($exp['nom'] ?? '')) ?: 'Quelqu\'un';

    // Envoyer push notification à tous les autres users de la pharmacie
    $stmtTok = $pdo->prepare("
        SELECT pt.token
          FROM Push_Token pt
          JOIN Compte c ON c.id_compte = pt.id_compte
         WHERE c.id_compte IN (
             SELECT id_compte FROM Personnel WHERE Id_pharmacie = :ph
             UNION
             SELECT id_compte FROM Admin WHERE Id_pharmacie = :ph
         )
           AND pt.id_compte <> :moi
    ");
    $stmtTok->execute([':ph' => $id_pharmacie, ':moi' => $payload['id']]);
    $tokens = array_column($stmtTok->fetchAll(), 'token');

    if (!empty($tokens)) {
        $messages = array_map(fn($t) => [
            'to'    => $t,
            'title' => "💬 $nomExp",
            'body'  => mb_strlen($contenu) > 100 ? mb_substr($contenu, 0, 97) . '...' : $contenu,
            'sound' => 'default',
            'data'  => ['screen' => 'messagerie'],
        ], $tokens);

        @file_get_contents('https://exp.host/--/api/v2/push/send', false,
            stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($messages),
                'timeout' => 3,
            ]])
        );
    }

    jsonResponse(['id_message' => $id_message], 201);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
