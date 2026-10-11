# Rapport de validation — version 1.0.0

Exécuté le **8 octobre 2026** sur une instance temporaire construite depuis le tag officiel **Mautic 6.0.9**, sans modification du code du cœur. Aucune instance de production n’a été utilisée.

## Environnement et résultat

| Contrôle | Environnement | Résultat exécuté |
| --- | --- | --- |
| Suite PHPUnit complète | PHP 8.1.31, PHPUnit 9.6.34, MySQL 5.7.44 | **34 tests, 320 assertions, aucune erreur** |
| Même suite | PHP 8.3.14, PHPUnit 9.6.34, MySQL 5.7.44 | **34 tests, 320 assertions, aucune erreur** |
| Syntaxe PHP | PHP 8.1.31 et 8.3.14, 18 fichiers PHP | Réussie |
| Syntaxe JavaScript | `node --check` | Réussie |
| Parcours navigateur | Chromium via Playwright, serveur PHP 8.1, environnement prod | Réussi à la racine et sous `/mautic` |
| Installation Composer locale | composer/installers 1.12.0, dépôt path sans symlink | Bundle installé dans le répertoire attendu |
| Métadonnées Composer | `composer validate --no-check-publish` | Valides ; avertissement informatif sur la version explicite du dépôt local |
| Installation du ZIP et activation par Entrée | Bundle extrait dans l’instance de test, racine et sous-répertoire | Duplication serveur réussie, annulation native |
| Cache, découverte, assets | Trois commandes natives en environnement prod | Exécutées, codes de sortie 0 |
| Désinstallation | Retrait physique du bundle, commandes natives, nouveau chargement navigateur | Réussie ; réponses historiques conservées |

Les bases des tests PHPUnit et du navigateur étaient distinctes. Les tests fonctionnels utilisent `MauticMysqlTestCase`, qui recrée la base désignée ; ils doivent être exécutés exclusivement sur une base dédiée.

## Ce que couvrent les tests livrés

Les **28 cas unitaires** couvrent la copie indépendante des propriétés et validations, les choix et leur ordre, les associations CRM du groupe, les choix contact/entreprise et booléens avec la valeur zéro, les aliases et collisions de brouillon, les identifiants HTML et références exactes, les descendants imbriqués, parents externes, frères exclus, champs supprimés, ordre natif non contigu, cycles, parents invalides, types exclus/inconnus/remplacés. Les contrôles create/editown/editother, propriétaire, verrou, CSRF, brouillon périmé et appartenance sont vérifiés avec les interfaces natives simulées.

Les **6 cas fonctionnels**, avec noyau et base réels, couvrent :

- Formulaire autonome et formulaire de campagne : soumission historique, duplication d’une liste et de son enfant conditionnel, absence de persistance avant Save, sauvegarde native, réouverture, nouveaux IDs et parents persistants, alias distincts, conditions présentes dès la première génération publique. Les nouvelles réponses source/copie sont différentes ; chaque réponse historique et son ID restent inchangés. La dépublication de l’intégration retire la configuration de l’éditeur et permet encore de soumettre le formulaire.
- Formulaire neuf : création d’un champ via la fenêtre native, duplication du champ non enregistré, reprise de la même requête sans deuxième copie, annulation native sans aucun formulaire/champ persisté, refus d’une requête sur le brouillon annulé.
- Requêtes authentifiées : CSRF du plugin invalide (403), source absente (404), bouton de soumission exclu (422), ancienne ouverture après réouverture du builder (409), sans copies persistées.
- Collecteurs CRM réels : création de listes contact et entreprise dans la base de test via le modèle natif ; duplication du groupe synchronisé ; matérialisation des libellés, valeurs et ordre ; `syncList=false`, associations nulles sur les copies et source intacte. Les listes copiées sont rendues dans le formulaire public après sauvegarde.
- Les **18 types autorisés** : création native, appel serveur de duplication de chacun, rendu des copies, sauvegarde de 37 champs incluant le bouton natif, 37 aliases uniques, réouverture et rendu public. Ce contrôle vérifie la reconstruction de chaque type, sans prétendre tester tous ses comportements métier.

Les tests fonctionnels ont révélé l’indexation temporaire des nouveaux champs dans la collection native après sauvegarde. Le subscriber local décrit dans [DIAGNOSTIC.md](DIAGNOSTIC.md) corrige cet index par événement natif. La régression vérifie immédiatement `data-mautic-form-show-on="rating_copy:1"`, puis une vraie soumission avec valeurs distinctes.

## Parcours Chromium exécuté

Deux formulaires préparés avec les modèles natifs, chacun avec une liste, un enfant texte conditionné par la valeur 1 et un bouton de soumission : autonome à la racine, campagne sous `/mautic`. Le sous-répertoire a été servi par un routeur local configurant le préfixe de script ; il s’agit de la même installation Mautic, pas d’un second déploiement Apache/Nginx.

Pour chacun :

1. Ouverture de l’onglet Fields ; deux boutons Duplicate seulement, aucun sur le bouton de soumission.
2. Deux clics rapides et un événement double clic sur la même commande : **une seule requête**, un groupe copié et ouverture de l’éditeur natif.
3. Nouvelle tentative pendant la fenêtre ouverte : message demandant de valider/fermer, aucune requête ni copie supplémentaire.
4. Changement du libellé en « Confort de la salle » et validation avec la commande visible de la fenêtre native.
5. Réponse HTTP 500 simulée dans le navigateur : message d’erreur affiché, bouton réactivé, nombre de champs inchangé ; nouvelle tentative réussie.
6. Deuxième duplication, fermeture de la fenêtre, Save natif, réouverture : sept champs, libellé modifié retrouvé.
7. Cancel natif, absence des boutons hors builder, ouverture AJAX depuis la liste : une seule commande par champ autorisé.
8. Formulaire public : source=2, copie=1, deuxième copie=2 ; seul l’enfant de la première copie devient visible. Soumission réussie avec réponse texte indépendante ; vérification des valeurs stockées via `SubmissionRepository::getEntity`.

Aucune erreur JavaScript non interceptée pendant ces parcours. Les événements rapides ont été déclenchés dans Chromium ; le test ne simule pas une perte de réponse *après* commit serveur. La reprise idempotente après commit est testée par deux POST réels ayant le même requestId dans la suite fonctionnelle.

Après retrait physique du plugin et exécution des commandes natives, Chromium a vérifié pour les deux URL : absence de configuration/boutons/script du plugin, sept champs natifs toujours présents, nouveau formulaire public fonctionnel et seconde soumission enregistrée. Les résultats de la première soumission ont été comparés par ID et sont restés identiques.

## Installation et preuves

Le parcours complet a utilisé le bundle dans `plugins/`, son autowiring natif et sa découverte via `mautic:plugins:reload`. Les commandes `cache:clear --env=prod`, `mautic:plugins:reload --env=prod`, `mautic:assets:generate --env=prod` ont été exécutées. Les dépendances front-end du checkout ont été installées avec `npm ci` avant génération.

Le contrôle Composer utilise un projet isolé déclarant un **metapackage core-lib 6.0.9** pour vérifier le contrat de dépendance et composer/installers. Il ne constitue pas un test d’installation Composer d’une distribution Mautic complète. Le tag source officiel annonce core-lib 6.0.0-dev via son dépôt path ; la procédure manuelle est documentée pour ce cas. Le paquet n’a pas été publié sur Packagist.

Les sorties finales et deux captures de l’éditeur sont dans [preuves/](preuves/) : `phpunit-php81.txt`, `phpunit-php83.txt`, `chromium.txt`, `chromium-desinstallation.txt`, `desinstallation-commandes.txt`, `desinstallation-integrite.txt`, `editeur-racine.png`, `editeur-sous-repertoire.png`. `installation-zip-commandes.txt` et `installation-zip-clavier.txt` confirment l’installation depuis l’archive et l’activation de la commande par la touche Entrée. `coeur-integrite.txt` confirme la comparaison des 3 860 fichiers PHP originaux du tag, sans modification. Elles ne contiennent ni identifiant de connexion, secret, cookie ni donnée de contact réelle.

## Limites précises de la validation

- Version Mautic exécutée : **6.0.9 uniquement**. Autres 6.0.x, 6.1+ et PHP 8.2/8.4 non exécutés ; aucune certification de toute la série 6.x.
- MySQL 5.7.44 uniquement ; MariaDB et MySQL 8 non validés pour cette livraison.
- Chromium uniquement ; Firefox, Safari, lecteur d’écran et navigation entièrement au clavier non exécutés. L’activation du bouton par Entrée a été exécutée à la racine et en sous-répertoire après installation du ZIP. Les commandes sont des boutons natifs avec nom accessible et infobulle traduits ; les traductions françaises/anglaises sont livrées, le parcours Chromium utilisait l’interface anglaise.
- Le rendu, la sauvegarde et la réouverture des 18 types sont exécutés ; téléversement réel de fichier, recherche d’entreprise avec données CRM, toutes les variantes de validation et tous les référentiels CRM ne sont pas testés en navigateur.
- Les refus de permissions/propriété/verrou sont unitaires avec services natifs simulés ; aucun parcours navigateur avec plusieurs comptes et rôles n’a été exécuté.
- Les tests imbriqués et dépendances externes sont unitaires ; le parcours public utilise un groupe parent/enfant d’un niveau.
- Les propriétés de liste sont indépendantes en tests ; aucune campagne automatisée ni action externe n’a été exécutée. Le plugin ne modifie pas les actions du formulaire.
- Handler PHP local verrouillant la session ; Redis et requêtes réellement concurrentes non exécutés. Le brouillon natif reste partagé entre onglets du même formulaire enregistré.
- HTML/JavaScript libre : aucune réécriture arbitraire ; IDs et références internes doivent être revus lorsque leur relation ne peut être déterminée. Types tiers et remplacements des types natifs refusés.
- Aucun déploiement dans votre Mautic réel n’a été effectué. Le ZIP est prêt à être installé selon le README.

## Complément du 11 octobre 2026

La préparation de release a réexécuté les 34 tests / 320 assertions sur PHP 8.1.31, **8.2.26** et 8.3.14 avec un nouveau checkout jetable. La limite PHP 8.2 ci-dessus décrit la recette initiale du 8 octobre ; voir [RELEASE_VALIDATION.md](RELEASE_VALIDATION.md) pour les nouveaux résultats et les contrôles de publication. PHP 8.4 reste non exécuté.
