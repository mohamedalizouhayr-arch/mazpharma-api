<?php
require_once __DIR__ . '/_config.php';

$id = q('id');

// ----- GET catalogue (tous les produits comme référence) -----
if (method() === 'GET' && !$id && q('action') === 'catalogue') {
    requireRole(['ADMIN', 'SUPERADMIN']);
    $search = q('search');
    $sql = "SELECT id_produit, nom_du_produit, dci, forme_pharma, dosage,
                   princeps_generique, code_cip, code_barre,
                   prix_de_vente, prix_d_achat
            FROM Produit WHERE actif = 1";
    $params = [];
    if ($search) {
        $sql .= " AND (nom_du_produit LIKE :s OR dci LIKE :s)";
        $params[':s'] = "%{$search}%";
    }
    $sql .= " ORDER BY nom_du_produit LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['catalogue' => $stmt->fetchAll()]);
}

// ----- GET liste -----
if (method() === 'GET' && !$id) {
    $payload     = requireRole(['ADMIN', 'USER', 'SUPERADMIN']);
    $id_pharmacie = getPharmacieId($pdo, $payload);

    $search = q('search');
    $alerte = q('alerte');

    $sql = "
       SELECT p.id_produit, p.nom_du_produit, p.dci, p.forme_pharma, p.dosage,
              p.princeps_generique, p.code_cip, p.code_barre,
              p.Quantite_disponible AS stock, p.stock_max,
              COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100)) AS seuil_min,
              p.prix_de_vente AS ppv, p.prix_d_achat AS pa,
              p.vente_moyenne_jour, p.actif,
              CASE
                WHEN p.Quantite_disponible = 0 THEN 'RUPTURE'
                WHEN p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100)) THEN 'SOUS_SEUIL'
                ELSE 'OK'
              END AS statut_stock
         FROM Produit p
         LEFT JOIN Proposer pr  ON pr.id_produit   = p.id_produit
         LEFT JOIN Pharmacie ph ON ph.Id_pharmacie = pr.Id_pharmacie
        WHERE 1=1
    ";
    $params = [];

    // Filtre par pharmacie (ADMIN/USER uniquement — SUPERADMIN voit tout)
    if ($id_pharmacie !== null) {
        $sql .= " AND pr.Id_pharmacie = :id_ph";
        $params[':id_ph'] = $id_pharmacie;
    }
    if ($search) {
        $sql .= " AND (p.nom_du_produit LIKE :s OR p.dci LIKE :s OR p.code_barre = :exact)";
        $params[':s']     = "%{$search}%";
        $params[':exact'] = $search;
    }
    if ($alerte === '1') {
        $sql .= " AND p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100))";
    }
    $sql .= " ORDER BY p.nom_du_produit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['produits' => $stmt->fetchAll()]);
}

// ----- GET un produit -----
if (method() === 'GET' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM Produit WHERE id_produit = :id");
    $stmt->execute([':id' => $id]);
    $prod = $stmt->fetch();
    if (!$prod) jsonResponse(['error' => 'Produit introuvable'], 404);
    jsonResponse(['produit' => $prod]);
}

// ----- POST création produit (ADMIN/SUPERADMIN) -----
if (method() === 'POST') {
    $payload      = requireRole(['ADMIN','SUPERADMIN']);
    $id_pharmacie = getPharmacieId($pdo, $payload);
    $b = jsonBody();

    // Récupérer un id_stock valide (Stock est partagé, indépendant de la pharmacie)
    $sRow = $pdo->query("SELECT id_stock FROM Stock LIMIT 1");
    $sData = $sRow->fetch();
    $id_stock = $sData ? (int)$sData['id_stock'] : 1;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO Produit(nom_du_produit, prix_de_vente, prix_d_achat, dci, forme_pharma,
                      dosage, princeps_generique, stock_max, seuil_min_manuel, Quantite_disponible, id_stock)
            VALUES(:nom, :pv, :pa, :dci, :forme, :dose, :pg, :smax, :smin, :stock, :id_stock)
        ");
        $stmt->execute([
            ':nom'      => $b['nom_du_produit'] ?? '',
            ':pv'       => $b['prix_de_vente']  ?? 0,
            ':pa'       => $b['prix_d_achat']   ?? 0,
            ':dci'      => $b['dci']            ?? null,
            ':forme'    => $b['forme_pharma']   ?? null,
            ':dose'     => $b['dosage']         ?? null,
            ':pg'       => $b['princeps_generique'] ?? null,
            ':smax'     => $b['stock_max']      ?? 100,
            ':smin'     => $b['seuil_min_manuel'] ?? null,
            ':stock'    => $b['stock_initial']  ?? 0,
            ':id_stock' => $id_stock,
        ]);
        $id_produit = (int)$pdo->lastInsertId();

        // Lier le produit à la pharmacie via la table Proposer
        if ($id_pharmacie) {
            $pdo->prepare("INSERT IGNORE INTO Proposer(Id_pharmacie, id_produit) VALUES(?, ?)")
                ->execute([$id_pharmacie, $id_produit]);
        }

        $pdo->commit();
        jsonResponse(['id' => $id_produit], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

// ----- PUT seuil global : applique seuil_min_manuel aux produits de la pharmacie -----
if (method() === 'PUT' && !$id && q('action') === 'seuil_global') {
    $payload      = requireRole(['ADMIN', 'SUPERADMIN']);
    $id_pharmacie = getPharmacieId($pdo, $payload);
    $b     = jsonBody();
    $seuil = (int)($b['seuil'] ?? 0);
    if ($seuil <= 0) jsonResponse(['error' => 'Seuil invalide'], 400);

    if ($id_pharmacie !== null) {
        // ADMIN : seulement les produits de sa pharmacie
        // On remet seuil_alerte_manuel à NULL pour que le seuil global reprenne le dessus
        $stmt = $pdo->prepare("
            UPDATE Produit
            SET seuil_min_manuel = :s, seuil_alerte_manuel = NULL
            WHERE actif = 1
            AND id_produit IN (SELECT id_produit FROM Proposer WHERE Id_pharmacie = :ph)
        ");
        $stmt->execute([':s' => $seuil, ':ph' => $id_pharmacie]);
    } else {
        // SUPERADMIN : tous les produits
        $stmt = $pdo->prepare("UPDATE Produit SET seuil_min_manuel = :s, seuil_alerte_manuel = NULL WHERE actif = 1");
        $stmt->execute([':s' => $seuil]);
    }
    jsonResponse(['updated' => $stmt->rowCount()]);
}

// ----- PUT MAJ stock ou seuil -----
if (method() === 'PUT' && $id) {
    requireRole(['ADMIN','SUPERADMIN','USER']);
    $b = jsonBody();
    $fields = [];
    $params = [':id' => $id];
    foreach (['Quantite_disponible','stock_max','seuil_min_manuel','seuil_alerte_manuel','prix_de_vente','prix_d_achat','actif'] as $f) {
        if (array_key_exists($f, $b)) {
            $fields[] = "$f = :$f";
            $params[":$f"] = $b[$f];
        }
    }
    if (!$fields) jsonResponse(['error' => 'Aucun champ à modifier'], 400);
    $stmt = $pdo->prepare("UPDATE Produit SET " . implode(', ', $fields) . " WHERE id_produit = :id");
    $stmt->execute($params);
    jsonResponse(['updated' => $stmt->rowCount()]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
