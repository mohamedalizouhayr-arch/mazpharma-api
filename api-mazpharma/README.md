# MAZ-Pharma — API REST

API PHP REST connectée à la base MySQL Railway `Stage2026`.

## Structure

```
api-mazpharma/
├── _config.php          # bootstrap PDO + helpers + CORS (inclus partout)
├── auth.php             # login / me
├── produits.php         # CRUD produits + recherche + alertes
├── ventes.php           # création vente + listing
├── bc.php               # cycle de vie des Bons de Commande
├── bl.php               # réception et validation des Bons de Livraison
├── kpi.php              # KPI consolidés (overview, by_day, by_fournisseur...)
├── fournisseurs.php     # liste fournisseurs
├── test.html            # page de test interactif (à ouvrir dans le navigateur)
├── .env.example         # modèle de variables d'environnement
├── Procfile             # commande de démarrage Railway
├── composer.json        # détection projet PHP par Railway
└── README.md            # ce fichier
```

## Endpoints

| Méthode | URL | Description |
|---|---|---|
| `POST` | `/auth.php?action=login` | Connexion (body `{login, password}`) → token |
| `GET`  | `/auth.php?action=me` | Infos utilisateur courant (header `Authorization: Bearer <token>`) |
| `GET`  | `/produits.php` | Liste produits (params : `search`, `alerte=1`) |
| `GET`  | `/produits.php?id=N` | Détail produit |
| `POST` | `/produits.php` | Création produit (ADMIN) |
| `PUT`  | `/produits.php?id=N` | MAJ stock/seuil/prix |
| `GET`  | `/ventes.php` | Liste ventes (params : `from`, `to`) |
| `GET`  | `/ventes.php?id=N` | Détail vente + lignes |
| `POST` | `/ventes.php` | Nouvelle vente (body `{id_personnel, id_client, lignes:[{id_produit,qte}]}`) |
| `GET`  | `/bc.php` | Liste BC (params : `statut`, `fournisseur`) |
| `GET`  | `/bc.php?id=N` | Détail BC + lignes + BL associé |
| `POST` | `/bc.php` | Création BC manuelle |
| `PUT`  | `/bc.php?id=N&action=valider` | Valider (ADMIN) |
| `PUT`  | `/bc.php?id=N&action=rejeter` | Rejeter (body `{motif}`) |
| `PUT`  | `/bc.php?id=N&action=envoyer` | Marquer envoyée fournisseur |
| `GET`  | `/bl.php` | Liste BL |
| `GET`  | `/bl.php?id=N` | Détail BL + lignes |
| `POST` | `/bl.php` | Création BL depuis BC (body `{id_commande, lignes:[{id_produit, qte_recue, numero_lot, date_peremption}]}`) |
| `GET`  | `/kpi.php?action=overview` | KPI globaux |
| `GET`  | `/kpi.php?action=by_day` | Vue v_kpi_pharmacie (30 derniers jours) |
| `GET`  | `/kpi.php?action=by_fournisseur` | Performance par fournisseur |
| `GET`  | `/kpi.php?action=top_produits` | Top 10 produits par CA |
| `GET`  | `/kpi.php?action=alertes` | Produits sous seuil |
| `GET`  | `/fournisseurs.php` | Liste fournisseurs |

## Déploiement Railway (recommandé)

1. **Sur GitHub** : créez un nouveau dépôt `mazpharma-api` et poussez tout le dossier `api-mazpharma/` dedans.
2. **Sur Railway** : ouvrez votre projet (le même qui héberge MySQL). Cliquez **+ New** → **GitHub repo** → sélectionnez `mazpharma-api`.
3. Railway détecte le projet PHP (grâce au `composer.json` + `Procfile`) et le build automatiquement.
4. Dans **Variables**, ajoutez :
   - `DB_HOST` = `mysql.railway.internal` *(connexion interne, ultra-rapide)*
   - `DB_PORT` = `3306`
   - `DB_USER` = `root`
   - `DB_PASSWORD` = votre mot de passe MySQL Railway
   - `DB_NAME` = `Stage2026`
5. Cliquez sur **Generate Domain** pour obtenir une URL publique (ex : `https://mazpharma-api-production.up.railway.app`).
6. Testez : `https://<votre-url>/produits.php` → doit retourner la liste JSON des 10 médicaments.

## Test en local (XAMPP / WAMP / Laragon)

1. Copiez tout le dossier dans `htdocs/api-mazpharma/`
2. Renommez `.env.example` en `.env` et complétez avec vos credentials Railway (proxy public `hopper.proxy.rlwy.net:41587`)
3. Démarrez Apache + ouvrez `http://localhost/api-mazpharma/test.html`

## Sécurité

- Mot de passe **jamais en dur** dans le code (lu depuis variables d'environnement)
- Toutes les requêtes utilisent des **prepared statements** (anti-injection SQL)
- Auth via token base64 (pour la démo) — passer à JWT signé en production
- Le mot de passe `demo123` est accepté en clair tant qu'il n'est pas re-hashé en BD
- En production : `ini_set('display_errors', '0')` + activer HTTPS strict

## Comptes de démo (mot de passe : `demo123`)

| Login | Rôle | Périmètre |
|---|---|---|
| `superadmin` | SUPERADMIN | Toutes les pharmacies |
| `a.kamal` | ADMIN | Pharmacie MAZ - Agadir Centre |
| `h.bensaid` | USER | Vente, BL, propositions BC |
| `s.alaoui` | USER | Vente, BL, propositions BC |
