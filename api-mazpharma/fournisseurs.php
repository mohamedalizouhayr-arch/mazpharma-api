<?php
require_once __DIR__ . '/_config.php';

// ----- GET liste des fournisseurs -----
if (method() === 'GET') {
    $stmt = $pdo->query("
        SELECT id_fournisseur, Nom, Type_de_med_vendus, Type_fournisseur,
               Site_web, contact_commercial, delai_livraison_j, ice
          FROM Fournisseur
         ORDER BY Nom
    ");
    jsonResponse(['fournisseurs' => $stmt->fetchAll()]);
}

// ----- POST : créer un fournisseur -----
if (method() === 'POST') {
    $payload = requireRole(['ADMIN', 'SUPERADMIN']);
    $raw = file_get_contents('php://input');
    $b   = (is_array(json_decode($raw, true))) ? json_decode($raw, true) : [];

    $nom = trim($b['nom'] ?? '');
    if (!$nom) jsonResponse(['error' => 'Nom du fournisseur requis'], 400);

    $pdo->beginTransaction();
    try {
        // adresse optionnelle : on la crée seulement si la ville est renseignée
        $id_adresse = null;
        $ville = trim($b['ville'] ?? '');
        if ($ville) {
            $pdo->prepare("
                INSERT INTO Adresse(Numero_de_voie, Type_de_voie, Nom_de_la_voie, Ville, Code_postale)
                VALUES(:num, :type, :nom_voie, :ville, :cp)
            ")->execute([
                ':num'      => $b['numero_voie'] ? (int)$b['numero_voie'] : null,
                ':type'     => $b['type_voie']   ?? null,
                ':nom_voie' => $b['nom_voie']    ?? null,
                ':ville'    => $ville,
                ':cp'       => $b['code_postal'] ? (int)$b['code_postal'] : null,
            ]);
            $id_adresse = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            INSERT INTO Fournisseur(Nom, Type_fournisseur, Type_de_med_vendus,
                                    contact_commercial, Site_web, ice,
                                    delai_livraison_j, id_adresse)
            VALUES(:nom, :type_f, :type_med, :contact, :site, :ice, :delai, :adr)
        ")->execute([
            ':nom'      => $nom,
            ':type_f'   => $b['type_fournisseur'] ?? null,
            ':type_med' => $b['type_med']          ?? null,
            ':contact'  => $b['contact']           ?? null,
            ':site'     => $b['site_web']          ?? null,
            ':ice'      => $b['ice']               ?? null,
            ':delai'    => isset($b['delai']) && $b['delai'] !== '' ? (int)$b['delai'] : 3,
            ':adr'      => $id_adresse,
        ]);
        $id_fournisseur = (int)$pdo->lastInsertId();

        $pdo->commit();
        jsonResponse(['id_fournisseur' => $id_fournisseur], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}


jsonResponse(['error' => 'Méthode non supportée'], 405);
