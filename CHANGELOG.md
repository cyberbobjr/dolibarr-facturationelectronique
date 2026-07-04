# Journal des Modifications (Changelog) - Facturation Électronique B2B

Toutes les modifications notables apportées à ce projet seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versionnage Sémantique](https://semver.org/lang/fr/).

---

## [1.8.0-alpha.3] - 2026-07-04

### 🐛 Corrections de Bugs
- fix: skip SIREN error for B2C private-individual customers (#27) (5395100) par benjaminmarchand

## [1.8.0-alpha.2] - 2026-07-03

### 🐛 Corrections de Bugs
- fix(setup): show live connection status on every page load (4cfb4b1) par benjaminmarchand
- fix(invoice): defer hook scripts to printCommonFooter for Dolibarr V24 (b1b9d6b) par benjaminmarchand

## [1.8.0-alpha.1] - 2026-07-03

### ✨ Nouvelles Fonctionnalités
- feat(vatex): handle EN16931 VAT exemption reason codes (BT-120/BT-121) (d29f44c) par benjaminmarchand
- feat(inbound): guard import UI, add refresh and compact download buttons (9cb97c4) par benjaminmarchand
- feat(inbound): add PDF/XML download and import toggle for incoming invoices (d974fd1) par benjaminmarchand

### 🐛 Corrections de Bugs
- fix(inbound): use a labeled compact button for the refresh action (dcdba8e) par benjaminmarchand

### 📝 Documentation
- docs: document VATEX exemptions and inbound features with screenshots (069e0bd) par benjaminmarchand

### 🔧 Maintenance & Refactoring
- i18n: add FR/EN strings for inbound download, refresh and VATEX exemptions (dfc9b47) par benjaminmarchand

## [1.7.0-alpha.4] - 2026-07-01

### 📝 Documentation
- docs: document EN16931 negative-line handling (BG-20 allowances) (2dbabf3) par benjaminmarchand

## [1.7.0-alpha.3] - 2026-07-01

### 📝 Documentation
- docs: remove FactPulse references from user guide (non-agréé DGFiP) (a997b04) par benjaminmarchand

## [1.7.0-alpha.2] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: remove non-existent idprof1/country_code columns from SIREN list query (3e85475) par benjaminmarchand

## [1.7.0-alpha.1] - 2026-06-28

### ✨ Nouvelles Fonctionnalités
- feat: add "Tiers sans SIREN" page with inline SIREN lookup modal (3693ad8) par benjaminmarchand

## [1.6.0-alpha.1] - 2026-06-28

### ✨ Nouvelles Fonctionnalités
- feat: add independent feature flags for e-invoicing and SIREN management (a7eb0da) par benjaminmarchand

## [1.5.0-alpha.7] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: skip subtotal module title/section lines from EN16931 payload (issue #14 follow-up) (49c4125) par benjaminmarchand

## [1.5.0-alpha.6] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: block transmission on missing seller address, omit empty buyer address fields (#24) (8b87bcc) par benjaminmarchand

## [1.5.0-alpha.5] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: map Dolibarr unit codes to UN/ECE Rec.20 for BT-130 instead of hardcoded C62 (#23) (a468917) par benjaminmarchand

## [1.5.0-alpha.4] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: use invoice currency from multicurrency_code instead of hardcoded EUR (#19) (a8208ad) par benjaminmarchand

## [1.5.0-alpha.3] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: map EN16931 VAT category codes dynamically (AE, K, G, Z, O, E) (#15) (9f90edf) par benjaminmarchand

## [1.5.0-alpha.2] - 2026-06-28

### 🔧 Maintenance & Refactoring
- refactor: replace raw SQL in buildPaymentMeans with native Account class (11d3b61) par benjaminmarchand

## [1.5.0-alpha.1] - 2026-06-28

### ✨ Nouvelles Fonctionnalités
- feat: inject BG-16 payment_means into EN16931 payload (#17) (150d2ac) par benjaminmarchand

## [1.4.0-alpha.8] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: remove fake FR00000000000 VAT fallback — inject conditionally (#18) (0c4cc8e) par benjaminmarchand

## [1.4.0-alpha.7] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: log and flag silent 20% VAT fallback on incoming invoice import (#22) (a316dae) par benjaminmarchand

## [1.4.0-alpha.6] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: inject preceding_invoice_reference (BG-3) for credit notes — BR-55 (#16) (fae172d) par benjaminmarchand

## [1.4.0-alpha.5] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: block transmission when all lines are negative — BR-16 violation (#20) (3eff312) par benjaminmarchand

## [1.4.0-alpha.4] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: remove hardcoded PMT/PMD/AAB notes — INVOICE_FREE_TEXT covers them (b4bfd2d) par benjaminmarchand
- fix: replace hardcoded payment legal notes with configurable dynamic values (cf10185) par benjaminmarchand

### 🔧 Maintenance & Refactoring
- refactor: read INVOICE_FREE_TEXT instead of duplicating payment note config (25b36d6) par benjaminmarchand

## [1.4.0-alpha.3] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: strip pre-release suffix from zip filename for Dolibarr compatibility (60f13dc) par benjaminmarchand

## [1.4.0-alpha.2] - 2026-06-28

### 🐛 Corrections de Bugs
- fix: map EN16931 VAT category code dynamically instead of hardcoding 'S' (372446b) par benjaminmarchand

## [1.4.0-alpha.1] - 2026-06-26

### ✨ Nouvelles Fonctionnalités
- feat: match imported supplier invoice lines to existing products (901e9e6) par benjaminmarchand

## [1.3.0-alpha.2] - 2026-06-26

### 🐛 Corrections de Bugs
- fix: declare required Dolibarr module dependencies (264edd4) par benjaminmarchand

## [1.3.0-alpha.1] - 2026-06-26

### ✨ Nouvelles Fonctionnalités
- feat: SIREN format validation and SuperPDP processing trigger (3d2ba58) par benjaminmarchand

### 🐛 Corrections de Bugs
- fix: respect Dolibarr SSL/proxy config in HTTP client (fixes WAMP/local SSL errors) (b5034d3) par benjaminmarchand

## [1.2.0-alpha.9] - 2026-06-26

### 📝 Documentation
- docs: remove FactPulse references from README (ad2ef60) par benjaminmarchand

## [1.2.0-alpha.8] - 2026-06-26

### 🔧 Maintenance & Refactoring
- refactor: remove FactPulse provider (non-agréé DGFiP) (f8405d6) par benjaminmarchand

## [1.2.0-alpha.7] - 2026-06-26

### 🐛 Corrections de Bugs
- fix: correct TaxTotalAmount currencyID and payment amounts in EN16931 payload (fb35a38) par benjaminmarchand

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
