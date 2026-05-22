<?php
require_once __DIR__ . '/_config.php';

// GET : liste de tous les utilisateurs (SUPERADMIN uniquement)
if (method() === 'GET') {
    requireRole(['SUPERADMIN']);

    $stmt = $pdo->query("
        SELECT c.id_compte, c.Nom_d_utilisateur AS login, c.Role, c.actif,
               a.prenom, a.nom,
               ph.Id_pharmacie, ph.Nom AS pharmacie_nom
          FROM Compte c
          JOIN Admin a      ON a.id_compte    = c.id_compte
          JOIN Pharmacie ph ON ph.Id_pharmacie = a.Id_pharmacie
         WHERE c.Role = 'ADMIN'

        UNION ALL

        SELECT c.id_compte, c.Nom_d_utilisateur AS login, c.Role, c.actif,
               p.Prenom AS prenom, p.Nom AS nom,
               ph.Id_pharmacie, ph.Nom AS pharmacie_nom
          FROM Compte c
          JOIN Personnel p  ON p.id_compte    = c.id_compte
          JOIN Pharmacie ph ON ph.Id_pharmacie = p.Id_pharmacie
         WHERE c.Role = 'USER'

        UNION ALL

        SELECT c.id_compte, c.Nom_d_utilisateur AS login, c.Role, c.actif,
               sa.prenom, sa.nom,
               NULL AS Id_pharmacie, NULL AS pharmacie_nom
          FROM Compte c
          JOIN SuperAdmin sa ON sa.id_compte = c.id_compte
         WHERE c.Role = 'SUPERADMIN'

        ORDER BY Role, login
    ");

    jsonResponse(['utilisateurs' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Méthode non supportée'], 405);
