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

    $pdo->beginTransaction();
    try {
        // Créer l'adresse si une ville est fournie
        $id_adresse = null;
        $ville      = trim($b['ville'] ?? '');
        if ($ville) {
            $pdo->prepare("
                INSERT INTO Adresse(Numero_de_voie, Type_de_voie, Nom_de_la_voie, Ville, Code_postale)
                VALUES(:nv, :tv, :nlv, :vi, :cp)
            ")->execute([
                ':nv'  => $b['numero_voie']  ?? null,
                ':tv'  => $b['type_voie']    ?? null,
                ':nlv' => $b['nom_voie']     ?? null,
                ':vi'  => $ville,
                ':cp'  => $b['code_postal']  ?? null,
            ]);
            $id_adresse = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            INSERT INTO Pharmacie(Nom, Numero_de_telephone, nom_du_proprietaire, id_adresse, active)
            VALUES(:nom, :tel, :prop, :adr, 1)
        ")->execute([
            ':nom'  => $nom,
            ':tel'  => trim($b['telephone']   ?? '') ?: null,
            ':prop' => trim($b['proprietaire'] ?? '') ?: null,
            ':adr'  => $id_adresse,
        ]);
        $id = (int)$pdo->lastInsertId();

        $pdo->commit();
        jsonResponse(['id_pharmacie' => $id, 'nom' => $nom], 201);
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
    $checks = [
        "SELECT COUNT(*) FROM Admin     WHERE Id_pharmacie = ?" => "des administrateurs",
        "SELECT COUNT(*) FROM Personnel WHERE Id_pharmacie = ?" => "du personnel",
        "SELECT COUNT(*) FROM Commande  WHERE Id_pharmacie = ?" => "des commandes",
        "SELECT COUNT(*) FROM Vente     WHERE Id_pharmacie = ?" => "des ventes",
    ];
    foreach ($checks as $sql => $label) {
        $s = $pdo->prepare($sql);
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            jsonResponse(['error' => "Impossible de supprimer : cette pharmacie a $label associés."], 409);
        }
    }

    $pdo->prepare("DELETE FROM Pharmacie WHERE Id_pharmacie = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
