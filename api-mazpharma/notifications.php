<?php
require_once __DIR__ . '/_config.php';

// GET  /notifications.php          -> liste des notifs de la pharmacie
// POST /notifications.php?action=check -> verifier alertes stock apres une vente
// PUT  /notifications.php?id=N&action=valider -> valider : cree un BC
// PUT  /notifications.php?id=N&action=rejeter -> rejeter la notif
// PUT  /notifications.php?id=N&action=lue    -> marquer comme lue

// ----- helper : trouver Id_pharmacie depuis id_compte -----
function getPharmacieId(PDO $pdo, int $id_compte): int {
    try {
        $s = $pdo->prepare("SELECT Id_pharmacie FROM Personnel WHERE id_compte = :id LIMIT 1");
        $s->execute([':id' => $id_compte]);
        $ph = $s->fetchColumn();
        if ($ph) return (int)$ph;
    } catch (Exception $e) {}
    try {
        $s = $pdo->prepare("SELECT Id_pharmacie FROM Admin WHERE id_compte = :id LIMIT 1");
        $s->execute([':id' => $id_compte]);
        $ph = $s->fetchColumn();
        if ($ph) return (int)$ph;
    } catch (Exception $e) {}
    return 1;
}

// ----- GET liste -----
if (method() === 'GET') {
    $payload      = requireRole(['USER', 'ADMIN', 'SUPERADMIN']);
    $id_pharmacie = getPharmacieId($pdo, $payload['id']);

    $stmt = $pdo->prepare("
        SELECT n.id_notif, n.titre, n.message, n.type, n.statut,
               n.seuil, n.qte_suggere, n.date_creation, n.date_action,
               n.id_commande,
               p.id_produit, p.nom_du_produit, p.Quantite_disponible AS stock_actuel
          FROM Notification n
          LEFT JOIN Produit p ON p.id_produit = n.id_produit
         WHERE n.Id_pharmacie = :ph
         ORDER BY n.date_creation DESC
         LIMIT 50
    ");
    $stmt->execute([':ph' => $id_pharmacie]);
    jsonResponse(['notifications' => $stmt->fetchAll()]);
}

// ----- POST check alertes apres vente -----
if (method() === 'POST' && q('action') === 'check') {
    $payload      = requireRole(['USER', 'ADMIN', 'SUPERADMIN']);
    $raw          = file_get_contents('php://input');
    $b            = (is_array(json_decode($raw, true))) ? json_decode($raw, true) : [];
    $id_produits  = array_map('intval', $b['id_produits'] ?? []);
    $seuil_global = max(0, (int)($b['seuil_global'] ?? 10));

    if (empty($id_produits)) jsonResponse(['created' => 0, '_debug' => ['raw' => $raw, 'b' => $b]]);

    $id_pharmacie = getPharmacieId($pdo, $payload['id']);

    $in   = implode(',', array_fill(0, count($id_produits), '?'));
    $stmt = $pdo->prepare("
        SELECT id_produit, nom_du_produit, Quantite_disponible,
               seuil_alerte_manuel, seuil_min_manuel, prix_d_achat
          FROM Produit
         WHERE id_produit IN ($in)
    ");
    $stmt->execute($id_produits);
    $produits = $stmt->fetchAll();

    $created = 0;
    $alertes = []; // liste des produits qui ont declenche une alerte

    foreach ($produits as $p) {
        // priorite : seuil_alerte_manuel > seuil_min_manuel > seuil_global
        if ($p['seuil_alerte_manuel'] !== null) {
            $seuil = (int)$p['seuil_alerte_manuel'];
        } elseif ($p['seuil_min_manuel'] !== null) {
            $seuil = (int)$p['seuil_min_manuel'];
        } else {
            $seuil = $seuil_global;
        }

        if ($seuil <= 0)                          continue;
        if ($p['Quantite_disponible'] >= $seuil)  continue;

        // eviter doublon : ne pas creer si une notif PENDING existe deja
        $stmtChk = $pdo->prepare("
            SELECT id_notif FROM Notification
             WHERE id_produit = :pid AND Id_pharmacie = :ph AND statut = 'PENDING'
             LIMIT 1
        ");
        $stmtChk->execute([':pid' => $p['id_produit'], ':ph' => $id_pharmacie]);
        if ($stmtChk->fetchColumn()) {
            // notif deja existante : on l'inclut quand meme dans alertes pour la notif systeme
            $alertes[] = [
                'nom'   => $p['nom_du_produit'],
                'stock' => (int)$p['Quantite_disponible'],
                'seuil' => $seuil,
            ];
            continue;
        }

        $qte_suggere = (int)ceil($seuil * 1.5);
        $titre   = "Alerte stock : " . $p['nom_du_produit'];
        $message = "Stock actuel : {$p['Quantite_disponible']} (seuil : {$seuil}). "
                 . "Voulez-vous commander {$qte_suggere} unites ?";

        $pdo->prepare("
            INSERT INTO Notification(Id_pharmacie, id_produit, titre, message,
                                     type, statut, seuil, qte_suggere)
            VALUES(:ph, :pid, :t, :m, 'ALERTE_STOCK', 'PENDING', :s, :q)
        ")->execute([
            ':ph'  => $id_pharmacie,
            ':pid' => $p['id_produit'],
            ':t'   => $titre,
            ':m'   => $message,
            ':s'   => $seuil,
            ':q'   => $qte_suggere,
        ]);

        $alertes[] = [
            'nom'   => $p['nom_du_produit'],
            'stock' => (int)$p['Quantite_disponible'],
            'seuil' => $seuil,
        ];
        $created++;
    }

    jsonResponse(['created' => $created, 'alertes' => $alertes]);
}

// ----- PUT actions -----
if (method() === 'PUT' && q('id')) {
    $payload = requireRole(['USER', 'ADMIN', 'SUPERADMIN']);
    $id      = (int)q('id');
    $action  = q('action', 'lue');

    $stmt = $pdo->prepare("SELECT * FROM Notification WHERE id_notif = :id");
    $stmt->execute([':id' => $id]);
    $notif = $stmt->fetch();
    if (!$notif) jsonResponse(['error' => 'Notification introuvable'], 404);

    // ----- valider : cree un BC PROPOSEE -----
    if ($action === 'valider') {
        requireRole(['ADMIN', 'SUPERADMIN']);
        if ($notif['statut'] !== 'PENDING') jsonResponse(['error' => 'Notification deja traitee'], 400);

        $id_pharmacie = (int)$notif['Id_pharmacie'];

        // chercher fournisseur lie a la pharmacie
        $stmtF = $pdo->prepare("SELECT id_fournisseur FROM Commander WHERE Id_pharmacie = :ph LIMIT 1");
        $stmtF->execute([':ph' => $id_pharmacie]);
        $id_fournisseur = $stmtF->fetchColumn();

        // fallback : premier fournisseur disponible
        if (!$id_fournisseur) {
            $id_fournisseur = $pdo->query("SELECT id_fournisseur FROM Fournisseur LIMIT 1")->fetchColumn();
        }
        if (!$id_fournisseur) jsonResponse(['error' => 'Aucun fournisseur disponible'], 400);

        // prix achat du produit
        $stmtP = $pdo->prepare("SELECT prix_d_achat FROM Produit WHERE id_produit = :pid");
        $stmtP->execute([':pid' => $notif['id_produit']]);
        $pa  = (float)($stmtP->fetchColumn() ?: 0);
        $qte = (int)$notif['qte_suggere'];

        $pdo->beginTransaction();
        try {
            $ref = newBcRef($pdo);
            $pdo->prepare("
                INSERT INTO Commande(reference_bc, id_fournisseur, Id_pharmacie,
                                     statut, Montant_total_commande, auto_generee, commentaire)
                VALUES(:ref, :f, :ph, 'PROPOSEE', :mt, 1, :c)
            ")->execute([
                ':ref' => $ref,
                ':f'   => $id_fournisseur,
                ':ph'  => $id_pharmacie,
                ':mt'  => round($qte * $pa, 2),
                ':c'   => "BC valide depuis notification : " . $notif['titre'],
            ]);
            $id_commande = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO Contenir(id_commande, id_produit, Quantite_commandee, prix_unitaire_achat)
                VALUES(?,?,?,?)
            ")->execute([$id_commande, $notif['id_produit'], $qte, $pa]);

            $pdo->prepare("
                UPDATE Notification
                   SET statut='VALIDEE', id_commande=?, date_action=NOW()
                 WHERE id_notif=?
            ")->execute([$id_commande, $id]);

            $pdo->commit();
            jsonResponse(['ok' => true, 'id_commande' => $id_commande, 'reference_bc' => $ref]);

        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    // ----- rejeter -----
    if ($action === 'rejeter') {
        $pdo->prepare("UPDATE Notification SET statut='REJETEE', date_action=NOW() WHERE id_notif=?")
            ->execute([$id]);
        jsonResponse(['ok' => true]);
    }

    // ----- marquer comme lue (sans valider) -----
    if ($action === 'lue') {
        $pdo->prepare("UPDATE Notification SET statut='LUE', date_action=NOW() WHERE id_notif=? AND statut='PENDING'")
            ->execute([$id]);
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['error' => "Action inconnue : $action"], 400);
}

jsonResponse(['error' => 'Methode non supportee'], 405);
