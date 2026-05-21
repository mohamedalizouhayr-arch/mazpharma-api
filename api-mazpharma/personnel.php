<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste du personnel -----
// ADMIN uniquement : voit le personnel de sa pharmacie
if (method() === 'GET') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);

    if ($payload['role'] === 'SUPERADMIN') {
        // le superadmin voit tout le personnel de toutes les pharmacies
        $stmt = $pdo->query("
            SELECT p.id_personnel, p.Nom, p.Prenom, p.Numero_de_telephone,
                   p.Fonction, p.Date_de_prise_du_poste, p.Sexe,
                   ph.Nom AS pharmacie
              FROM Personnel p
              JOIN Pharmacie ph ON ph.Id_pharmacie = p.Id_pharmacie
             ORDER BY p.Nom, p.Prenom
        ");
        jsonResponse(['personnel' => $stmt->fetchAll()]);
    }

    // ADMIN : uniquement le personnel de sa pharmacie
    $stmt = $pdo->prepare("
        SELECT p.id_personnel, p.Nom, p.Prenom, p.Numero_de_telephone,
               p.Fonction, p.Date_de_prise_du_poste, p.Sexe,
               ph.Nom AS pharmacie
          FROM Personnel p
          JOIN Admin adm     ON adm.Id_pharmacie  = p.Id_pharmacie
          JOIN Pharmacie ph  ON ph.Id_pharmacie   = p.Id_pharmacie
         WHERE adm.id_compte = :id
         ORDER BY p.Nom, p.Prenom
    ");
    $stmt->execute([':id' => $payload['id']]);
    jsonResponse(['personnel' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
