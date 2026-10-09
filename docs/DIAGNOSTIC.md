# Diagnostic technique — Mautic 6.0.9

Sources examinées : archive du tag [6.0.9](https://github.com/mautic/mautic/tree/6.0.9), téléchargée le 8 octobre 2026. L’[issue 2247](https://github.com/mautic/mautic/issues/2247) décrit le besoin ; elle n’a pas été utilisée comme preuve d’une implémentation. Les [instructions plugins](https://devdocs.mautic.org/en/6.0/plugins/getting_started.html) et [extensions UI](https://devdocs.mautic.org/en/6.0/plugin_extensions/ui.html) sont des références secondaires ; les signatures ci-dessous proviennent du code cible.

## Brouillon et identifiants

`FormBundle/Controller/FormController.php::newAction/editAction` initialise la session `mautic.form.<formId>.fields.modified`, accompagnée de `fields.deleted`. Un nouveau formulaire reçoit `mautic_<sha1>` ; un formulaire enregistré utilise son ID numérique. `FieldController::newAction/editAction` modifie ce tableau, sans persister les champs. Les IDs temporaires commencent par `new`, suivis de 40 caractères hexadécimaux. `clearSessionComponents` retire les données après annulation ou enregistrement final.

Les nouveaux champs conditionnels ne sont pas nécessairement contigus à leur parent dans ce tableau. Le plugin reconstruit l’ordre visuel en parcours parent/enfants avant d’insérer la copie. Il conserve les tableaux de propriétés des sources, les frères non copiés et les entrées supprimées (qui restent exclues de la copie et de la sauvegarde).

## Entités, résultats et formulaire public

`FormModel::setFields` crée des `Field` pour les nouveaux IDs, associe `sessionId`, ordonne les racines et conserve l’alias comme clé des résultats. `FormConditionalSubscriber::onFormPostSave` remplace les parents temporaires par les IDs persistants. Les conditions natives 6.0.9 sont `{expr, any, values}` ; leur cible est `parent`, pas une chaîne arbitraire dans `values`.

Une particularité vérifiée en test fonctionnel nécessite un subscriber local : après `setFields`, les nouveaux objets restent indexés dans la collection Doctrine par leur ID temporaire. Le subscriber conditionnel natif résout pourtant leur `parent` en ID persistant. Le rendu public cherche ce parent par clé numérique et omet alors la condition de la copie dès la première sauvegarde. `PersistedIndexSubscriber` écoute `FORM_POST_SAVE` à la priorité -128, après le subscriber natif et avant `generateHtml`, et réindexe les mêmes objets avec leurs IDs persistants pour un formulaire ayant reçu des copies. La relation inverse n’a pas d’orphanRemoval dans 6.0.9 : cette opération en mémoire ne supprime ni ne recrée un champ. Le test de régression contrôle la condition publique immédiatement après la première sauvegarde.

La sauvegarde native du formulaire et ses subscribers gèrent le schéma de résultats et le HTML/cache public. Le plugin ne persiste rien et ne crée aucune colonne lui-même. `SubmissionRepository::getEntity` recharge les réponses de la table de résultats ; `findBy` seul ne recharge pas ces réponses.

## Choix et alias

`FieldModel::generateAlias/cleanAlias` effectue translittération, normalisation, vérification SQL et réservations internes. La base native est limitée à 25 caractères, avec un suffixe de collision natif qui peut ensuite l’allonger. Le plugin réutilise cette normalisation, réserve la place du suffixe `_copy`, `_copy_2` et garantit une longueur finale de 25 caractères ; les collisions incluent tout le brouillon et les alias marqués supprimés.

Les templates `Field/select.html.twig` et `Field/group.html.twig` résolvent `properties.syncList` via les `FieldCrate` collectés pour contact/entreprise. Le plugin utilise le même `FieldCollectorInterface` et les parsers natifs. Il matérialise des lignes `{label,value}` dans `list.list` ou `optionlist.list`, y compris les valeurs booléennes `0/1`, puis désactive `syncList`. Le `FormModel::getContactFieldPropertiesList` seul ne convient pas aux entreprises.

## Extensions UI, assets et services

`Builder/_actions.html.twig` n’émet pas d’événement pour les boutons de chaque champ. Le hook natif `CoreEvents::VIEW_INJECT_CUSTOM_CONTENT`, contexte `page.header.right`, reçoit les paramètres du builder et permet d’injecter une configuration échappée ainsi qu’un jeton propre à cette ouverture. JavaScript ajoute uniquement le bouton dans `.form-field-wrapper[data-sortable-id] .form-buttons`, de façon idempotente ; un MutationObserver prend en charge les fragments AJAX.

`VIEW_INJECT_CUSTOM_ASSETS` et `CustomAssetsEvent::addScript` sont présents. Le fichier se trouve dans `Assets/library/js`, hors du regroupement automatique `Assets/js`, et n’est déclaré que lorsque l’intégration est publiée, dans l’administration. Il reste chargé pendant la session de navigation AJAX ; sans configuration active du builder, il retire les boutons et n’exécute aucune duplication.

Le bundle utilise une extension DI et `Config/services.php` avec autowiring/autoconfiguration Symfony 6.4, selon les plugins natifs 6.0.9. L’intégration possède l’alias natif `mautic.integration.formfieldduplicator`. La route dédiée est `/s/wao-form-field-duplicator/duplicate`, POST, sans conflit avec les routes génériques `/forms/{objectAction}/{objectId}`. Aucune classe centrale ni template du cœur n’est remplacé.

## Sécurité et limites natives

Le noyau vérifie déjà `X-CSRF-Token` avec l’intention `mautic_ajax_post` pour les POST AJAX d’administration ; mQuery le fournit automatiquement. Le plugin ajoute son propre jeton lié au brouillon et à son ouverture. Il contrôle les droits create/editown/editother, le propriétaire, `FormModel::isLocked`, l’utilisateur de la session, l’appartenance des entités et des champs temporaires au brouillon.

Le brouillon d’un formulaire enregistré est partagé entre onglets du même utilisateur/session. Le nonce renouvelé lors du rendu du builder refuse la duplication depuis une ancienne ouverture. Il ne transforme pas les opérations natives de Mautic en brouillons indépendants par onglet. Un handler de session qui sérialise les requêtes concurrentes est requis, conformément au fonctionnement natif.

La copie et le rendu sont calculés avant toute écriture en session. Une erreur conserve le brouillon précédent. Les 32 dernières requêtes sont mémorisées pour reprendre une réponse perdue sans nouvelle copie. Aucun contenu de champ, contact ou réponse n’est journalisé par le plugin.

## Licence et contraintes

`LICENSE.txt` du tag autorise GPL version 3 ou ultérieure ; `app/composer.json` indique GPL-3.0 et PHP `~8.1` (PHP 8.x à partir de 8.1), avec Symfony `^6.4`. Le plugin choisit `GPL-3.0-or-later`, PHP `>=8.1 <8.5` et une contrainte Composer conservatrice `>=6.0.9 <6.1`. L’architecture vise 6.x ; les autres versions ne sont pas certifiées.
