<?php
require_once __DIR__ . '/_config.php';

// Helper : retrouver la pharmacie du compte connecté
function getPharmacieId(PDO $pdo, array $payload): int {
    $id = $payload['id'];
    if ($payload['role'] === 'ADMIN') {
        $s = $pdo->prepare("SELECT Id_pharmacie FROM Admin WHERE id_compte = :id LIMIT 1");
    } elseif ($payload['role'] === 'USER') {
        $s = $pdo->prepare("SELECT Id_pharmacie FROM Personnel WHERE id_compte = :id LIMIT 1");
    } else {
        jsonResponse(['error' => 'Messagerie non disponible pour ce rôle'], 403);
    }
    $s->execute([':id' => $id]);
    $ph = (int)$s->fetchColumn();
    if (!$ph) jsonResponse(['error' => 'Pharmacie introuvable'], 400);
    return $ph;
}

// ----- GET : liste des messages -----
if (method() === 'GET') {
    $payload      = requireRole(['ADMIN', 'USER']);
    $id_pharmacie = getPharmacieId($pdo, $payload);

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

    jsonResponse(['id_message' => (int)$pdo->lastInsertId()], 201);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
