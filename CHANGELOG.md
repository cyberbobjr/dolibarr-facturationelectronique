# Journal des Modifications (Changelog) - Facturation Électronique B2B

Toutes les modifications notables apportées à ce projet seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versionnage Sémantique](https://semver.org/lang/fr/).

---

## [1.2.0-alpha.6] - 2026-06-26

### 🐛 Corrections de Bugs
- fix: convert negative Dolibarr lines to EN16931 document-level allowances (2335710) par benjaminmarchand

## [1.2.0-alpha.5] - 2026-06-26

### 🐛 Corrections de Bugs
- fix: correct invoice send payload and outbound list Dolibarr status (7dfefb9) par benjaminmarchand

## [1.2.0-alpha.4] - 2026-06-26

### 🐛 Corrections de Bugs
- fix(csrf): ajout du token CSRF manquant dans les formulaires SuperPDP et FactPulse (#9) (fd38b74) par benjaminmarchand

## [1.2.0-alpha.3] - 2026-06-26

### 🐛 Corrections de Bugs

- fix(csrf): ajout du token CSRF manquant dans les formulaires SuperPDP et FactPulse pour compatibilité avec Dolibarr 20+ (#9) par benjaminmarchand

## [1.2.0-alpha.2] - 2026-06-06

### 📝 Documentation
- docs: add user guide for configuration and usage with screenshots (a5b5752) par benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #7 from cyberbobjr/feature/add-user-guide (75ab0c9) par Benjamin MARCHAND

## [1.2.0-alpha.1] - 2026-06-05

### ✨ Nouvelles Fonctionnalités
- feat(ci): enforce Conventional Commits in Pull Requests to block invalid commits (47bcf52) par benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #6 from cyberbobjr/feature/commit-msg-enforcer (83fb2bc) par Benjamin MARCHAND

## [1.1.0-alpha.1] - 2026-06-05

### ✨ Nouvelles Fonctionnalités
- feat(ci): implement fully automated changelog generation and version bump on merge (58102e4) par benjaminmarchand
- feat(ci): redesign release workflow to publish without committing to main (90e1f73) par benjaminmarchand
- feat(ci): automate changelog generation and SemVer release notes (c44dfb2) par benjaminmarchand
- feat(ci): add CI/CD workflows, unit testing, and contributing guidelines (6534da2) par benjaminmarchand

### 🐛 Corrections de Bugs
- fix(ci): use composer update and ignore composer.lock to support multiple PHP versions (825f886) par benjaminmarchand

### 📝 Documentation
- docs: correct configuration instructions in README.md (8a7533a) par benjaminmarchand
- docs: translate CONTRIBUTING.md into French (230bb93) par benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #5 from cyberbobjr/feature/fully-automated-releases (c6836bc) par Benjamin MARCHAND
- Merge pull request #4 from cyberbobjr/feature/no-admin-bypass-release (9a31c53) par Benjamin MARCHAND
- Merge pull request #3 from cyberbobjr/feature/changelog-automation (e58e2a3) par Benjamin MARCHAND
- Merge pull request #2 from cyberbobjr/feature/fix-release-workflow (57d8c21) par Benjamin MARCHAND
- chore(ci): use RELEASE_TOKEN secret to bypass main branch protection on version bump (049a802) par benjaminmarchand
- Merge pull request #1 from cyberbobjr/feature/ci-testing (39bdc75) par Benjamin MARCHAND
- initial: First release 1.0.0-alpha for volunteer testing (3f857e5) par benjaminmarchand

Toutes les modifications notables apportées à ce projet seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versionnage Sémantique](https://semver.org/lang/fr/).

---
