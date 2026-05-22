<?php
require_once __DIR__ . '/_config.php';

// GET  /ventes.php        -> liste des ventes
// GET  /ventes.php?id=N   -> detail + lignes produits
// POST /ventes.php        -> creer une nouvelle vente
// DELETE /ventes.php?id=N -> annuler une vente (restaure le stock)

// ----- GET liste -----
if (method() === 'GET' && !q('id')) {
    requireRole(['ADMIN', 'USER', 'SUPERADMIN']);
    $from = q('from');
    $to   = q('to');
    $sql = "
       SELECT v.id_vente, v.date_et_heure_de_la_vente, v.montant_total, v.mode_paiement,
              v.reference_ordonnance,
              c.Nom   AS client_nom,  c.Prenom AS client_prenom,
              p.Nom   AS user_nom,    p.Prenom AS user_prenom,
              m.Nom   AS medecin_nom, m.Prenom AS medecin_prenom
         FROM Vente v
         LEFT JOIN Client    c ON c.id_client    = v.id_client
         LEFT JOIN Personnel p ON p.id_personnel = v.id_personnel
         LEFT JOIN Medecin_  m ON m.id_medecin   = v.id_medecin
        WHERE 1=1
    ";
    $params = [];
    if ($from) { $sql .= " AND v.date_et_heure_de_la_vente >= :from"; $params[':from'] = $from; }
    if ($to)   { $sql .= " AND v.date_et_heure_de_la_vente <  :to";   $params[':to']   = $to;   }
    $sql .= " ORDER BY v.date_et_heure_de_la_vente DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['ventes' => $stmt->fetchAll()]);
}

// ----- GET detail d'une vente -----
if (method() === 'GET' && q('id')) {
    requireRole(['ADMIN', 'USER', 'SUPERADMIN']);
    $id = (int)q('id');
    $stmt = $pdo->prepare("
       SELECT v.id_vente, v.date_et_heure_de_la_vente, v.montant_total,
              v.mode_paiement, v.reference_ordonnance,
              c.Nom   AS client_nom,  c.Prenom AS client_prenom,
              p.Nom   AS user_nom,    p.Prenom AS user_prenom,
              m.Nom   AS medecin_nom, m.Prenom AS medecin_prenom
         FROM Vente v
         LEFT JOIN Client    c ON c.id_client    = v.id_client
         LEFT JOIN Personnel p ON p.id_personnel = v.id_personnel
         LEFT JOIN Medecin_  m ON m.id_medecin   = v.id_medecin
        WHERE v.id_vente = :id
    ");
    $stmt->execute([':id' => $id]);
    $v = $stmt->fetch();
    if (!$v) jsonResponse(['error' => 'Vente introuvable'], 404);
    $stmt = $pdo->prepare("
       SELECT co.id_produit, pr.nom_du_produit, pr.dci,
              co.Quantite_vendu AS qte,
              co.prix_unitaire  AS pu,
              (co.Quantite_vendu * co.prix_unitaire) AS total_ligne
         FROM Concerner_ co
         JOIN Produit pr ON pr.id_produit = co.id_produit
        WHERE co.id_vente = :id
    ");
    $stmt->execute([':id' => $id]);
    $v['lignes'] = $stmt->fetchAll();
    jsonResponse(['vente' => $v]);
}

// ----- POST nouvelle vente -----
if (method() === 'POST') {
    try {
    $payload = requireRole(['USER', 'ADMIN', 'SUPERADMIN']);
    $b = jsonBody();
    if (empty($b['lignes']) || !is_array($b['lignes'])) {
        jsonResponse(['error' => 'Lignes manquantes'], 400);
    }

    // Trouver id_personnel + id_pharmacie depuis le compte connecte
    $id_personnel = null;
    $id_pharmacie = 1;

    // Tentative 1 : Personnel a une colonne id_compte
    try {
        $stmt = $pdo->prepare("SELECT id_personnel, Id_pharmacie FROM Personnel WHERE id_compte = :id LIMIT 1");
        $stmt->execute([':id' => $payload['id']]);
        $row = $stmt->fetch();
        if ($row) {
            $id_personnel = (int)$row['id_personnel'];
            $id_pharmacie = (int)$row['Id_pharmacie'];
        }
    } catch (Exception $e) { /* colonne id_compte absente dans Personnel */ }

    // Tentative 2 : recuperer infos Admin (Nom, Prenom, Id_pharmacie)
    if (!$id_personnel) {
        try {
            $stmt = $pdo->prepare("SELECT Id_pharmacie, Nom, Prenom FROM Admin WHERE id_compte = :id LIMIT 1");
            $stmt->execute([':id' => $payload['id']]);
            $adm = $stmt->fetch();
            if ($adm) {
                $id_pharmacie = (int)$adm['Id_pharmacie'];
                $nomAdm    = $adm['Nom']    ?? null;
                $prenomAdm = $adm['Prenom'] ?? null;
                // chercher un Personnel avec ce nom dans cette pharmacie
                if ($nomAdm) {
                    $stmt2 = $pdo->prepare("SELECT id_personnel FROM Personnel WHERE Nom = :n AND Prenom = :p AND Id_pharmacie = :ph LIMIT 1");
                    $stmt2->execute([':n' => $nomAdm, ':p' => $prenomAdm, ':ph' => $id_pharmacie]);
                    $pid = $stmt2->fetchColumn();
                    if ($pid) {
                        $id_personnel = (int)$pid;
                    } else {
                        // creer un Personnel pour cet admin s'il n'existe pas encore
                        $stmt3 = $pdo->prepare("INSERT INTO Personnel(Nom, Prenom, Id_pharmacie) VALUES(:n, :p, :ph)");
                        $stmt3->execute([':n' => $nomAdm, ':p' => $prenomAdm, ':ph' => $id_pharmacie]);
                        $id_personnel = (int)$pdo->lastInsertId();
                    }
                }
            }
        } catch (Exception $e) { /* Admin sans Nom/Prenom ou inaccessible */ }
    }

    // Tentative 3 : prendre le premier Personnel de la pharmacie
    if (!$id_personnel) {
        try {
            $stmt = $pdo->prepare("SELECT id_personnel FROM Personnel WHERE Id_pharmacie = :ph LIMIT 1");
            $stmt->execute([':ph' => $id_pharmacie]);
            $pid = $stmt->fetchColumn();
            if ($pid) $id_personnel = (int)$pid;
        } catch (Exception $e) { /* table Personnel inaccessible */ }
    }

    // Si toujours rien, erreur claire
    if (!$id_personnel) {
        jsonResponse(['error' => 'Impossible de determiner le personnel pour ce compte (id_compte: ' . $payload['id'] . ')'], 403);
    }

    // client par nom/prenom — null si champ vide
    $id_client = null;
    $nomCli    = trim($b['client_nom']    ?? '');
    $prenomCli = trim($b['client_prenom'] ?? '');
    if ($nomCli !== '' || $prenomCli !== '') {
        $stmt = $pdo->prepare("SELECT id_client FROM Client WHERE Nom = :n AND Prenom = :p LIMIT 1");
        $stmt->execute([':n' => $nomCli, ':p' => $prenomCli]);
        $id_client = $stmt->fetchColumn();
        if (!$id_client) {
            $stmt = $pdo->prepare("INSERT INTO Client(Nom, Prenom) VALUES(:n, :p)");
            $stmt->execute([':n' => $nomCli, ':p' => $prenomCli]);
            $id_client = (int)$pdo->lastInsertId();
        }
    }

    $pdo->beginTransaction();
    try {
        $ids  = array_map('intval', array_column($b['lignes'], 'id_produit'));
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id_produit, nom_du_produit, prix_de_vente, Quantite_disponible FROM Produit WHERE id_produit IN ($in)");
        $stmt->execute($ids);
        $cat = [];
        foreach ($stmt->fetchAll() as $r) $cat[$r['id_produit']] = $r;

        $total = 0;
        foreach ($b['lignes'] as $l) {
            $pid = (int)$l['id_produit'];
            $qte = (int)$l['qte'];
            if ($qte <= 0) continue;
            if (!isset($cat[$pid])) throw new Exception("Produit $pid inconnu");
            if ($cat[$pid]['Quantite_disponible'] < $qte)
                throw new Exception("Stock insuffisant pour {$cat[$pid]['nom_du_produit']} (dispo: {$cat[$pid]['Quantite_disponible']}, demande: $qte)");
            $pu     = isset($l['pu']) && (float)$l['pu'] > 0 ? (float)$l['pu'] : (float)$cat[$pid]['prix_de_vente'];
            $total += $qte * $pu;
        }

        $stmt = $pdo->prepare("INSERT INTO Vente(id_personnel, id_client, montant_total, mode_paiement, reference_ordonnance) VALUES(:per,:cli,:mt,:mp,:ord)");
        $stmt->execute([':per' => $id_personnel, ':cli' => $id_client, ':mt' => round($total,2), ':mp' => $b['mode_paiement'] ?? 'Especes', ':ord' => $b['reference_ordonnance'] ?? null]);
        $id_vente = (int)$pdo->lastInsertId();

        $stmtL = $pdo->prepare("INSERT INTO Concerner_(id_produit, id_vente, Quantite_vendu, prix_unitaire) VALUES(?,?,?,?)");
        $stmtS = $pdo->prepare("UPDATE Produit SET Quantite_disponible = Quantite_disponible - ? WHERE id_produit = ?");
        foreach ($b['lignes'] as $l) {
            $pid = (int)$l['id_produit']; $qte = (int)$l['qte'];
            if ($qte <= 0) continue;
            $pu = isset($l['pu']) && (float)$l['pu'] > 0 ? (float)$l['pu'] : (float)$cat[$pid]['prix_de_vente'];
            $stmtL->execute([$pid, $id_vente, $qte, $pu]);
            $stmtS->execute([$qte, $pid]);
        }

        $pdo->prepare("INSERT INTO Realiser(Id_pharmacie, id_vente) VALUES(?,?)")->execute([$id_pharmacie, $id_vente]);
        $pdo->commit();
        jsonResponse(['id_vente' => $id_vente, 'montant_total' => round($total,2)], 201);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }

    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

// ----- DELETE annuler une vente -----
if (method() === 'DELETE' && q('id')) {
    requireRole(['ADMIN', 'SUPERADMIN']);
    $id = (int)q('id');
    $stmt = $pdo->prepare("SELECT id_vente FROM Vente WHERE id_vente = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) jsonResponse(['error' => 'Vente introuvable'], 404);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id_produit, Quantite_vendu FROM Concerner_ WHERE id_vente = :id");
        $stmt->execute([':id' => $id]);
        $stmtR = $pdo->prepare("UPDATE Produit SET Quantite_disponible = Quantite_disponible + ? WHERE id_produit = ?");
        foreach ($stmt->fetchAll() as $l) $stmtR->execute([$l['Quantite_vendu'], $l['id_produit']]);
        $pdo->prepare("DELETE FROM Concerner_ WHERE id_vente = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM Realiser   WHERE id_vente = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM Vente      WHERE id_vente = ?")->execute([$id]);
        $pdo->commit();
        jsonResponse(['success' => true, 'id_vente' => $id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Methode non supportee'], 405);
