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
        $ville = trim($b['ville'] ?? '');

        // 1. Créer l'adresse de la pharmacie (AUTO_INCREMENT)
        $pdo->prepare("
            INSERT INTO Adresse(Numero_de_voie, Type_de_voie, Nom_de_la_voie, Ville, Code_postale)
            VALUES(:nv, :tv, :nlv, :vi, :cp)
        ")->execute([
            ':nv'  => trim($b['numero_voie'] ?? '') ?: null,
            ':tv'  => trim($b['type_voie']   ?? '') ?: null,
            ':nlv' => trim($b['nom_voie']    ?? '') ?: null,
            ':vi'  => $ville ?: null,
            ':cp'  => trim($b['code_postal'] ?? '') ?: null,
        ]);
        $id_adresse_pharmacie = (int)$pdo->lastInsertId();

        // 2. Créer la pharmacie
        $pdo->prepare("
            INSERT INTO Pharmacie(Nom, Numero_de_telephone, nom_du_proprietaire, id_adresse, active)
            VALUES(:nom, :tel, :prop, :adr, 1)
        ")->execute([
            ':nom'  => $nom,
            ':tel'  => trim($b['telephone']   ?? '') ?: null,
            ':prop' => trim($b['proprietaire'] ?? '') ?: null,
            ':adr'  => $id_adresse_pharmacie,
        ]);
        $id_pharmacie = (int)$pdo->lastInsertId();

        // 3. Créer une adresse séparée pour l'Admin (id_adresse est UNIQUE dans Admin)
        $pdo->prepare("
            INSERT INTO Adresse(Ville) VALUES(:vi)
        ")->execute([':vi' => $ville ?: null]);
        $id_adresse_admin = (int)$pdo->lastInsertId();

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

        // 5. Créer le profil Admin avec son propre id_adresse
        $pdo->prepare("
            INSERT INTO Admin(id_compte, Id_pharmacie, id_adresse, prenom, nom)
            VALUES(:id_compte, :id_pharmacie, :id_adresse, :prenom, :nom)
        ")->execute([
            ':id_compte'    => $id_compte,
            ':id_pharmacie' => $id_pharmacie,
            ':id_adresse'   => $id_adresse_admin,
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

// ----- DELETE suppression pharmacie (SUPERADMIN uniquement) — cascade complète -----
if (method() === 'DELETE') {
    requireRole(['SUPERADMIN']);
    $id = (int)q('id');
    if (!$id) jsonResponse(['error' => 'id requis'], 400);

    // Récupérer la pharmacie + son id_adresse
    $stmt = $pdo->prepare("SELECT Id_pharmacie, id_adresse FROM Pharmacie WHERE Id_pharmacie = ?");
    $stmt->execute([$id]);
    $pharmacie = $stmt->fetch();
    if (!$pharmacie) jsonResponse(['error' => 'Pharmacie introuvable'], 404);
    $id_adresse = (int)($pharmacie['id_adresse'] ?? 0);

    $pdo->beginTransaction();
    try {
        // ── 1. Récupérer les IDs utiles ──────────────────────────────
        $adminComptes = $pdo->prepare("SELECT id_compte, id_adresse FROM Admin WHERE Id_pharmacie = ?");
        $adminComptes->execute([$id]);
        $adminRows      = $adminComptes->fetchAll();
        $adminCompteIds = array_column($adminRows, 'id_compte');
        $adminAdresseIds = array_column($adminRows, 'id_adresse');

        $persoStmt = $pdo->prepare("SELECT id_compte, id_personnel FROM Personnel WHERE Id_pharmacie = ?");
        $persoStmt->execute([$id]);
        $persoRows      = $persoStmt->fetchAll();
        $persoCompteIds = array_column($persoRows, 'id_compte');
        $persoIds       = array_column($persoRows, 'id_personnel');

        $cmdStmt = $pdo->prepare("SELECT id_commande FROM Commande WHERE Id_pharmacie = ?");
        $cmdStmt->execute([$id]);
        $commandeIds = $cmdStmt->fetchAll(PDO::FETCH_COLUMN);

        // ── 2. Supprimer commandes et BL ─────────────────────────────
        if (!empty($commandeIds)) {
            $inCmd = implode(',', array_fill(0, count($commandeIds), '?'));

            // Lignes BL (ON DELETE CASCADE de Bon_de_livraison, mais on supprime explicitement)
            $blStmt = $pdo->prepare("SELECT id_bl FROM Bon_de_livraison WHERE id_commande IN ($inCmd)");
            $blStmt->execute($commandeIds);
            $blIds = $blStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($blIds)) {
                $inBl = implode(',', array_fill(0, count($blIds), '?'));
                $pdo->prepare("DELETE FROM Ligne_BL WHERE id_bl IN ($inBl)")->execute($blIds);
            }

            $pdo->prepare("DELETE FROM Bon_de_livraison WHERE id_commande IN ($inCmd)")->execute($commandeIds);
            // Contenir (lignes commande) a ON DELETE CASCADE depuis Commande — suppression auto
            $pdo->prepare("DELETE FROM Commande WHERE Id_pharmacie = ?")->execute([$id]);
        }

        // ── 3. Supprimer ventes de cette pharmacie via Realiser ───────
        $venteStmt = $pdo->prepare("SELECT id_vente FROM Realiser WHERE Id_pharmacie = ?");
        $venteStmt->execute([$id]);
        $venteIds = $venteStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($venteIds)) {
            $inVente = implode(',', array_fill(0, count($venteIds), '?'));
            $pdo->prepare("DELETE FROM Concerner_ WHERE id_vente IN ($inVente)")->execute($venteIds);
            $pdo->prepare("DELETE FROM Realiser   WHERE id_vente IN ($inVente)")->execute($venteIds);
            $pdo->prepare("DELETE FROM Vente      WHERE id_vente IN ($inVente)")->execute($venteIds);
        }

        // ── 3b. Supprimer les produits liés via Proposer ──────────────
        $pdo->prepare("DELETE FROM Proposer WHERE Id_pharmacie = ?")->execute([$id]);

        // ── 4. Supprimer Personnel et Admin ──────────────────────────
        $pdo->prepare("DELETE FROM Personnel WHERE Id_pharmacie = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM Admin     WHERE Id_pharmacie = ?")->execute([$id]);

        // ── 5. Supprimer les comptes associés ────────────────────────
        $tousComptes = array_values(array_filter(array_merge($adminCompteIds, $persoCompteIds)));
        if (!empty($tousComptes)) {
            $inC = implode(',', array_fill(0, count($tousComptes), '?'));
            $pdo->prepare("DELETE FROM Compte WHERE id_compte IN ($inC)")->execute($tousComptes);
        }

        // ── 6. Supprimer la pharmacie ────────────────────────────────
        $pdo->prepare("DELETE FROM Pharmacie WHERE Id_pharmacie = ?")->execute([$id]);

        // ── 7. Supprimer les adresses (pharmacie + admins) ──────────
        $adressesToDelete = array_values(array_filter(array_unique(
            array_merge([$id_adresse], $adminAdresseIds)
        )));
        if (!empty($adressesToDelete)) {
            $inAdr = implode(',', array_fill(0, count($adressesToDelete), '?'));
            $pdo->prepare("DELETE FROM Adresse WHERE id_adresse IN ($inAdr)")->execute($adressesToDelete);
        }

        $pdo->commit();
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
