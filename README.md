# gitpull — socle pour GLPI 12.x

Version 0.1.1 · GPL-3.0-or-later · PHP et extensions requis par GLPI 12, Linux,
Git, proc_open, POSIX, DNS, Bash/PHP pour les scripts.

## Arborescence

```
gitpull/
├── setup.php                    Métadonnées, prérequis, menu
├── hook.php                     Installation du cron, désinstallation non destructive
├── src/
│   ├── AtomicJson.php           Lecture stricte et remplacement atomique
│   ├── Manifest.php             Validation, édition et contrôle de concurrence
│   ├── Paths.php                Emplacements et restrictions des destinations
│   ├── StateStore.php           Cache par configuration, verrou par cible
│   ├── Process.php              proc_open sans shell, timeout, sortie bornée
│   ├── RepositoryManager.php    Clone, fetch, pull/reset et post-pull
│   ├── Scheduler.php            Cron natif GLPI et échéances par dépôt
│   └── Web.php                  Droits, protection POST, rendu Twig
├── front/
│   ├── dashboard.php
│   └── config.php
├── templates/
│   ├── dashboard.html.twig
│   └── config.html.twig
├── public/js/dashboard.js
├── bin/bootstrap.php            Initialisation Docker explicite
├── tests/storage.php            Tests autonomes du stockage
├── tests/linux.php              Validation du manifeste et des chemins sous Linux
└── gitpull_repos.example.json
```

## Installation et démarrage Docker

1. Copier ce dossier sous GLPI_ROOT/plugins/gitpull (pas un dossier imbriqué).
   Le code de gitpull doit déjà être présent dans l'image ou un volume.
2. Installer GLPI et sa base de données selon la procédure de votre image.
3. Placer le manifeste dans GLPI_CONFIG_DIR/gitpull_repos.json.
   Adapter les URL et chemins de l'exemple : ce sont des valeurs illustratives.
4. Donner à www-data l'accès en écriture aux racines de déploiement, au cache et
   au dossier contenant le manifeste. Le remplacement atomique crée un fichier
   temporaire puis le renomme : un simple bind-mount du fichier JSON en lecture
   seule, ou un montage individuel empêchant rename(), ne permet pas l'édition UI.
   Monter le dossier de configuration approprié. Ne pas utiliser chmod 777.
5. Exécuter le bootstrap après l'initialisation de GLPI, avant de servir le trafic.
   Le processus PHP doit réellement avoir l'UID www-data ; le plugin ne fait ni
   sudo ni changement d'utilisateur. Exemple avec l'outil de l'image :

```sh
# Depuis l'hôte Docker ; adapter le nom et le chemin d'installation
docker exec --user www-data glpi php /var/www/glpi/plugins/gitpull/bin/bootstrap.php
```

Pour l'automatiser, placer l'équivalent dans le point d'entrée de l'image, après
l'installation de GLPI et avant le lancement du serveur, en utilisant l'outil de
changement d'utilisateur disponible dans l'image (su-exec, gosu, runuser…).
Ne pas remplacer aveuglément l'entrypoint du fournisseur. Le bootstrap retourne
0 si les dépôts absents ont été initialisés, 1 en cas d'échec ou de verrou occupé.

6. Installer et activer gitpull dans Configuration > Plugins. L'installation
   enregistre l'action automatique Sync en mode CLI uniquement.
7. Dans le crontab de www-data, déclencher GLPI toutes les minutes :

```cron
* * * * * /usr/bin/php /var/www/glpi/front/cron.php
```

Si un ordonnanceur est déjà fourni par l'image, le conserver et vérifier son UID.
Activer Sync et vérifier sa prochaine exécution dans les Actions automatiques.
Le menu Configuration > gitpull donne accès au tableau de bord.

**Périmètre de déploiement :** le bootstrap clone les sources et lance le script
configuré. Il n'installe ni n'active automatiquement les autres plugins dans SQL.
Ces étapes propres à GLPI peuvent être faites dans un script administrateur
idempotent, adapté aux commandes de votre installation. Un conteneur neuf dont
GLPI n'est pas encore installé ne peut pas initialiser son noyau : la phase
d'installation de GLPI doit précéder le bootstrap.

## Manifeste : configuration uniquement

```json
{
  "version": 1,
  "repositories": [
    {
      "id": "monplugin",
      "url": "https://github.com/organisation/monplugin.git",
      "path": "/var/www/glpi/plugins/monplugin",
      "post_pull": {"type": "bash", "path": "/opt/gitpull-scripts/monplugin.sh"},
      "hard_pull": false,
      "auto_check": true,
      "auto_pull": false,
      "check_interval": 5,
      "pull_interval": 30
    }
  ]
}
```

Tous les champs sont obligatoires ; post_pull vaut null si absent.
id est stable et unique, les chemins sont uniques. Les fréquences sont des
entiers de 1 à 525600 minutes. Aucun statut, journal, timestamp ou compteur
n'est accepté dans le manifeste. Les champs inconnus entraînent une erreur
explicite : un manifeste invalide n'est jamais silencieusement remplacé.

L'UI enregistre directement dans ce fichier, après verrouillage d'un fichier
latéral .lock et contrôle de révision SHA-256. Un conflit entre deux éditions
demande un rechargement ; aucune mise à jour concurrente n'est écrasée.
Un éditeur externe doit lui aussi publier le manifeste par remplacement atomique
et coordonner ses écritures avec ce verrou.

Par défaut les cibles sont des enfants directs du chemin réel GLPI_ROOT/plugins.
Pour plusieurs racines existantes, définir dans PHP-FPM ET dans le cron :

```text
GITPULL_ALLOWED_ROOTS=/var/www/glpi/plugins:/var/www/glpi/marketplace
```

Les liens symboliques dans le chemin cible, les chemins relatifs, les sous-dossiers
imbriqués, les doublons et l'auto-remplacement de gitpull sont refusés.
Les racines doivent être existantes et accessibles en écriture à www-data.
Ne pas mélanger marketplace automatique et gitpull pour un même plugin.

## État et ordonnancement

Le cache se trouve sous GLPI_VAR_DIR/_plugins/gitpull/<empreinte-config>.json.
Il contient les états ready/update_available/checking/pulling/error, les dates
UTC sous forme de timestamps Unix, les dernières tentatives, la dernière erreur,
les 32 Kio de sortie récents et les 20 derniers événements. Twig affiche les dates
dans le fuseau configuré par GLPI. Le tableau de bord lit ce cache ; seul le
formulaire de configuration modifie le manifeste.

Le cache est privé (dossier 0700, fichiers 0600). Il doit se situer hors de toute
exposition HTTP conformément à l'installation GLPI. Un cache effacé recrée
l'état aux prochains passages. Les clones déjà présents restent intacts.
Les anciens fichiers liés à des configurations supprimées peuvent être purgés
lorsque les workers sont arrêtés ; aucun nettoyage ne doit supprimer des verrous
pendant une exécution. Ne pas partager ces fichiers entre réplicas concurrents
sans stockage et flock cohérents ; cette base vise une instance GLPI active.

À chaque passage :
- tout dépôt absent est cloné, même si les deux cases auto sont désactivées ;
- un échec attend la fréquence pull avant une nouvelle tentative ;
- un pull automatique dû est prioritaire et comprend une vérification ;
- sinon une vérification due lance fetch, sans modifier l'arbre de travail.

Les délais sont mesurés depuis la dernière tentative pour éviter une boucle
rapide en cas d'échec. Les dernières réussites restent distinctes. Un cache
absent rend immédiatement exigibles les actions auto. Les compteurs sont
calculés côté navigateur ; l'échéance dépend du passage réel du cron et de la
durée des dépôts précédents. Les états se mettent à jour au rechargement.

Les actions rapides exécutent check/pull dans la requête HTTP sous www-data ;
les automatisations passent par le cron en arrière-plan. Adapter le timeout
PHP-FPM/proxy si ces actions manuelles durent longtemps. Les verrous empêchent
deux opérations simultanées sur la même cible. Une exécution interrompue est
détectée au tableau de bord lorsque son verrou est libre.

## Git, scripts et erreurs

- Dépôts publics HTTPS uniquement, sans identifiants, token, clé ou prompt.
  Pas de sous-modules ni de redirections HTTP. Les DNS privés/réservés sont rejetés.
  Appliquer aussi une politique réseau sortante au conteneur : la validation DNS
  seule ne garantit pas la protection contre un changement DNS entre deux accès.
- Clone dans un dossier temporaire voisin, publication par rename après succès.
  Un clone échoué peut laisser un dossier .gitpull-* à inspecter et supprimer
  manuellement, sans toucher à la cible.
- Un dossier préexistant non Git ou un origin différent provoque une erreur.
  Une branche locale suivant origin est nécessaire ; pas de HEAD détachée.
  Aucune adoption forcée d'un dossier existant.
- Check : fetch --prune puis comptage des commits distants absents de HEAD.
  Une branche locale seulement en avance ne déclenche pas une fausse mise à jour.
- Pull normal : git pull --ff-only --no-rebase. Conflits, divergence et modifications
  locales bloquantes sont affichés en erreur.
- Hard Pull : fetch puis git reset --hard vers le commit distant. Les modifications
  locales des fichiers suivis sont perdues. Aucun git clean n'est lancé.
- Script : chemin absolu vers un fichier Bash/PHP lisible, passé comme argument
  à /bin/bash ou /usr/bin/php, répertoire courant = dépôt. Pas de commande libre
  concaténée. Exécuté après clone et après chaque pull réussi, même sans nouveau
  commit ; écrire des scripts idempotents.
- Un échec du script garde l'opération en erreur et ne met pas à jour la date du
  dernier pull réussi, même si Git a déjà changé les fichiers. Un nouveau pull
  rejoue le script. Le bootstrap ne rejoue pas les scripts des cibles existantes :
  utiliser Pull maintenant ou activer le pull auto après correction.
- Timeout par commande : 120 secondes, script : 300 secondes. Un script qui lance
  des processus détachés doit gérer leur arrêt ; le timeout couvre le processus
  direct. Prévoir une supervision Docker pour les groupes de processus complexes.

Les commandes utilisent proc_open avec un tableau d'arguments, sans shell.
L'environnement Git global/système est neutralisé, les helpers d'identifiants
et hooks Git sont désactivés. Les scripts reçoivent un environnement minimal
sans secrets hérités. Les exécutables attendus sont /usr/bin/git, /usr/bin/php
et /bin/bash. Les erreurs de permissions, réseau, Git et scripts sont dans le
cache et l'UI. Le cron journalise également ses erreurs globales.

L'accès UI exige le droit GLPI config/UPDATE et l'activation du plugin.
Les POST utilisent un jeton propre au plugin, en complément de la protection
native de GLPI 12. Toutes les valeurs Twig sont échappées. Configurer un dépôt
ou un script autorise l'exécution de code sous www-data : réserver ce droit aux
administrateurs de confiance, ainsi que l'écriture des manifestes et scripts.

## Vérifications

```sh
find /var/www/glpi/plugins/gitpull -name '*.php' -exec php -l {} \;
php /var/www/glpi/plugins/gitpull/tests/storage.php
# Dans le conteneur sous www-data
php /var/www/glpi/plugins/gitpull/tests/linux.php
```

Les tests autonomes couvrent la séparation configuration/état, les écritures
atomiques, le verrouillage, les conflits de révision, le JSON corrompu, la
validation stricte et les chemins interdits. Ils n'exigent aucune instance GLPI.
Pour la validation d'intégration GLPI 12 : installer/activer, tester un clone
public réel, un dossier non accessible, un manifeste invalide, une divergence,
un hard pull, un script en échec et le passage du cron sans session navigateur.
Vérifier qu'un check/pull ne modifie jamais le hash du manifeste.

Cette livraison est un socle initial ; la compatibilité d'exécution complète
doit être validée dans votre image GLPI 12 avec sa base et son serveur web.

## Références

- [Architecture des plugins](https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/objects.html)
- [Tutoriel et actions automatiques](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)
- [Cron CLI](https://help.glpi-project.org/documentation/modules/configuration/crontasks)
- [Sources GLPI 12.0.0](https://github.com/glpi-project/glpi/tree/12.0.0)

La commande CLI initialise explicitement le Kernel GLPI 12. Les pages web sont
initialisées par le routeur GLPI ; elles n'incluent pas l'ancien inc/includes.php.
Les ressources JavaScript résident sous public/ et les URL sont construites sans
Plugin::getWebDir, supprimé des sources GLPI 12.

## Diagnostic du manifeste (0.1.1)

Un bandeau en haut du tableau de bord et de la configuration affiche le chemin
GLPI_CONFIG_DIR/gitpull_repos.json réellement utilisé, son chemin résolu, sa
présence, ses droits de lecture/écriture, les droits du dossier et du verrou,
l'utilisateur PHP, sa date de modification et le nombre de dépôts déclarés.

Le journal général est GLPI_VAR_DIR/_plugins/gitpull/diagnostics.json :
50 événements maximum, erreurs de lecture/enregistrement/cron/bootstrap,
résultats des opérations Git. Les sorties détaillées Git/scripts restent dans
le cache de chaque dépôt. Aucune donnée de diagnostic n'est écrite dans le
manifeste. En cas d'impossibilité d'écrire le journal, consulter les erreurs PHP.

L'interface affiche maintenant les dépôts dont la cible est refusée, avec le
motif et les boutons d'exécution désactivés. Leur configuration reste accessible
pour correction. Le cron conserve la validation stricte du manifeste avant
exécution. Un JSON syntaxiquement invalide ne peut pas être édité via le
formulaire : corriger d'abord le fichier, sans l'écraser automatiquement.

Un message de réussite confirme l'enregistrement après redirection. Les erreurs
incluent le chemin du fichier/dossier/verrou concerné et sont journalisées.

Attention à l'exemple fourni lors du diagnostic :
- une entrée visant le dossier d'installation de gitpull est refusée, car cette
  base ne permet pas l'auto-remplacement du plugin ;
- un caractère % effectivement présent après la dernière accolade invalide le
  JSON. Un % affiché uniquement par le terminal ne fait pas partie du fichier ;
- un fichier présent ailleurs que le chemin affiché ne sera pas chargé.

Mise à jour depuis 0.1.0 : remplacer le dossier du code avec celui du ZIP 0.1.1
en conservant le manifeste externe et le cache. Pas de migration SQL ajoutée.
Si OPcache conserve les anciens fichiers, recharger PHP-FPM selon votre image.
