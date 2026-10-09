# WebAnyOne Form Field Duplicator

Plugin open source pour dupliquer une question et ses descendants conditionnels dans l’éditeur de formulaire Mautic. Les copies sont des champs Mautic natifs, indépendants des sources. Les liaisons contact et entreprise sont retirées, sans création de champs CRM. Aucun accès à l’API REST, clé API, service externe, migration ou télémétrie.

Version 1.0.0 — licence GPL-3.0-or-later. Développé pour Mautic 6.x, cible vérifiée : **6.0.9**. Composer limite volontairement l’installation à `>=6.0.9 <6.1`, PHP `>=8.1 <8.5`. Les autres versions 6.0.x restent à vérifier ; Mautic 6.1+, 4, 5 et 7 ne sont pas déclarés compatibles. Consulter le [rapport de validation](docs/VALIDATION.md) livré pour les exécutions et leurs limites.

## Installation manuelle

Depuis la racine de votre installation Mautic :

```sh
unzip WebAnyOneFormFieldDuplicator-1.0.0.zip -d plugins/
php bin/console cache:clear --env=prod
php bin/console mautic:plugins:reload --env=prod
php bin/console mautic:assets:generate --env=prod
```

La structure doit être `plugins/WebAnyOneFormFieldDuplicatorBundle/WebAnyOneFormFieldDuplicatorBundle.php`, sans niveau ZIP supplémentaire. Utilisez le PHP et l’utilisateur système employés par Mautic. Ces trois commandes existent dans le tag 6.0.9 et ont été exécutées sur l’instance temporaire de validation. La génération d’assets exige les dépendances front-end Mautic déjà installées ; sur un checkout source, exécutez d’abord `npm ci`, selon le workflow du cœur.

Ouvrez **Paramètres → Plugins**, sélectionnez **WebAnyOne Form Field Duplicator** et publiez l’intégration. Aucun identifiant externe n’est demandé. Rechargez complètement l’administration pour charger son JavaScript.

## Installation Composer locale

Le paquet n’est pas publié sur Packagist. Il est installable depuis le répertoire source fourni. Exemple, en plaçant ce répertoire dans `/opt/mautic-local-plugins/WebAnyOneFormFieldDuplicatorBundle` :

```sh
composer config repositories.wao-field-duplicator path /opt/mautic-local-plugins/WebAnyOneFormFieldDuplicatorBundle
composer require webanyone/form-field-duplicator:1.0.0
php bin/console cache:clear --env=prod
php bin/console mautic:plugins:reload --env=prod
php bin/console mautic:assets:generate --env=prod
```

Le `type: mautic-plugin` et `extra.installer-name` placent le bundle dans `plugins/WebAnyOneFormFieldDuplicatorBundle`. Cela a été vérifié avec composer/installers 1.12.0, un dépôt local sans symlink et un contrat core-lib 6.0.9 dans un projet d’installation isolé. Le parcours complet Mautic a été validé avec l’installation manuelle. Ne combinez pas les deux modes dans la même installation.

Le checkout Git officiel du tag fixe son paquet path `mautic/core-lib` à `6.0.0-dev`, contrairement à une distribution versionnée 6.0.9. Sur ce checkout, utilisez l’installation manuelle ; n’abaissez pas la contrainte du plugin pour contourner cette différence.

## Utilisation

1. Créez un formulaire autonome ou de campagne, ou ouvrez un formulaire existant.
2. Validez ou fermez toute fenêtre de modification de champ ouverte.
3. Dans l’onglet **Champs**, cliquez sur l’icône de copie près de Modifier/Supprimer. Son infobulle et son nom accessible sont « Dupliquer ».
4. Le serveur copie le champ et tous ses descendants. La copie apparaît immédiatement après le groupe source et s’ouvre dans l’éditeur natif. Son libellé racine reçoit « — copie » ; les libellés des descendants restent identiques.
5. Modifiez la question et les autres paramètres. Vous pouvez rétablir des liaisons CRM dans cet éditeur, sous réserve des restrictions natives sur les champs déjà associés.
6. **Appliquer** ou **Enregistrer et fermer** persiste les copies. **Annuler** abandonne les copies et les modifications non enregistrées, conformément au brouillon natif.

Vous pouvez dupliquer un champ enregistré ou ajouté pendant cette session, même dans un formulaire neuf. Dupliquer un sous-champ copie ses descendants éventuels, garde son parent externe et ne copie pas ses frères. Les valeurs et expressions des conditions restent identiques ; les parents internes sont remappés.

Le bouton est accessible au clavier. Pendant la requête, les commandes de l’éditeur attendent sa fin ; un double clic ne provoque pas deux copies. Après une erreur, les boutons redeviennent utilisables. Une réponse réseau perdue peut être reprise grâce au même identifiant de requête. Le retour discret concernant le retrait des liaisons n’apparaît que si une liaison existait.

## Types pris en charge

| Champs natifs | Types techniques |
| --- | --- |
| Texte, paragraphe, nombre | `text`, `textarea`, `number` |
| Date et date/heure | `date`, `datetime` |
| Listes, radios, cases à cocher, pays | `select`, `radiogrp`, `checkboxgrp`, `country` |
| Cachés, descriptions, HTML | `hidden`, `freetext`, `freehtml` |
| Email, téléphone, URL, mot de passe | `email`, `tel`, `url`, `password` |
| Fichier, recherche d’entreprise | `file`, `companyLookup` |

Les propriétés natives sont copiées : choix, valeurs, ordre, sélection multiple, placeholder, défaut, aide, validation, obligation, résultats, présentation et comportements spécifiques. Pour `file`, seuls les paramètres du champ sont copiés, jamais les fichiers soumis. `companyLookup` conserve le fonctionnement natif de recherche, sans liaison mappedObject/mappedField. Le plugin ne modifie pas les paramètres globaux de suivi ou d’anonymat et n’impose aucun email.

Le bouton est absent pour `button`, `captcha`, `sociallogin`, `pagebreak` et les types inconnus. Si un descendant a un type exclu, le groupe entier est refusé. Les types ajoutés ou remplacés par d’autres plugins ne sont pas acceptés ; aucun adaptateur tiers n’est fourni dans cette version.

## Choix liés au CRM

Pour `syncList`, le plugin résout les choix effectifs avec le collecteur Mautic, y compris les champs contact/entreprise, listes select/multiselect/lookup, booléens et référentiels pays/région/fuseau/langue. La copie conserve une liste locale ordonnée et désactive la synchronisation. Une liste non résolue, des options groupées incompatibles avec le builder natif ou un contrat inconnu entraînent un refus atomique.

## Limites HTML et sessions

Les aliases sont uniques dans le brouillon et limités à 25 caractères après normalisation native. Les IDs HTML générés par Mautic deviennent distincts via ces aliases. Les attributs personnalisés valides pour le parser XML natif reçoivent de nouveaux `id`; les références exactes `for`, ARIA, `headers`, `list`, `href="#id"`, `data-target="#id"` sont ajustées lorsqu’elles visent un ID connu du groupe. Classes et styles sont conservés. Les attributs mal formés, IDs ambigus ou valeurs qui dépasseraient la longueur native sont refusés.

Le plugin ne remplace pas des chaînes dans le HTML libre, les scripts, `onclick`, CSS, sélecteurs complexes ou références externes. Des IDs écrits directement dans le contenu d’une zone HTML doivent être revus après duplication. Mautic peut remplacer les `id/name/for` fournis dans les attributs par ses propres valeurs natives. Les attributs des labels répétés de radios/cases peuvent aussi produire des répétitions selon le rendu natif. Utilisez les aliases natifs pour les automatisations personnalisées ; vérifiez vos références libres dans le formulaire public.

Mautic 6.0.9 partage le brouillon d’un formulaire existant entre onglets de la même session. Le plugin bloque la duplication depuis une ancienne ouverture après réouverture du builder ailleurs ; il ne change pas les autres opérations natives. Utilisez un seul onglet par formulaire enregistré. Les formulaires neufs ont des IDs de session distincts. Le fonctionnement concurrent requiert un handler de session verrouillant les requêtes, comme le handler PHP natif. Les 32 dernières requêtes de duplication bénéficient de la reprise idempotente.

Un subscriber local corrige aussi l’index en mémoire des nouveaux champs après le remappage natif des parents, afin que les conditions publiques fonctionnent dès la première sauvegarde. Il utilise l’événement natif `FORM_POST_SAVE` ; aucune modification du cœur ni écriture SQL directe.

## Mise à jour et désinstallation

Pour une installation manuelle, remplacez uniquement le répertoire du bundle par la nouvelle version, puis relancez les trois commandes d’installation. Pour Composer, mettez à jour le dépôt source local puis exécutez `composer update webanyone/form-field-duplicator` et les mêmes commandes. Fermez/réouvrez les éditeurs après mise à jour.

Pour désactiver, dépubliez l’intégration puis rechargez complètement l’administration : le bouton et la déclaration d’asset disparaissent. Les champs enregistrés continuent à fonctionner.

Pour désinstaller manuellement, retirez `plugins/WebAnyOneFormFieldDuplicatorBundle`, puis exécutez `cache:clear`, `mautic:plugins:reload` et `mautic:assets:generate`. Pour Composer, utilisez `composer remove webanyone/form-field-duplicator`, puis les mêmes commandes. Aucun champ, alias, résultat historique, soumission ou action du formulaire n’est supprimé. Le code de copie ne sera simplement plus disponible.

## Tests et dépannage

Dans un checkout de test Mautic 6.0.9 disposant de ses dépendances de développement :

```sh
php bin/phpunit -c plugins/WebAnyOneFormFieldDuplicatorBundle/phpunit.xml.dist
```

Les tests fonctionnels héritent de `MauticMysqlTestCase` : **ils recréent et réinitialisent la base désignée par DB_NAME**. Utilisez une base dédiée, jamais la production. Configurez `.env.test.local` avec DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWD selon votre environnement. Pour les seuls tests unitaires :

```sh
php bin/phpunit -c plugins/WebAnyOneFormFieldDuplicatorBundle/phpunit.xml.dist plugins/WebAnyOneFormFieldDuplicatorBundle/Tests/Unit
```

Si le bouton manque, vérifiez la publication de l’intégration, le rechargement complet de la page, les permissions et le chargement de `plugins/WebAnyOneFormFieldDuplicatorBundle/Assets/library/js/duplicator.js`. Vérifiez aussi que le champ n’est pas exclu. En sous-répertoire, les URLs proviennent du routeur et du helper d’assets natifs.

Un message « brouillon plus actif » signifie généralement qu’un autre onglet a rouvert le formulaire ou qu’un enregistrement/une annulation a terminé ce brouillon. Un groupe refusé indique un type exclu, un parent manquant/cyclique, une liste CRM non résolue ou des attributs HTML incompatibles. Corrigez la cause dans l’éditeur natif. Une erreur générique peut venir du rendu Twig ou de la session ; les copies ne sont ajoutées qu’après rendu réussi.
