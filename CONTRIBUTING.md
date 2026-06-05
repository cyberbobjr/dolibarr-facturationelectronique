# Contribution au module Facturation Électronique B2B

Merci de l'intérêt que vous portez à la contribution de ce module Dolibarr ! Afin de garantir un processus de développement fluide et de maintenir un niveau élevé de qualité de code, merci de suivre ces directives.

---

## Flux de travail (Workflow)

### 1. Dépôt dédié
Ce module est versionné indépendamment du cœur de Dolibarr. Assurez-vous de travailler directement dans le répertoire du module personnalisé :
`custom/facturationelectronique/`

### 2. Branches et Pull Requests
- **Ne poussez jamais directement sur la branche `main`.** Celle-ci est protégée.
- Créez une branche de fonctionnalité (feature branch) pour vos modifications :
  ```bash
  git checkout -b feature/nom-de-votre-fonctionnalite
  ```
- Poussez votre branche sur GitHub et ouvrez une **Pull Request (PR)** ciblant la branche `main`.
- Votre PR doit être approuvée par au moins un mainteneur et passer les vérifications CI/CD automatisées avant de pouvoir être fusionnée.

### 3. Versioning et Release automatiques

Pour livrer une nouvelle version du module :
1. Sur votre branche de fonctionnalité, préparez la release en exécutant le script de génération de changelog et de version :
   ```bash
   php build/generate_changelog.php
   ```
   Ce script va automatiquement :
   - Analyser les nouveaux commits et mettre à jour le fichier `CHANGELOG.md` en français.
   - Calculer la nouvelle version sémantique (SemVer) et mettre à jour le descripteur du module.
2. Commitez et poussez ces changements (`CHANGELOG.md` et descripteur) sur votre branche de Pull Request.
3. Une fois la Pull Request approuvée et fusionnée sur `main` :
   - Le pipeline CI/CD de release détecte le changement de version sur `main`.
   - Il extrait automatiquement les notes de cette version depuis le `CHANGELOG.md`.
   - Il crée le tag Git correspondant (ex: `v1.0.0-alpha.1`).
   - Il compile le package de distribution au format ZIP.
   - Il génère automatiquement la release GitHub avec le ZIP attaché et les notes de version, sans jamais avoir besoin de pousser ou de commiter automatiquement sur la branche protégée `main`.

---

## Normes de codage

Ce module respecte les normes de codage natives de Dolibarr :
- **Indentation** : Utilisez des **Tabulations** pour l'indentation des fichiers PHP, JS et CSS.
- **Syntaxe PHP** : Utilisez un code orienté objet propre et compatible avec PHP 8.1+. Ne pas mettre de balise de fermeture `?>` à la fin des fichiers contenant uniquement du PHP.
- **Excellence esthétique** : Toutes les modifications d'interface utilisateur (UI) doivent utiliser les classes de style natives de Dolibarr (ex. `liste centpercent`, `oddeven`, `butAction`) afin de garantir la responsivité et l'intégration graphique avec tous les thèmes.

---

## Exécution locale des tests unitaires

Nous utilisons **PHPUnit** pour exécuter des tests unitaires. Afin d'éviter la lourdeur du chargement d'une instance complète de Dolibarr et de sa base de données, nous utilisons un bootstrap minimal de simulation (mock).

### Prérequis

Assurez-vous d'avoir PHP et Composer installés sur votre système.

### Installation des dépendances

Exécutez Composer à la racine du module pour installer les outils de test :
```bash
composer install
```

### Exécuter les tests

Pour lancer la suite de tests localement, exécutez :
```bash
./vendor/bin/phpunit --configuration phpunit.xml
```

### Ajouter des tests

Tous les tests unitaires doivent être placés dans le répertoire `tests/` et avoir le suffixe `Test.php`.
Si vous devez simuler des objets de base de données Dolibarr ou des paramètres globaux :
- Utilisez les fonctions factices (mocks) définies dans `tests/bootstrap.php`.
- Étendez ou enregistrez des propriétés simulées dans `tests/bootstrap.php` si de nouvelles fonctions d'aide de Dolibarr sont introduites.
