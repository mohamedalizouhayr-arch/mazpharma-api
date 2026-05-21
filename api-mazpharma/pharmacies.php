<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste des pharmacies -----
// SUPERADMIN : toutes les pharmacies
// ADMIN      : uniquement sa pharmacie (filtree par token)
if (method() === 'GET') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);

    if ($payload['role'] === 'SUPERADMIN') {
        // le superadmin voit toutes les pharmacies + leur ville (via Adresse)
        $stmt = $pdo->query("
            SELECT p.Id_pharmacie, p.Nom, p.Numero_de_telephone,
                   p.nom_du_proprietaire, p.active,
                   a.Ville, a.Numero_de_voie, a.Nom_de_la_voie, a.Code_postale
              FROM Pharmacie p
              LEFT JOIN Adresse a ON a.id_adresse = p.id_adresse
             ORDER BY p.Nom
        ");
        jsonResponse(['pharmacies' => $stmt->fetchAll()]);
    }

    // ADMIN : on recupere sa pharmacie depuis la table Admin (liee a son compte via token)
    $stmt = $pdo->prepare("
        SELECT ph.Id_pharmacie, ph.Nom, ph.Numero_de_telephone,
               ph.nom_du_proprietaire, ph.active,
               a.Ville, a.Numero_de_voie, a.Nom_de_la_voie, a.Code_postale
          FROM Admin adm
          JOIN Pharmacie ph ON ph.Id_pharmacie = adm.Id_pharmacie
          LEFT JOIN Adresse a ON a.id_adresse   = ph.id_adresse
         WHERE adm.id_compte = :id
    ");
    $stmt->execute([':id' => $payload['id']]);
    $pharmacie = $stmt->fetch();
    // on retourne un tableau d'une seule pharmacie pour garder le meme format
    jsonResponse(['pharmacies' => $pharmacie ? [$pharmacie] : []]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
