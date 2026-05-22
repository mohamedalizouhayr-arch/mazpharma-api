<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste des pharmacies -----
if (method() === 'GET') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);

    if ($payload['role'] === 'SUPERADMIN') {
        $stmt = $pdo->query("
            SELECT p.Id_pharmacie, p.Nom, p.Numero_de_telephone,
                   p.nom_du_proprietaire, p.active,
                   a.Ville, a.Numero_de_voie, a.Nom_de_la_voie,
                   a.Type_de_voie, a.Code_postale
              FROM Pharmacie p
              LEFT JOIN Adresse a ON a.id_adresse = p.id_adresse
             ORDER BY p.Nom
        ");
        jsonResponse(['pharmacies' => $stmt->fetchAll()]);
    }

    // ADMIN : uniquement sa pharmacie
    $stmt = $pdo->prepare("
        SELECT ph.Id_pharmacie, ph.Nom, ph.Numero_de_telephone,
               ph.nom_du_proprietaire, ph.active,
               a.Ville, a.Numero_de_voie, a.Nom_de_la_voie,
               a.Type_de_voie, a.Code_postale
          FROM Admin adm
          JOIN Pharmacie ph ON ph.Id_pharmacie = adm.Id_pharmacie
          LEFT JOIN Adresse a ON a.id_adresse   = ph.id_adresse
         WHERE adm.id_compte = :id
    ");
    $stmt->execute([':id' => $payload['id']]);
    $pharmacie = $stmt->fetch();
    jsonResponse(['pharmacies' => $pharmacie ? [$pharmacie] : []]);
}

// ----- POST création pharmacie (SUPERADMIN uniquement) -----
if (method() === 'POST') {
    requireRole(['SUPERADMIN']);
    $b = jsonBody();

    $nom = trim($b['nom'] ?? '');
    if (!$nom) jsonResponse(['error' => 'Le nom de la pharmacie est requis'], 400);

    // Validation du compte administrateur
    $admin_login    = trim($b['admin_login']    ?? '');
    $admin_password = trim($b['admin_password'] ?? '');
    $admin_prenom   = trim($b['admin_prenom']   ?? '');
    $admin_nom_val  = trim($b['admin_nom']      ?? '');
    $admin_email    = trim($b['admin_email']    ?? '');

    if (!$admin_login)    jsonResponse(['error' => 'Le login de l\'administrateur est requis'], 400);
    if (!$admin_password) jsonResponse(['error' => 'Le mot de passe de l\'administrateur est requis'], 400);
    if (strlen($admin_password) < 6) jsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caractères'], 400);

    // Vérifier que le login n'est pas déjà pris
    $check = $pdo->prepare("SELECT id_compte FROM Compte WHERE Nom_d_utilisateur = ?");
    $check->execute([$admin_login]);
    if ($check->fetch()) jsonResponse(['error' => 'Ce login est déjà utilisé'], 409);

    $pdo->beginTransaction();
    try {
        // 1. Calcul du prochain id_adresse (pas d'AUTO_INCREMENT sur cette colonne)
        $ville = trim($b['ville'] ?? '');
        $stmt_max = $pdo->prepare("SELECT COALESCE(MAX(id_adresse), 0) + 1 AS next_id FROM Adresse");
        $stmt_max->execute();
        $row = $stmt_max->fetch();
        $id_adresse = isset($row['next_id']) ? (int)$row['next_id'] : 1;

        // 2. Créer l'adresse
        $pdo->prepare("
            INSERT INTO Adresse(id_adresse, Numero_de_voie, Type_de_voie, Nom_de_la_voie, Ville, Code_postale)
            VALUES(:id, :nv, :tv, :nlv, :vi, :cp)
        ")->execute([
            ':id'  => $id_adresse,
            ':nv'  => trim($b['numero_voie'] ?? '') ?: null,
            ':tv'  => trim($b['type_voie']   ?? '') ?: null,
            ':nlv' => trim($b['nom_voie']    ?? '') ?: null,
            ':vi'  => $ville ?: null,
            ':cp'  => trim($b['code_postal'] ?? '') ?: null,
        ]);

        // 3. Créer la pharmacie
        $pdo->prepare("
            INSERT INTO Pharmacie(Nom, Numero_de_telephone, nom_du_proprietaire, id_adresse, active)
            VALUES(:nom, :tel, :prop, :adr, 1)
        ")->execute([
            ':nom'  => $nom,
            ':tel'  => trim($b['telephone']   ?? '') ?: null,
            ':prop' => trim($b['proprietaire'] ?? '') ?: null,
            ':adr'  => $id_adresse,
        ]);
        $id_pharmacie = (int)$pdo->lastInsertId();

        // 4. Créer le compte ADMIN
        $hash = password_hash($admin_password, PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO Compte(Nom_d_utilisateur, Mots_de_passe, Role, email, actif)
            VALUES(:login, :pwd, 'ADMIN', :email, 1)
        ")->execute([
            ':login' => $admin_login,
            ':pwd'   => $hash,
            ':email' => $admin_email ?: null,
        ]);
        $id_compte = (int)$pdo->lastInsertId();

        // 5. Créer le profil Admin (réutilise le même id_adresse que la pharmacie)
        $pdo->prepare("
            INSERT INTO Admin(id_compte, Id_pharmacie, id_adresse, prenom, nom)
            VALUES(:id_compte, :id_pharmacie, :id_adresse, :prenom, :nom)
        ")->execute([
            ':id_compte'    => $id_compte,
            ':id_pharmacie' => $id_pharmacie,
            ':id_adresse'   => $id_adresse,
            ':prenom'       => $admin_prenom ?: null,
            ':nom'          => $admin_nom_val ?: null,
        ]);

        $pdo->commit();
        jsonResponse([
            'id_pharmacie' => $id_pharmacie,
            'nom'          => $nom,
            'admin_login'  => $admin_login,
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

// ----- DELETE suppression pharmacie (SUPERADMIN uniquement) -----
if (method() === 'DELETE') {
    requireRole(['SUPERADMIN']);
    $id = (int)q('id');
    if (!$id) jsonResponse(['error' => 'id requis'], 400);

    // Vérifier que la pharmacie existe
    $stmt = $pdo->prepare("SELECT Id_pharmacie FROM Pharmacie WHERE Id_pharmacie = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Pharmacie introuvable'], 404);

    // Vérifier s'il y a des dépendances (comptes, commandes, etc.)
    try {
        $checks = [
            "SELECT COUNT(*) FROM Admin     WHERE Id_pharmacie = ?" => "des administrateurs",
            "SELECT COUNT(*) FROM Personnel WHERE Id_pharmacie = ?" => "du personnel",
            "SELECT COUNT(*) FROM Commande  WHERE Id_pharmacie = ?" => "des commandes",
            // Vente est liée à la pharmacie via le personnel (pas de Id_pharmacie direct)
            "SELECT COUNT(*) FROM Vente v JOIN Personnel p ON p.id_personnel = v.id_personnel WHERE p.Id_pharmacie = ?" => "des ventes",
        ];
        foreach ($checks as $sql => $label) {
            $s = $pdo->prepare($sql);
            $s->execute([$id]);
            if ((int)$s->fetchColumn() > 0) {
                jsonResponse(['error' => "Impossible de supprimer : cette pharmacie a $label associés."], 409);
            }
        }
    } catch (Exception $e) {
        jsonResponse(['error' => 'Erreur lors de la vérification des dépendances : ' . $e->getMessage()], 500);
    }

    try {
        $pdo->prepare("DELETE FROM Pharmacie WHERE Id_pharmacie = ?")->execute([$id]);
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Erreur lors de la suppression : ' . $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
