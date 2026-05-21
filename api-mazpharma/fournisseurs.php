<?php
require_once __DIR__ . '/_config.php';

if (method() === 'GET') {
    $stmt = $pdo->query("SELECT id_fournisseur, Nom, Type_de_med_vendus, delai_livraison_j FROM Fournisseur ORDER BY Nom");
    jsonResponse(['fournisseurs' => $stmt->fetchAll()]);
}
jsonResponse(['error' => 'Méthode non supportée'], 405);
