<?php
/**
 * MAZ-Pharma API - Point d'entree
 * Cette page sert de :
 *   - Page d'accueil (verification que l'API tourne)
 *   - Documentation rapide des endpoints
 */

header('Content-Type: text/html; charset=utf-8');
$url = ($_SERVER['HTTPS'] ?? '') ? 'https' : 'http';
$url .= '://' . $_SERVER['HTTP_HOST'];

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>MAZ-Pharma API</title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:30px;line-height:1.6}
  .wrap{max-width:900px;margin:0 auto}
  h1{color:#0d9488;margin:0 0 6px}
  .sub{color:#64748b;margin-bottom:24px}
  .status{background:#16a34a;color:white;padding:8px 16px;border-radius:6px;display:inline-block;font-weight:700;margin-bottom:20px}
  .card{background:#1e293b;padding:18px;border-radius:10px;margin-bottom:14px;border-left:4px solid #0d9488}
  .endpoint{display:flex;gap:10px;align-items:center;padding:6px 0;font-family:Menlo,monospace;font-size:13px}
  .verb{padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px;min-width:50px;text-align:center}
  .GET{background:#3b82f6;color:white}
  .POST{background:#16a34a;color:white}
  .PUT{background:#f59e0b;color:white}
  a{color:#94f4d0;text-decoration:none}
  a:hover{text-decoration:underline}
  code{background:#0f172a;padding:2px 6px;border-radius:4px;color:#94f4d0}
</style>
</head>
<body>
<div class="wrap">
  <h1>API MAZ-Pharma</h1>
  <div class="sub">Stage 2026 - Mohamed Ali ZOUHAYR - BD Railway Stage2026</div>
  <div class="status">API en ligne</div>

  <div class="card">
    <h3 style="margin-top:0">Tests rapides (cliquez)</h3>
    <div class="endpoint"><span class="verb GET">GET</span> <a href="<?= $url ?>/produits.php">/produits.php</a> - liste des 10 medicaments</div>
    <div class="endpoint"><span class="verb GET">GET</span> <a href="<?= $url ?>/fournisseurs.php">/fournisseurs.php</a> - 8 fournisseurs marocains</div>
    <div class="endpoint"><span class="verb GET">GET</span> <a href="<?= $url ?>/kpi.php?action=overview">/kpi.php?action=overview</a> - KPI globaux</div>
    <div class="endpoint"><span class="verb GET">GET</span> <a href="<?= $url ?>/bc.php">/bc.php</a> - liste des bons de commande</div>
    <div class="endpoint"><span class="verb GET">GET</span> <a href="<?= $url ?>/kpi.php?action=alertes">/kpi.php?action=alertes</a> - produits sous seuil</div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Authentification</h3>
    <p style="margin:6px 0">Pour les endpoints proteges, recuperez un token via :</p>
    <pre style="background:#0f172a;padding:10px;border-radius:6px;font-size:12px">POST <?= $url ?>/auth.php?action=login
Content-Type: application/json
{"login":"a.kamal","password":"demo123"}</pre>
    <p style="margin:6px 0;font-size:12px;color:#64748b">Comptes : <code>superadmin</code> / <code>a.kamal</code> (ADMIN) / <code>h.bensaid</code> (USER) / <code>s.alaoui</code> (USER)</p>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Tests interactifs</h3>
    <p>Ouvrez <code>test.html</code> (depuis votre PC) et renseignez <code><?= $url ?></code> comme URL de base.</p>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Diagnostic</h3>
    <?php
      $envs = ['DB_HOST','DB_PORT','DB_USER','DB_NAME'];
      foreach ($envs as $e) {
        $v = getenv($e) ?: '<span style="color:#dc2626">non defini</span>';
        echo "<div style='font-family:Menlo,monospace;font-size:12px'>{$e} = {$v}</div>";
      }
      $dbpass = getenv('DB_PASSWORD');
      echo "<div style='font-family:Menlo,monospace;font-size:12px'>DB_PASSWORD = " . ($dbpass ? str_repeat('*', strlen($dbpass)) . " (defini)" : '<span style="color:#dc2626">non defini</span>') . "</div>";

      // Test connexion BD
      try {
        require_once __DIR__ . '/_config.php';
        // si on arrive ici sans exit, c'est que la connexion a marche, mais _config envoie un header JSON
      } catch (Exception $e) {
        echo "<div style='color:#dc2626;margin-top:8px'>Erreur connexion : " . $e->getMessage() . "</div>";
      }
    ?>
  </div>
</div>
</body>
</html>
