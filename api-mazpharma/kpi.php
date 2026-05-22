<?php
require_once __DIR__ . '/_config.php';

$action = q('action', 'overview');

switch ($action) {

    case 'overview':
        $data = [];
        $data['nb_produits']     = (int)$pdo->query("SELECT COUNT(*) FROM Produit WHERE actif=1")->fetchColumn();
        $data['nb_fournisseurs'] = (int)$pdo->query("SELECT COUNT(*) FROM Fournisseur")->fetchColumn();
        $data['nb_medecins']     = (int)$pdo->query("SELECT COUNT(*) FROM Medecin_")->fetchColumn();
        $data['nb_pharmacies']   = (int)$pdo->query("SELECT COUNT(*) FROM Pharmacie")->fetchColumn();
        $data['nb_ventes_7j']    = (int)$pdo->query("SELECT COUNT(*) FROM Vente WHERE date_et_heure_de_la_vente >= NOW() - INTERVAL 7 DAY")->fetchColumn();
        $data['ca_7j']           = (float)$pdo->query("SELECT COALESCE(SUM(montant_total),0) FROM Vente WHERE date_et_heure_de_la_vente >= NOW() - INTERVAL 7 DAY")->fetchColumn();
        $data['produits_sous_seuil'] = (int)$pdo->query("
           SELECT COUNT(*) FROM Produit p, Pharmacie ph
           WHERE ph.Id_pharmacie = 1
             AND p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100))
        ")->fetchColumn();
        $data['produits_rupture'] = (int)$pdo->query("SELECT COUNT(*) FROM Produit WHERE Quantite_disponible = 0")->fetchColumn();
        $data['bc_proposees']    = (int)$pdo->query("SELECT COUNT(*) FROM Commande WHERE statut='PROPOSEE'")->fetchColumn();
        $data['bc_en_cours']     = (int)$pdo->query("SELECT COUNT(*) FROM Commande WHERE statut IN ('VALIDEE','ENVOYEE')")->fetchColumn();
        jsonResponse($data);

    case 'by_statut':
        $stmt = $pdo->query("SELECT statut, COUNT(*) AS n, SUM(Montant_total_commande) AS total FROM Commande GROUP BY statut");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'by_day':
        $stmt = $pdo->query("SELECT * FROM v_kpi_pharmacie ORDER BY jour DESC LIMIT 30");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'by_fournisseur':
        $stmt = $pdo->query("
           SELECT f.id_fournisseur, f.Nom AS fournisseur,
                  COUNT(c.id_commande) AS nb_bc,
                  SUM(c.Montant_total_commande) AS montant_total,
                  SUM(CASE WHEN c.statut IN ('LIVREE','LIVREE_PARTIEL') THEN 1 ELSE 0 END) AS nb_livrees,
                  AVG(DATEDIFF(IFNULL(c.date_envoi_fournisseur, NOW()), c.date_validation)) AS delai_envoi_moyen
             FROM Fournisseur f
             LEFT JOIN Commande c ON c.id_fournisseur = f.id_fournisseur
            GROUP BY f.id_fournisseur, f.Nom
            ORDER BY montant_total DESC
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'top_produits':
        $stmt = $pdo->query("
           SELECT p.id_produit, p.nom_du_produit, p.dci,
                  SUM(c.Quantite_vendu) AS qte_totale,
                  SUM(c.Quantite_vendu * c.prix_unitaire) AS ca_total
             FROM Concerner_ c JOIN Produit p ON p.id_produit = c.id_produit
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
             WHERE v.date_et_heure_de_la_vente >= NOW() - INTERVAL 12 MONTH
             GROUP BY DATE_FORMAT(v.date_et_heure_de_la_vente, '%Y-%m')
             ORDER BY mois ASC
        ");
        jsonResponse(['data' => $stmt->fetchAll()]);

    case 'alertes':
        $stmt = $pdo->query("
           SELECT p.id_produit, p.nom_du_produit, p.Quantite_disponible AS stock, p.stock_max,
                  COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100)) AS seuil,
                  CASE WHEN p.Quantite_disponible = 0 THEN 'RUPTURE' ELSE 'SOUS_SEUIL' END AS niveau
             FROM Produit p, Pharmacie ph
            WHERE ph.Id_pharmacie = 1
              AND p.Quantite_disponible < COALESCE(p.seuil_min_manuel, ROUND(p.stock_max * ph.seuil_min_pct_general / 100))
            ORDER BY p.Quantite_disponible ASC
        ");
        jsonResponse(['alertes' => $stmt->fetchAll()]);

    default:
        jsonResponse(['error' => "Action inconnue: $action"], 400);
}
