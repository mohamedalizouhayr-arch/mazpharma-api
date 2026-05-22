<?php
require_once __DIR__ . '/_config.php';

$id     = q('id');
$action = q('action');

// ----- GET liste -----
if (method() === 'GET' && !$id) {
    $statut = q('statut');
    $four   = q('fournisseur');
    $sql = "
       SELECT c.id_commande, c.reference_bc, c.Date_et_heure_de_la_commande,
              c.Montant_total_commande, c.statut, c.auto_generee,
              c.date_validation, c.date_envoi_fournisseur, c.date_livraison_prev,
              c.commentaire,
              f.Nom AS fournisseur, f.id_fournisseur,
              p.Nom AS pharmacie,
              (SELECT COUNT(*) FROM Contenir ct WHERE ct.id_commande = c.id_commande) AS nb_lignes
         FROM Commande c
         JOIN Fournisseur f ON f.id_fournisseur = c.id_fournisseur
         JOIN Pharmacie   p ON p.Id_pharmacie   = c.Id_pharmacie
        WHERE 1=1
    ";
    $params = [];
    if ($statut) { $sql .= " AND c.statut = :st";  $params[':st'] = $statut; }
    if ($four)   { $sql .= " AND c.id_fournisseur = :f"; $params[':f']  = $four; }
    $sql .= " ORDER BY c.Date_et_heure_de_la_commande DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['commandes' => $stmt->fetchAll()]);
}

// ----- GET détail BC -----
if (method() === 'GET' && $id && !$action) {
    $stmt = $pdo->prepare("
       SELECT c.*, f.Nom AS fournisseur_nom, p.Nom AS pharmacie_nom
         FROM Commande c
         JOIN Fournisseur f ON f.id_fournisseur = c.id_fournisseur
         JOIN Pharmacie   p ON p.Id_pharmacie   = c.Id_pharmacie
        WHERE c.id_commande = :id
    ");
    $stmt->execute([':id' => $id]);
    $bc = $stmt->fetch();
    if (!$bc) jsonResponse(['error' => 'BC introuvable'], 404);

    $stmt = $pdo->prepare("
       SELECT ct.id_produit, p.nom_du_produit, p.dci, ct.Quantite_commandee AS qte_cmd, ct.prix_unitaire_achat AS pa
         FROM Contenir ct JOIN Produit p ON p.id_produit = ct.id_produit
        WHERE ct.id_commande = :id
    ");
    $stmt->execute([':id' => $id]);
    $bc['lignes'] = $stmt->fetchAll();

    // BL associé (s'il existe)
    $stmt = $pdo->prepare("SELECT * FROM Bon_de_livraison WHERE id_commande = :id");
    $stmt->execute([':id' => $id]);
    $bc['bl'] = $stmt->fetch() ?: null;

    jsonResponse(['commande' => $bc]);
}

// ----- POST création BC manuelle -----
// Body: { id_fournisseur, lignes: [{id_produit, qte, pa?}], commentaire? }
if (method() === 'POST') {
    requireRole(['USER','ADMIN','SUPERADMIN']);
    $b = jsonBody();
    if (empty($b['id_fournisseur']) || empty($b['lignes'])) jsonResponse(['error' => 'Fournisseur et lignes requis'], 400);

    $pdo->beginTransaction();
    try {
        $ref   = newBcRef($pdo);
        $total = 0;
        foreach ($b['lignes'] as $l) {
            $pa = (float)($l['pa'] ?? 0);
            $total += $l['qte'] * $pa;
        }

        $stmt = $pdo->prepare("
          INSERT INTO Commande(reference_bc, id_fournisseur, Id_pharmacie, statut,
                               Montant_total_commande, auto_generee, commentaire)
          VALUES(:ref, :f, 1, 'PROPOSEE', :mt, 0, :c)
        ");
        $stmt->execute([
            ':ref' => $ref,
            ':f'   => $b['id_fournisseur'],
            ':mt'  => round($total, 2),
            ':c'   => $b['commentaire'] ?? null
        ]);
        $id_cmd = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("INSERT INTO Contenir(id_commande, id_produit, Quantite_commandee, prix_unitaire_achat) VALUES(?,?,?,?)");
        foreach ($b['lignes'] as $l) {
            $pa = (float)($l['pa'] ?? 0);
            if ($pa == 0) {
                // récupérer le PA du produit
                $sp = $pdo->prepare("SELECT prix_d_achat FROM Produit WHERE id_produit = ?");
                $sp->execute([$l['id_produit']]);
                $pa = (float)$sp->fetchColumn();
            }
            $st->execute([$id_cmd, $l['id_produit'], $l['qte'], $pa]);
        }

        $pdo->commit();
        jsonResponse(['id_commande' => $id_cmd, 'reference_bc' => $ref], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

// ----- PUT actions sur une BC -----
if (method() === 'PUT' && $id) {
    $b = jsonBody();
    $stmt = $pdo->prepare("SELECT * FROM Commande WHERE id_commande = :id");
    $stmt->execute([':id' => $id]);
    $bc = $stmt->fetch();
    if (!$bc) jsonResponse(['error' => 'BC introuvable'], 404);

    switch ($action) {
        case 'valider':
            requireRole(['ADMIN','SUPERADMIN']);
            if ($bc['statut'] !== 'PROPOSEE') jsonResponse(['error' => 'BC non en attente'], 400);
            $pdo->prepare("UPDATE Commande SET statut='VALIDEE', date_validation=NOW(), valide_par=1 WHERE id_commande=?")
                ->execute([$id]);
            jsonResponse(['ok' => true, 'nouveau_statut' => 'VALIDEE']);

        case 'rejeter':
            requireRole(['ADMIN','SUPERADMIN']);
            $motif = $b['motif'] ?? 'Rejetée par ADMIN';
            $pdo->prepare("UPDATE Commande SET statut='ANNULEE', commentaire=:c WHERE id_commande=?")
                ->execute([':c' => $motif, $id]);
            jsonResponse(['ok' => true, 'nouveau_statut' => 'ANNULEE']);

        case 'envoyer':
            requireRole(['ADMIN','SUPERADMIN']);
            if ($bc['statut'] !== 'VALIDEE') jsonResponse(['error' => 'BC pas validée'], 400);
            $pdo->prepare("UPDATE Commande SET statut='ENVOYEE', date_envoi_fournisseur=NOW(), date_livraison_prev = CURDATE() + INTERVAL 3 DAY WHERE id_commande=?")
                ->execute([$id]);
            jsonResponse(['ok' => true, 'nouveau_statut' => 'ENVOYEE']);

        case 'annuler':
            requireRole(['ADMIN','SUPERADMIN','USER']);
            if ($bc['statut'] !== 'PROPOSEE') jsonResponse(['error' => 'Seules les commandes proposées peuvent être annulées'], 400);
            $pdo->prepare("UPDATE Commande SET statut='ANNULEE' WHERE id_commande=?")
                ->execute([$id]);
            jsonResponse(['ok' => true, 'nouveau_statut' => 'ANNULEE']);

        default:
            jsonResponse(['error' => "Action inconnue: $action"], 400);
    }
}

// ----- DELETE annulation BC -----
if (method() === 'DELETE' && $id) {
    requireRole(['ADMIN','SUPERADMIN']);
    $pdo->prepare("UPDATE Commande SET statut='ANNULEE' WHERE id_commande=?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
