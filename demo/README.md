# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images). Elle affiche les URLs de la démo.
3. Dans l'onglet **Ports**, ouvre « Rocket Auth » (3000), « Démo CRM » (4000), « Documentation et changelog » (3001) ou « Mailpit » (8025). Depuis Rocket Auth, `/docs` et `/changelog` y mènent aussi.

Les ports 3000 et 4000 sont rendus publics automatiquement : la page Démo CRM charge le composeur depuis le port 3000. Si ça échoue, passe-les en *Public* (clic droit → *Port Visibility*).

> ⚠️ Un port public est accessible à toute personne qui a l'URL, et les mots de passe de démo sont publics. Arrête le codespace quand tu as fini (menu Codespaces → *Stop codespace*). Le quota gratuit de GitHub est limité en heures par mois.

Pour relancer la démo à la main : `bash demo/codespaces/start.sh`.

## En local

Pré-requis : Docker avec Compose v2.24 ou plus récent.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

Le premier démarrage prend quelques minutes (build des images). Le service `demo-seed` prépare la base, charge les données de démo et synchronise l'annuaire LDAP, puis s'arrête. Pour suivre sa progression : `docker compose -f compose.yaml -f compose.demo.yaml logs -f demo-seed`.

| Adresse | Contenu |
|---|---|
| http://localhost:3000 | Rocket Auth |
| http://localhost:4000 | « Démo CRM », une application tierce qui embarque le composeur (widget JavaScript, et web component sur `/web-component`) |
| http://localhost:3001 | Documentation, et le changelog sur `/changelog` |
| http://localhost:8025 | Mailpit : tous les emails envoyés arrivent ici, rien ne part vraiment |
| http://localhost:8000/api/docs | Documentation de l'API |

## Comptes

| Compte | Mot de passe | Type |
|---|---|---|
| `admin@example.org` | `demo-admin-password` | local, administrateur |
| `alice@example.org` | `demo-alice-password` | local |
| `marie.martin@example.org` | `password` | LDAP, admin via le groupe `mailer-admins` |
| `jean.dupont@example.org` | `password` | LDAP |

## Scénarios à tester

1. **Composer un email.** Connecte-toi avec `alice@example.org`, ouvre *Nouveau message*, puis *Importer un template* → « Bienvenue ». Modifie le texte et envoie. Le message apparaît dans *Envoyés* et dans Mailpit.
2. **Créer un template.** Ouvre *Templates* → *Nouveau template*. Construis un email dans GrapesJS, enregistre, modifie et enregistre à nouveau. *Historique* permet ensuite de restaurer une version précédente.
3. **Connexion LDAP.** Connecte-toi avec `marie.martin@example.org` / `password` : elle est administratrice grâce à son groupe LDAP. Dans *Utilisateurs*, *Synchroniser LDAP* relance la synchronisation.
4. **Application tierce et impersonation.**
   - Ouvre http://localhost:4000 et choisis l'utilisateur du CRM dans la liste : le composeur envoie en son nom.
   - « Écrire à ce client » pré-remplit le destinataire et l'objet depuis le CRM ; les envois remontent dans le journal d'événements.
   - En choisissant `admin@example.org`, le widget n'obtient pas pour autant les droits administrateur.
5. **Sécurité de l'intégration.**
   - En admin, dans *Applications*, désactive « Démo CRM » : le widget de la page http://localhost:4000 cesse de fonctionner.
   - Retire `http://localhost:4000` des origines autorisées : le navigateur refuse d'afficher l'iframe.

## Réinitialiser

```bash
docker compose -f compose.yaml -f compose.demo.yaml down -v
```

> Cette démo utilise des mots de passe et un jeton d'application publics (`compose.demo.yaml`). Ne l'expose jamais sur Internet.
