<?php
require_once __DIR__ . '/_config.php';

$payload      = requireRole(['ADMIN', 'SUPERADMIN']);
$id_pharmacie = getPharmacieId($pdo, $payload);

// Clause WHERE réutilisables selon le type de filtre
$whereCom = $id_pharmacie !== null ? "AND c.Id_pharmacie = $id_pharmacie" : "";
$whereVente = $id_pharmacie !== null
    ? "AND v.id_vente IN (SELECT id_vente FROM Realiser WHERE Id_pharmacie = $id_pharmacie)"
    : "";
$whereProd = $id_pharmacie !== null
    ? "AND p.id_produit IN (SELECT id_produit FROM Proposer WHERE Id_pharmacie = $id_pharmacie)"
    : "";
$whereProposer = $id_pharmacie !== null ? "AND pr.Id_pharmacie = $id_pharmacie" : "";

$action = q('action', 'overview');

switch ($action) {

    case 'overview':
        $data = [];

        $data['nb_produits'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Produit p WHERE actif = 1 $whereProd
        ")->fetchColumn();

        $data['nb_fournisseurs'] = (int)$pdo->query("SELECT COUNT(*) FROM Fournisseur")->fetchColumn();
        $data['nb_medecins']     = (int)$pdo->query("SELECT COUNT(*) FROM Medecin_")->fetchColumn();
        $data['nb_pharmacies']   = (int)$pdo->query("SELECT COUNT(*) FROM Pharmacie")->fetchColumn();

        $data['nb_ventes_7j'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Vente v
            WHERE v.date_et_heure_de_la_vente >= NOW() - INTERVAL 7 DAY $whereVente
        ")->fetchColumn();

        $data['ca_7j'] = (float)$pdo->query("
            SELECT COALESCE(SUM(montant_total), 0) FROM Vente v
            WHERE v.date_et_heure_de_la_vente >= NOW() - INTERVAL 7 DAY $whereVente
        ")->fetchColumn();

        $data['produits_sous_seuil'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Produit p
            JOIN Proposer pr ON pr.id_produit   = p.id_produit
            JOIN Pharmacie ph ON ph.Id_pharmacie = pr.Id_pharmacie
            WHERE p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100))
            $whereProposer
        ")->fetchColumn();

        $data['produits_rupture'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Produit p WHERE p.Quantite_disponible = 0 $whereProd
        ")->fetchColumn();

        $data['bc_proposees'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Commande c WHERE c.statut = 'PROPOSEE' $whereCom
        ")->fetchColumn();

        // NOTE : statut 'PROPOSEE' en BDD = label "Passée" dans l'app (commande.jsx CONFIG_STATUT)
        // TODO: statut 'PROPOSEE' = BC passées en attente de traitement.
        // Changer pour IN ('VALIDEE','ENVOYEE') si on veut les BC en cours côté fournisseur.
        $data['bc_en_cours'] = (int)$pdo->query("
            SELECT COUNT(*) FROM Commande c WHERE c.statut = 'PROPOSEE' $whereCom
        ")->fetchColumn();

        jsonResponse($data);

    case 'by_statut':
        $stmt = $pdo->query("
            SELECT statut, COUNT(*) AS n, SUM(Montant_total_commande) AS total
            FROM Commande c WHERE 1=1 $whereCom
            GROUP BY statut
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'by_day':
        $whereDay = $id_pharmacie !== null ? "WHERE Id_pharmacie = $id_pharmacie" : "";
        $stmt = $pdo->query("SELECT * FROM v_kpi_pharmacie $whereDay ORDER BY jour DESC LIMIT 30");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'by_fournisseur':
        $stmt = $pdo->query("
           SELECT f.id_fournisseur, f.Nom AS fournisseur,
                  COUNT(CASE WHEN c.statut <> 'ANNULEE' THEN 1 END) AS nb_bc,
                  SUM(c.Montant_total_commande) AS montant_total,
                  SUM(CASE WHEN c.statut = 'LIVREE'         THEN 1 ELSE 0 END) AS nb_livrees,
                  SUM(CASE WHEN c.statut = 'LIVREE_PARTIEL' THEN 1 ELSE 0 END) AS nb_livrees_partiel,
                  AVG(DATEDIFF(IFNULL(c.date_envoi_fournisseur, NOW()), c.date_validation)) AS delai_envoi_moyen
             FROM Fournisseur f
             LEFT JOIN Commande c ON c.id_fournisseur = f.id_fournisseur
                   AND 1=1 $whereCom
            GROUP BY f.id_fournisseur, f.Nom
            ORDER BY montant_total DESC
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'top_produits':
        $stmt = $pdo->query("
           SELECT p.id_produit, p.nom_du_produit, p.dci,
                  SUM(con.Quantite_vendu) AS qte_totale,
                  SUM(con.Quantite_vendu * con.prix_unitaire) AS ca_total
             FROM Concerner_ con
             JOIN Produit p ON p.id_produit = con.id_produit
             JOIN Vente v   ON v.id_vente   = con.id_vente
            WHERE 1=1 $whereVente
            GROUP BY p.id_produit, p.nom_du_produit, p.dci
            ORDER BY ca_total DESC LIMIT 10
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'by_month':
        $stmt = $pdo->query("
            SELECT DATE_FORMAT(v.date_et_heure_de_la_vente, '%Y-%m') AS mois,
                   DATE_FORMAT(v.date_et_heure_de_la_vente, '%b %Y')  AS mois_label,
                   COUNT(DISTINCT v.id_vente)                          AS nb_ventes,
                   ROUND(SUM(v.montant_total), 2)                      AS ca_mois
              FROM Vente v
             WHERE 1=1 $whereVente
             GROUP BY DATE_FORMAT(v.date_et_heure_de_la_vente, '%Y-%m'),
                      DATE_FORMAT(v.date_et_heure_de_la_vente, '%b %Y')
             ORDER BY mois ASC
             LIMIT 24
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'alertes':
        $stmt = $pdo->query("
           SELECT p.id_produit, p.nom_du_produit, p.Quantite_disponible AS stock, p.stock_max,
                  COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100)) AS seuil,
                  CASE WHEN p.Quantite_disponible = 0 THEN 'RUPTURE' ELSE 'SOUS_SEUIL' END AS niveau
             FROM Produit p
             JOIN Proposer pr  ON pr.id_produit    = p.id_produit
             JOIN Pharmacie ph ON ph.Id_pharmacie  = pr.Id_pharmacie
            WHERE p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100))
            $whereProposer
            ORDER BY p.Quantite_disponible ASC
        ");
        jsonResponse(['alertes' => $stmt->fetchAll()]);

    default:
        jsonResponse(['error' => "Action inconnue: $action"], 400);
}
