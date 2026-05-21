<?php
require_once __DIR__ . '/_config.php';

$id = q('id');

// ----- GET liste -----
if (method() === 'GET' && !$id) {
    $stmt = $pdo->query("
       SELECT bl.id_bl, bl.reference_bl, bl.date_reception, bl.statut, bl.ecart_constate,
              c.reference_bc, f.Nom AS fournisseur,
              (SELECT COUNT(*) FROM Ligne_BL lbl WHERE lbl.id_bl = bl.id_bl) AS nb_lignes,
              (SELECT COUNT(*) FROM Ligne_BL lbl WHERE lbl.id_bl = bl.id_bl AND lbl.qte_recue < lbl.qte_commandee) AS nb_ecarts
         FROM Bon_de_livraison bl
         JOIN Commande c    ON c.id_commande   = bl.id_commande
         JOIN Fournisseur f ON f.id_fournisseur = c.id_fournisseur
         ORDER BY bl.date_reception DESC LIMIT 100
    ");
    jsonResponse(['livraisons' => $stmt->fetchAll()]);
}

// ----- GET détail BL -----
if (method() === 'GET' && $id) {
    $stmt = $pdo->prepare("
       SELECT bl.*, c.reference_bc, c.Montant_total_commande, f.Nom AS fournisseur
         FROM Bon_de_livraison bl
         JOIN Commande c    ON c.id_commande   = bl.id_commande
         JOIN Fournisseur f ON f.id_fournisseur = c.id_fournisseur
        WHERE bl.id_bl = :id
    ");
    $stmt->execute([':id' => $id]);
    $bl = $stmt->fetch();
    if (!$bl) jsonResponse(['error' => 'BL introuvable'], 404);

    $stmt = $pdo->prepare("
       SELECT lbl.id_produit, p.nom_du_produit, lbl.qte_commandee, lbl.qte_recue,
              lbl.numero_lot, lbl.date_peremption,
              (lbl.qte_commandee - COALESCE(lbl.qte_recue,0)) AS manquant
         FROM Ligne_BL lbl JOIN Produit p ON p.id_produit = lbl.id_produit
        WHERE lbl.id_bl = :id
    ");
    $stmt->execute([':id' => $id]);
    $bl['lignes'] = $stmt->fetchAll();
    jsonResponse(['livraison' => $bl]);
}

// ----- POST création BL à partir d'une BC -----
// Body: { id_commande, lignes:[{id_produit, qte_recue, numero_lot, date_peremption}] }
if (method() === 'POST') {
    requireRole(['USER','ADMIN','SUPERADMIN']);
    $b = jsonBody();
    if (empty($b['id_commande']) || empty($b['lignes'])) jsonResponse(['error' => 'id_commande et lignes requis'], 400);

    $pdo->beginTransaction();
    try {
        // Vérifier que la BC est bien en ENVOYEE ou LIVRAISON
        $stmt = $pdo->prepare("SELECT statut FROM Commande WHERE id_commande = ?");
        $stmt->execute([$b['id_commande']]);
        $st = $stmt->fetchColumn();
        if (!in_array($st, ['ENVOYEE','LIVRAISON'], true)) {
            throw new Exception("Statut BC '$st' invalide pour création BL");
        }

        $ref = newBlRef($pdo);
        $hasEcart = false;

        $stmt = $pdo->prepare("
           INSERT INTO Bon_de_livraison(reference_bl, id_commande, id_user_reception, statut)
           VALUES(:r, :c, 1, 'EN_ATTENTE')
        ");
        $stmt->execute([':r' => $ref, ':c' => $b['id_commande']]);
        $id_bl = (int)$pdo->lastInsertId();

        // Récupérer les quantités commandées
        $stmt = $pdo->prepare("SELECT id_produit, Quantite_commandee FROM Contenir WHERE id_commande = ?");
        $stmt->execute([$b['id_commande']]);
        $qteCmd = [];
        foreach ($stmt->fetchAll() as $r) $qteCmd[$r['id_produit']] = $r['Quantite_commandee'];

        $stmtLigne = $pdo->prepare("INSERT INTO Ligne_BL(id_bl, id_produit, qte_commandee, qte_recue, numero_lot, date_peremption) VALUES(?,?,?,?,?,?)");
        foreach ($b['lignes'] as $l) {
            $pid = $l['id_produit'];
            $cmd = $qteCmd[$pid] ?? 0;
            $rec = (int)($l['qte_recue'] ?? 0);
            if ($rec < $cmd) $hasEcart = true;
            $stmtLigne->execute([$id_bl, $pid, $cmd, $rec, $l['numero_lot'] ?? null, $l['date_peremption'] ?? null]);
        }

        // Passage à CONFORME ou ECART -> déclenche le trigger tg_maj_stock_bl
        $newStatut = $hasEcart ? 'ECART' : 'CONFORME';
        $pdo->prepare("UPDATE Bon_de_livraison SET statut = ? WHERE id_bl = ?")->execute([$newStatut, $id_bl]);

        $pdo->commit();
        jsonResponse([
            'id_bl' => $id_bl,
            'reference_bl' => $ref,
            'statut' => $newStatut,
            'ecart' => $hasEcart
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
