# Changelog

## 1.0.0 — 2026-10-08

- Duplication serveur d’un champ natif et de tous ses descendants conditionnels dans le brouillon.
- Copie indépendante, nouveaux identifiants et alias, retrait des associations CRM.
- Matérialisation des choix synchronisés contact et entreprise.
- Réutilisation du rendu, de l’éditeur et de la sauvegarde Mautic.
- Contrôles d’accès, propriété, verrou et double protection CSRF ; reprises idempotentes.
- Bouton accessible, traductions françaises et anglaises, navigation AJAX.
- Réindexation en mémoire après sauvegarde pour les conditions publiques dès le premier enregistrement.
- Tests unitaires, collecteurs CRM réels, couverture des 18 types et parcours Chromium sur Mautic 6.0.9.
- Préparation GitHub : CI PHP 8.1/8.2/8.3, brouillon de release sur tag, notes de version et ZIP reproductible avec empreinte SHA-256.
- Métadonnées Composer pour une version issue du tag Git et le dossier natif `WebAnyOneFormFieldDuplicatorBundle`.
