<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste -----
if (method() === 'GET' && !q('id')) {
    $from = q('from');
    $to   = q('to');
    $sql = "
       SELECT v.id_vente, v.date_et_heure_de_la_vente, v.montant_total, v.mode_paiement,
              v.reference_ordonnance,
              c.Nom AS client_nom, c.Prenom AS client_prenom,
              p.Nom AS user_nom, p.Prenom AS user_prenom,
              m.Nom AS medecin_nom, m.Prenom AS medecin_prenom
         FROM Vente v
         LEFT JOIN Client    c ON c.id_client    = v.id_client
         LEFT JOIN Personnel p ON p.id_personnel = v.id_personnel
         LEFT JOIN Medecin_  m ON m.id_medecin   = v.id_medecin
        WHERE 1=1
    ";
    $params = [];
    if ($from) { $sql .= " AND v.date_et_heure_de_la_vente >= :from"; $params[':from'] = $from; }
    if ($to)   { $sql .= " AND v.date_et_heure_de_la_vente <  :to";   $params[':to']   = $to; }
    $sql .= " ORDER BY v.date_et_heure_de_la_vente DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['ventes' => $stmt->fetchAll()]);
}

// ----- GET détail d'une vente (avec lignes) -----
if (method() === 'GET' && q('id')) {
    $id = q('id');
    $stmt = $pdo->prepare("SELECT * FROM Vente WHERE id_vente = :id");
    $stmt->execute([':id' => $id]);
    $v = $stmt->fetch();
    if (!$v) jsonResponse(['error' => 'Vente introuvable'], 404);
    $stmt = $pdo->prepare("
       SELECT c.id_produit, p.nom_du_produit, p.dci, c.Quantite_vendu AS qte, c.prix_unitaire AS pu,
              (c.Quantite_vendu * c.prix_unitaire) AS total_ligne
         FROM Concerner_ c
         JOIN Produit p ON p.id_produit = c.id_produit
        WHERE c.id_vente = :id
    ");
    $stmt->execute([':id' => $id]);
    $v['lignes'] = $stmt->fetchAll();
    jsonResponse(['vente' => $v]);
}

// ----- POST nouvelle vente -----
// Body: { id_personnel, id_client, id_medecin?, mode_paiement?, lignes: [{id_produit, qte}] }
if (method() === 'POST') {
    requireRole(['USER','ADMIN','SUPERADMIN']);
    $b = jsonBody();
    if (empty($b['lignes']) || !is_array($b['lignes'])) jsonResponse(['error' => 'Lignes manquantes'], 400);

    $pdo->beginTransaction();
    try {
        // Récupérer les prix actuels
        $ids = array_column($b['lignes'], 'id_produit');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id_produit, prix_de_vente, Quantite_disponible, nom_du_produit FROM Produit WHERE id_produit IN ($in)");
        $stmt->execute($ids);
        $prix = [];
        foreach ($stmt->fetchAll() as $r) $prix[$r['id_produit']] = $r;

        // Vérifier le stock
        $total = 0;
        foreach ($b['lignes'] as $l) {
            $pid = $l['id_produit']; $qte = (int)$l['qte'];
            if (!isset($prix[$pid]))      throw new Exception("Produit $pid inconnu");
            if ($prix[$pid]['Quantite_disponible'] < $qte) {
                throw new Exception("Stock insuffisant pour {$prix[$pid]['nom_du_produit']} (dispo {$prix[$pid]['Quantite_disponible']}, demande $qte)");
            }
            $total += $qte * (float)$prix[$pid]['prix_de_vente'];
        }

        // Créer la vente
        $stmt = $pdo->prepare("
           INSERT INTO Vente(id_personnel, id_client, id_medecin, montant_total, mode_paiement, reference_ordonnance)
           VALUES(:per, :cli, :med, :mt, :mp, :ord)
        ");
        $stmt->execute([
            ':per' => $b['id_personnel'] ?? 1,
            ':cli' => $b['id_client']    ?? 1,
            ':med' => $b['id_medecin']   ?? null,
            ':mt'  => round($total, 2),
            ':mp'  => $b['mode_paiement'] ?? 'Especes',
            ':ord' => $b['reference_ordonnance'] ?? null,
        ]);
        $id_vente = (int)$pdo->lastInsertId();

        // Lignes + décrément stock (le trigger tg_log_mouvement_vente trace tout)
        $stmtLigne = $pdo->prepare("INSERT INTO Concerner_(id_produit, id_vente, Quantite_vendu, prix_unitaire) VALUES(?,?,?,?)");
        $stmtMaj   = $pdo->prepare("UPDATE Produit SET Quantite_disponible = Quantite_disponible - ? WHERE id_produit = ?");
        foreach ($b['lignes'] as $l) {
            $stmtLigne->execute([$l['id_produit'], $id_vente, $l['qte'], $prix[$l['id_produit']]['prix_de_vente']]);
            $stmtMaj->execute([$l['qte'], $l['id_produit']]);
        }

        // Lier la vente à la pharmacie
        $pdo->prepare("INSERT INTO Realiser(Id_pharmacie, id_vente) VALUES(1, ?)")->execute([$id_vente]);

        $pdo->commit();
        jsonResponse(['id_vente' => $id_vente, 'montant_total' => $total], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
