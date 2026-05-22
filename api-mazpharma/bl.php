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
       SELECT lbl.id_produit, p.nom_du_produit, p.dci,
              lbl.qte_commandee, lbl.qte_recue,
              (lbl.qte_commandee - COALESCE(lbl.qte_recue,0)) AS manquant
         FROM Ligne_BL lbl JOIN Produit p ON p.id_produit = lbl.id_produit
        WHERE lbl.id_bl = :id
    ");
    $stmt->execute([':id' => $id]);
    $bl['lignes'] = $stmt->fetchAll();
    jsonResponse(['livraison' => $bl]);
}

// ----- POST création BL à partir d'une BC -----
// Body: { id_commande, lignes:[{id_produit, qte_recue}], commentaire? }
if (method() === 'POST') {
    requireRole(['USER','ADMIN','SUPERADMIN']);
    $b = jsonBody();
    if (empty($b['id_commande']) || empty($b['lignes'])) {
        jsonResponse(['error' => 'id_commande et lignes requis'], 400);
    }

    $pdo->beginTransaction();
    try {
        // Vérifier que la BC n'est pas déjà livrée ou annulée
        $stmt = $pdo->prepare("SELECT statut FROM Commande WHERE id_commande = ?");
        $stmt->execute([$b['id_commande']]);
        $st = $stmt->fetchColumn();
        if (in_array($st, ['LIVREE', 'LIVREE_PARTIEL', 'ANNULEE'], true)) {
            throw new Exception("Impossible de créer un BL pour une commande en statut $st");
        }

        // Vérifier qu'aucun BL n'existe déjà pour cette commande
        $stmtEx = $pdo->prepare("SELECT COUNT(*) FROM Bon_de_livraison WHERE id_commande = ?");
        $stmtEx->execute([$b['id_commande']]);
        if ((int)$stmtEx->fetchColumn() > 0) {
            throw new Exception("Un bon de livraison existe déjà pour cette commande");
        }

        $ref      = newBlRef($pdo);
        $hasEcart = false;

        // Récupérer les quantités commandées
        $stmt = $pdo->prepare("
            SELECT ct.id_produit, ct.Quantite_commandee, p.nom_du_produit
              FROM Contenir ct JOIN Produit p ON p.id_produit = ct.id_produit
             WHERE ct.id_commande = ?
        ");
        $stmt->execute([$b['id_commande']]);
        $produitsCmd = [];
        foreach ($stmt->fetchAll() as $r) {
            $produitsCmd[$r['id_produit']] = [
                'qte'  => $r['Quantite_commandee'],
                'nom'  => $r['nom_du_produit'],
            ];
        }

        // Calculer les écarts
        $ecartDetails = [];
        $lignesFinales = [];
        foreach ($b['lignes'] as $l) {
            $pid = (int)$l['id_produit'];
            $cmd = $produitsCmd[$pid]['qte'] ?? 0;
            $rec = max(0, (int)($l['qte_recue'] ?? $cmd));
            if ($rec < $cmd) {
                $hasEcart      = true;
                $nom           = $produitsCmd[$pid]['nom'] ?? "Produit #$pid";
                $ecartDetails[] = "$nom : commandé $cmd, reçu $rec";
            }
            $lignesFinales[] = ['id_produit' => $pid, 'cmd' => $cmd, 'rec' => $rec];
        }

        // Construire le texte ecart_constate
        $ecartTexte = '';
        if ($hasEcart) {
            $ecartTexte = implode(' | ', $ecartDetails);
        }
        $commentaire = trim($b['commentaire'] ?? '');
        if ($commentaire) {
            $ecartTexte = $ecartTexte ? "$ecartTexte. $commentaire" : $commentaire;
        }

        // Créer le BL (statut EN_ATTENTE)
        $stmt = $pdo->prepare("
           INSERT INTO Bon_de_livraison(reference_bl, id_commande, id_user_reception, statut)
           VALUES(:r, :c, 1, 'EN_ATTENTE')
        ");
        $stmt->execute([':r' => $ref, ':c' => $b['id_commande']]);
        $id_bl = (int)$pdo->lastInsertId();

        // Insérer les lignes
        $stmtLigne = $pdo->prepare("
            INSERT INTO Ligne_BL(id_bl, id_produit, qte_commandee, qte_recue)
            VALUES(?, ?, ?, ?)
        ");
        foreach ($lignesFinales as $l) {
            $stmtLigne->execute([$id_bl, $l['id_produit'], $l['cmd'], $l['rec']]);
        }

        // Si un produit reçu n'est pas encore dans le stock de la pharmacie, on l'y rattache
        // avant que tg_maj_stock_bl ne mette à jour Quantite_disponible
        $stmtPh = $pdo->prepare("SELECT Id_pharmacie FROM Commande WHERE id_commande = ?");
        $stmtPh->execute([$b['id_commande']]);
        $id_pharmacie_bl = $stmtPh->fetchColumn();
        if ($id_pharmacie_bl) {
            $stmtPro = $pdo->prepare("INSERT IGNORE INTO Proposer(Id_pharmacie, id_produit) VALUES(?,?)");
            foreach ($lignesFinales as $l) {
                if ($l['rec'] > 0) {
                    $stmtPro->execute([$id_pharmacie_bl, $l['id_produit']]);
                }
            }
        }

        // Passer CONFORME ou ECART (déclenche tg_maj_stock_bl → stock + statut BC)
        $newStatut = $hasEcart ? 'ECART' : 'CONFORME';
        $pdo->prepare("
            UPDATE Bon_de_livraison
               SET statut = ?, ecart_constate = ?
             WHERE id_bl = ?
        ")->execute([$newStatut, $ecartTexte ?: null, $id_bl]);

        $pdo->commit();
        jsonResponse([
            'id_bl'        => $id_bl,
            'reference_bl' => $ref,
            'statut'       => $newStatut,
            'ecart'        => $hasEcart,
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
