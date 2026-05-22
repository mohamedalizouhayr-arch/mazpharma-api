<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste du personnel -----
// ADMIN uniquement : voit le personnel de sa pharmacie
if (method() === 'GET') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);

    if ($payload['role'] === 'SUPERADMIN') {
        // le superadmin voit tout le personnel de toutes les pharmacies
        $stmt = $pdo->query("
            SELECT p.id_personnel, p.Nom, p.Prenom, p.Numero_de_telephone,
                   p.Fonction, p.Date_de_prise_du_poste, p.Sexe,
                   ph.Nom AS pharmacie
              FROM Personnel p
              JOIN Pharmacie ph ON ph.Id_pharmacie = p.Id_pharmacie
             ORDER BY p.Nom, p.Prenom
        ");
        jsonResponse(['personnel' => $stmt->fetchAll()]);
    }

    // ADMIN : uniquement le personnel de sa pharmacie
    $stmt = $pdo->prepare("
        SELECT p.id_personnel, p.Nom, p.Prenom, p.Numero_de_telephone,
               p.Fonction, p.Date_de_prise_du_poste, p.Sexe,
               ph.Nom AS pharmacie
          FROM Personnel p
          JOIN Admin adm     ON adm.Id_pharmacie  = p.Id_pharmacie
          JOIN Pharmacie ph  ON ph.Id_pharmacie   = p.Id_pharmacie
         WHERE adm.id_compte = :id
         ORDER BY p.Nom, p.Prenom
    ");
    $stmt->execute([':id' => $payload['id']]);
    jsonResponse(['personnel' => $stmt->fetchAll()]);
}

// ----- POST : créer un membre du personnel + son compte -----
if (method() === 'POST') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);
    $raw = file_get_contents('php://input');
    $b   = (is_array(json_decode($raw, true))) ? json_decode($raw, true) : [];

    $nom      = trim($b['nom']      ?? '');
    $prenom   = trim($b['prenom']   ?? '');
    $username = trim($b['username'] ?? '');
    $password = trim($b['password'] ?? '');

    if (!$nom || !$prenom)         jsonResponse(['error' => 'Nom et prénom requis'], 400);
    if (!$username || !$password)  jsonResponse(['error' => 'Identifiants de connexion requis'], 400);

    // pharmacie de l'admin
    $s = $pdo->prepare("SELECT Id_pharmacie FROM Admin WHERE id_compte = :id LIMIT 1");
    $s->execute([':id' => $payload['id']]);
    $id_pharmacie = (int)$s->fetchColumn();
    if (!$id_pharmacie) jsonResponse(['error' => 'Pharmacie introuvable'], 400);

    // unicité du nom d'utilisateur
    $chk = $pdo->prepare("SELECT id_compte FROM Compte WHERE Nom_d_utilisateur = :u LIMIT 1");
    $chk->execute([':u' => $username]);
    if ($chk->fetchColumn()) jsonResponse(['error' => "Nom d'utilisateur déjà utilisé"], 409);

    $pdo->beginTransaction();
    try {
        // créer le compte
        $pdo->prepare("
            INSERT INTO Compte(Nom_d_utilisateur, Mots_de_passe, Role, actif)
            VALUES(:u, :p, 'USER', 1)
        ")->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $id_compte = (int)$pdo->lastInsertId();

        // créer le personnel
        $pdo->prepare("
            INSERT INTO Personnel(Nom, Prenom, Numero_de_telephone, Fonction,
                                  Date_de_prise_du_poste, Sexe, Id_pharmacie, id_compte)
            VALUES(:nom, :prenom, :tel, :fonction, :date, :sexe, :ph, :ic)
        ")->execute([
            ':nom'     => $nom,
            ':prenom'  => $prenom,
            ':tel'     => $b['telephone']  ?? null,
            ':fonction'=> $b['fonction']   ?? null,
            ':date'    => $b['date_poste'] ?? date('Y-m-d'),
            ':sexe'    => $b['sexe']       ?? null,
            ':ph'      => $id_pharmacie,
            ':ic'      => $id_compte,
        ]);
        $id_personnel = (int)$pdo->lastInsertId();

        $pdo->commit();
        jsonResponse(['id_personnel' => $id_personnel, 'id_compte' => $id_compte], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

// ----- DELETE : supprimer un membre (désactive aussi son compte) -----
if (method() === 'DELETE') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);
    $id = (int)q('id');
    if (!$id) jsonResponse(['error' => 'ID requis'], 400);

    $stmt = $pdo->prepare("SELECT id_personnel, id_compte FROM Personnel WHERE id_personnel = :id");
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) jsonResponse(['error' => 'Personnel introuvable'], 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM Personnel WHERE id_personnel = :id")
            ->execute([':id' => $id]);

        // désactiver le compte (ne pas supprimer pour conserver l'historique)
        if ($p['id_compte']) {
            $pdo->prepare("UPDATE Compte SET actif = 0 WHERE id_compte = :id AND Role = 'USER'")
                ->execute([':id' => $p['id_compte']]);
        }

        $pdo->commit();
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
