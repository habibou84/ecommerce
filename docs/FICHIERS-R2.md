# Fichiers sur Cloudflare R2 et Cloudflare en mode proxy

Deux réglages indépendants, à faire dans cet ordre. Ils préparent l'arrivée d'un second serveur web : les fichiers
ne sont plus sur le disque d'un serveur, et Cloudflare protège et met en cache ce qui peut l'être.

| | Avant | Après |
|---|---|---|
| Photos du catalogue, logos, images de partage, aide | disque du serveur (`storage/app/public`) | espace R2 **public**, servi par `medias.jibiat-marchand.com` |
| Preuves de livraison, pièces d'identité, reçus, photos reçues sur WhatsApp | disque du serveur (`storage/app/private`) | espace R2 **privé**, servi seulement par l'application |
| Trafic | directement sur le serveur | par Cloudflare (adresse du serveur masquée, protection, cache des fichiers fixes) |

Rien ne change dans la base : les chemins des fichiers restent les mêmes, seul le disque change.

Restent sur le serveur pour l'instant : les exports de données (temporaires, 7 jours), les sessions du WhatsApp relié
par QR code (`storage/app/whatsapp-bridge`) et les journaux.

## 1. Fichiers sur R2

### 1.1 Les deux espaces (Cloudflare › R2)

1. **Create bucket** : `jibiat-marchand-medias` (public). Puis **Settings › Custom Domains › Connect Domain** :
   `medias.jibiat-marchand.com`. Cloudflare crée l'enregistrement DNS et le certificat.
2. **Create bucket** : `jibiat-marchand-fichiers` (privé : ne rien ouvrir au public).
3. **R2 › Manage API Tokens › Create API Token** : permission **Object Read & Write**, limitée à ces deux espaces.
   Noter l'**Access Key ID**, le **Secret Access Key** et l'adresse **S3** du compte
   (`https://<identifiant>.r2.cloudflarestorage.com`).

La préproduction a ses propres espaces (`…-preprod-medias`, `…-preprod-fichiers`, domaine
`medias-preprod.jibiat-marchand.com`) : ne jamais partager les espaces de la production.

Ces espaces sont différents de celui des sauvegardes (`BACKUP_S3_*`), qui reste à part.

### 1.2 Réglages (`.env`), sans encore changer de disque

```
R2_ACCESS_KEY_ID=…
R2_SECRET_ACCESS_KEY=…
R2_ENDPOINT=https://<identifiant>.r2.cloudflarestorage.com
R2_PUBLIC_BUCKET=jibiat-marchand-medias
R2_PUBLIC_URL=https://medias.jibiat-marchand.com
R2_PRIVATE_BUCKET=jibiat-marchand-fichiers
```

Puis `sudo -u <utilisateur> php artisan optimize`.

### 1.3 Copie des fichiers existants

```bash
cd /var/www/jibiat-production
sudo -u jibiat_production php artisan files:copy public r2 --dry-run    # compte, sans rien copier
sudo -u jibiat_production php artisan files:copy public r2
sudo -u jibiat_production php artisan files:copy local r2-private --except=exports
```

La commande vérifie d'abord qu'elle peut écrire sur R2, garde les mêmes chemins et peut être relancée : ce qui est
déjà copié (même taille) est passé. Elle se termine par un résumé ; « 0 en erreur » est attendu.

### 1.4 Bascule

Dans le `.env` :

```
CATALOG_DISK=r2
PRIVATE_DISK=r2-private
```

Puis relancer `files:copy` une dernière fois (fichiers ajoutés pendant la copie), `php artisan optimize`, et
redémarrer la file : `supervisorctl restart jibiat-production-queue: jibiat-production-queue-long`.

Vérifications : une fiche produit de boutique affiche ses photos depuis `medias.jibiat-marchand.com` ; une preuve de
livraison s'ouvre dans la gestion ; une photo ajoutée au catalogue apparaît.

Retour en arrière : remettre `CATALOG_DISK=public` et `PRIVATE_DISK=local`. Les fichiers du serveur n'ont pas été
effacés ; ceux ajoutés depuis la bascule sont sur R2 (`files:copy r2 public` et `files:copy r2-private local`).
Une fois la bascule confirmée depuis quelques semaines, `storage/app/public` et `storage/app/private` peuvent être
vidés (sauf `exports`).

### 1.5 Sauvegardes

La sauvegarde de la nuit copie `storage/app` : après la bascule, elle ne contient plus les photos ni les documents.
R2 garde les fichiers sur plusieurs centres de données, mais ne protège pas d'un effacement par erreur. À prévoir :
une copie régulière des deux espaces vers celui des sauvegardes (rclone, déjà installé par `backup.sh`).

## 2. Cloudflare en mode proxy (production)

### 2.1 Avant de passer au nuage orange

Dans le `.env` de la production : `TRUSTED_PROXIES=cloudflare`, puis `php artisan optimize`. Sans ce réglage, toutes
les requêtes semblent venir de Cloudflare : les limites de tentatives (connexion, codes SMS) seraient partagées par
tous les visiteurs.

### 2.2 Cloudflare › jibiat-marchand.com

1. **SSL/TLS › Overview** : **Full (strict)**. Le certificat Let's Encrypt du serveur reste valable derrière Cloudflare
   (il est renouvelé par validation DNS, sans passer par le site).
2. **DNS › Records** : passer `@` et `*` en **Proxied** (nuage orange).
3. **Caching** : rien à changer. Cloudflare met en cache les fichiers fixes (`/build`, `/storage`, images) selon les
   en-têtes envoyés par le serveur, pas les pages ni l'API.

Vérifications : `https://jibiat-marchand.com/up` et une boutique (`https://<boutique>.jibiat-marchand.com`) répondent ;
l'en-tête `cf-ray` est présent (`curl -sI https://jibiat-marchand.com | grep -i cf-ray`) ; la connexion par code SMS
fonctionne.

### 2.3 Ce qui ne change pas

- **Préproduction** : `preprod` et `*.preprod` restent en **DNS only**. Le certificat gratuit de Cloudflare ne couvre
  qu'un niveau de sous-domaine (`*.jibiat-marchand.com`), pas `boutique.preprod.jibiat-marchand.com`.
- **Domaines personnalisés des boutiques** : ils pointent toujours vers l'adresse du serveur, sans Cloudflare.
- **Webhooks** (Jibiat, Meta, paiements) : mêmes adresses.
