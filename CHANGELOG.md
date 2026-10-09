# Changelog - B2B Electronic Invoicing

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### ✨ Added

- Validate buyer electronic addresses against active SuperPDP directory entries before Factur-X conversion in production, excluding B2C invoices. Routing checks default to `block`, with configurable `warn` and `off` modes; directory errors warn and are logged without blocking transmission.
- Select the third-party PEPPOL address from its active directory entries and use it as the invoice default.
- Override the buyer PEPPOL address for an individual invoice from a compact edit button on the main invoice card, immediately above “PDP Invoice ID”. The modal supports validation, cancellation and keyboard dismissal; invoice-specific choices are cleared when cloning.
- Add invoice transmission diagnostics showing the routing configuration, conversion and deposit steps, HTTP errors and retained attempts. Copy all displayed diagnostic information to the clipboard, keeping full technical details restricted to administrators.
- Explain the distinction between production directory addresses and fictitious SuperPDP sandbox recipients.

### 🐛 Fixed

- Block production transmissions to missing or inactive buyer addresses, including bare SIREN fallbacks when the recipient publishes only suffixed addresses.
- Share buyer address resolution between payload generation, routing validation and diagnostics to prevent divergent destinations.
- Distinguish missing associations, inactive or absent addresses and empty directory results in invoice warnings; show only active reception addresses as selectable choices.
- Preserve the third-party address when an invoice-specific destination changes, and leave saved values unchanged when modal editing is cancelled.
- Keep the invoice at the top of the page after validating a PEPPOL address, instead of scrolling to the address field.

### 🔧 Maintenance & Refactoring

- Publish changelog headings and release-note metadata in English. Generate unreleased notes without changing the module version.

- Require internal invoice write permission, explicit CSRF tokens and invoice access checks before transmission; scope outgoing lists and synchronization to authorized entities and customers.

- Require POST for all transmission and status synchronization actions and reject invoices outside validated/paid states. Use recent persisted evidence for invoice-card routing banners, reconcile existing PDP invoice responses in diagnostics, and roll back invoice creation when transmission-field resets cannot be saved.

## [1.10.1-beta.1] - 2026-10-09

### 🐛 Fixed

- Download the readable supplier invoice PDF from the incoming invoice list when the original file is UBL XML containing an embedded PDF.
- Preserve directly received PDFs, prefer the attachment designated LISIBLE, and return an explicit error for missing, invalid or ambiguous readable documents.
- Reject a LISIBLE attachment with a missing or non-PDF MIME type before considering a PDF annex as a fallback.
- Reject XML DTDs and entity expansion while extracting embedded invoice and credit-note PDFs.

## [1.10.0-beta.2] - 2026-10-09

### 🐛 Fixed
- fix(invoice): reset transmission fields even when e-invoicing is disabled (be2d118)
- fix(invoice): reset FE transmission fields on new situation invoices (#34) (184b628)

## [1.10.0-beta.1] - 2026-08-04

### ✨ Added
- feat(siren): add pluggable directories with the public gouv.fr API (8fbf9f0) by benjaminmarchand
- feat(ereporting): skip collection e-reporting when seller reports VAT on debits (b6f1155) by benjaminmarchand
- feat(superpdp): send AFNOR processing_rule (B2B/B2C/B2BInt) on transmission (9e1d120) by benjaminmarchand
- feat(setup): expose the BR-FR-05 legal mentions in the config page (#31) (7b49264) by benjaminmarchand
- feat(en16931): map retained warranty, buyer & contract references (#31) (78401e4) by benjaminmarchand

### 🐛 Fixed
- fix(peppol): parse routing identifiers instead of storing them raw (5e92b16) by benjaminmarchand
- fix(review): address Copilot feedback on PR #33 (446c3db) by benjaminmarchand
- fix(en16931): set delivery date (BT-72) to clear the R008 empty-element warning (#31) (da6808c) by benjaminmarchand
- fix(en16931): decode entity accents, resolve contract ref, BR-CO-25 (#31) (8354aba) by benjaminmarchand
- fix(invoice): resolve transmit button URL with dol_buildpath (#32) (5a20034) by benjaminmarchand
- fix(en16931): valid seller identifier scheme + mandatory FR legal notes (#31) (ddb26c7) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- refactor(ui): extract the shared SIREN lookup modal into a template (24c1821) by benjaminmarchand
- Merge pull request #33 from cyberbobjr/fix/facturationelectronique-en16931 (fc58e3b) by Benjamin MARCHAND
- test(b2c): cover resolveProcessingRule (B2B/B2C/B2BInt) (0ac8561) by benjaminmarchand
- Merge pull request #30 from cyberbobjr/ci/bump-actions-node24 (40db8db) by Benjamin MARCHAND
- ci: bump actions to Node 24 runtimes (checkout v5, gh-release v3) (5b341c7) by benjaminmarchand

## [1.9.0-beta.1] - 2026-07-05

### 🔧 Maintenance & Refactoring
- ci: switch releases to beta channel and PR-based versioning (b1cba23) by benjaminmarchand

## [1.9.0-alpha.2] - 2026-07-05

### 🔧 Maintenance & Refactoring
- chore: remove committed .playwright-mcp working directory (755b9ca) by benjaminmarchand

## [1.9.0-alpha.1] - 2026-07-05

### ✨ Added
- feat(vatex): read exemption code from the VAT dictionary (einvoice_vatex, #28) (c0fd9b4) by benjaminmarchand

## [1.8.0-alpha.3] - 2026-07-04

### 🐛 Fixed
- fix: skip SIREN error for B2C private-individual customers (#27) (5395100) by benjaminmarchand

## [1.8.0-alpha.2] - 2026-07-03

### 🐛 Fixed
- fix(setup): show live connection status on every page load (4cfb4b1) by benjaminmarchand
- fix(invoice): defer hook scripts to printCommonFooter for Dolibarr V24 (b1b9d6b) by benjaminmarchand

## [1.8.0-alpha.1] - 2026-07-03

### ✨ Added
- feat(vatex): handle EN16931 VAT exemption reason codes (BT-120/BT-121) (d29f44c) by benjaminmarchand
- feat(inbound): guard import UI, add refresh and compact download buttons (9cb97c4) by benjaminmarchand
- feat(inbound): add PDF/XML download and import toggle for incoming invoices (d974fd1) by benjaminmarchand

### 🐛 Fixed
- fix(inbound): use a labeled compact button for the refresh action (dcdba8e) by benjaminmarchand

### 📝 Documentation
- docs: document VATEX exemptions and inbound features with screenshots (069e0bd) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- i18n: add FR/EN strings for inbound download, refresh and VATEX exemptions (dfc9b47) by benjaminmarchand

## [1.7.0-alpha.4] - 2026-07-01

### 📝 Documentation
- docs: document EN16931 negative-line handling (BG-20 allowances) (2dbabf3) by benjaminmarchand

## [1.7.0-alpha.3] - 2026-07-01

### 📝 Documentation
- docs: remove FactPulse references from user guide (not DGFiP-accredited) (a997b04) by benjaminmarchand

## [1.7.0-alpha.2] - 2026-06-28

### 🐛 Fixed
- fix: remove non-existent idprof1/country_code columns from SIREN list query (3e85475) by benjaminmarchand

## [1.7.0-alpha.1] - 2026-06-28

### ✨ Added
- feat: add "Tiers sans SIREN" page with inline SIREN lookup modal (3693ad8) by benjaminmarchand

## [1.6.0-alpha.1] - 2026-06-28

### ✨ Added
- feat: add independent feature flags for e-invoicing and SIREN management (a7eb0da) by benjaminmarchand

## [1.5.0-alpha.7] - 2026-06-28

### 🐛 Fixed
- fix: skip subtotal module title/section lines from EN16931 payload (issue #14 follow-up) (49c4125) by benjaminmarchand

## [1.5.0-alpha.6] - 2026-06-28

### 🐛 Fixed
- fix: block transmission on missing seller address, omit empty buyer address fields (#24) (8b87bcc) by benjaminmarchand

## [1.5.0-alpha.5] - 2026-06-28

### 🐛 Fixed
- fix: map Dolibarr unit codes to UN/ECE Rec.20 for BT-130 instead of hardcoded C62 (#23) (a468917) by benjaminmarchand

## [1.5.0-alpha.4] - 2026-06-28

### 🐛 Fixed
- fix: use invoice currency from multicurrency_code instead of hardcoded EUR (#19) (a8208ad) by benjaminmarchand

## [1.5.0-alpha.3] - 2026-06-28

### 🐛 Fixed
- fix: map EN16931 VAT category codes dynamically (AE, K, G, Z, O, E) (#15) (9f90edf) by benjaminmarchand

## [1.5.0-alpha.2] - 2026-06-28

### 🔧 Maintenance & Refactoring
- refactor: replace raw SQL in buildPaymentMeans with native Account class (11d3b61) by benjaminmarchand

## [1.5.0-alpha.1] - 2026-06-28

### ✨ Added
- feat: inject BG-16 payment_means into EN16931 payload (#17) (150d2ac) by benjaminmarchand

## [1.4.0-alpha.8] - 2026-06-28

### 🐛 Fixed
- fix: remove fake FR00000000000 VAT fallback — inject conditionally (#18) (0c4cc8e) by benjaminmarchand

## [1.4.0-alpha.7] - 2026-06-28

### 🐛 Fixed
- fix: log and flag silent 20% VAT fallback on incoming invoice import (#22) (a316dae) by benjaminmarchand

## [1.4.0-alpha.6] - 2026-06-28

### 🐛 Fixed
- fix: inject preceding_invoice_reference (BG-3) for credit notes — BR-55 (#16) (fae172d) by benjaminmarchand

## [1.4.0-alpha.5] - 2026-06-28

### 🐛 Fixed
- fix: block transmission when all lines are negative — BR-16 violation (#20) (3eff312) by benjaminmarchand

## [1.4.0-alpha.4] - 2026-06-28

### 🐛 Fixed
- fix: remove hardcoded PMT/PMD/AAB notes — INVOICE_FREE_TEXT covers them (b4bfd2d) by benjaminmarchand
- fix: replace hardcoded payment legal notes with configurable dynamic values (cf10185) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- refactor: read INVOICE_FREE_TEXT instead of duplicating payment note config (25b36d6) by benjaminmarchand

## [1.4.0-alpha.3] - 2026-06-28

### 🐛 Fixed
- fix: strip pre-release suffix from zip filename for Dolibarr compatibility (60f13dc) by benjaminmarchand

## [1.4.0-alpha.2] - 2026-06-28

### 🐛 Fixed
- fix: map EN16931 VAT category code dynamically instead of hardcoding 'S' (372446b) by benjaminmarchand

## [1.4.0-alpha.1] - 2026-06-26

### ✨ Added
- feat: match imported supplier invoice lines to existing products (901e9e6) by benjaminmarchand

## [1.3.0-alpha.2] - 2026-06-26

### 🐛 Fixed
- fix: declare required Dolibarr module dependencies (264edd4) by benjaminmarchand

## [1.3.0-alpha.1] - 2026-06-26

### ✨ Added
- feat: SIREN format validation and SuperPDP processing trigger (3d2ba58) by benjaminmarchand

### 🐛 Fixed
- fix: respect Dolibarr SSL/proxy config in HTTP client (fixes WAMP/local SSL errors) (b5034d3) by benjaminmarchand

## [1.2.0-alpha.9] - 2026-06-26

### 📝 Documentation
- docs: remove FactPulse references from README (ad2ef60) by benjaminmarchand

## [1.2.0-alpha.8] - 2026-06-26

### 🔧 Maintenance & Refactoring
- refactor: remove FactPulse provider (not DGFiP-accredited) (f8405d6) by benjaminmarchand

## [1.2.0-alpha.7] - 2026-06-26

### 🐛 Fixed
- fix: correct TaxTotalAmount currencyID and payment amounts in EN16931 payload (fb35a38) by benjaminmarchand

## [1.2.0-alpha.6] - 2026-06-26

### 🐛 Fixed
- fix: convert negative Dolibarr lines to EN16931 document-level allowances (2335710) by benjaminmarchand

## [1.2.0-alpha.5] - 2026-06-26

### 🐛 Fixed
- fix: correct invoice send payload and outbound list Dolibarr status (7dfefb9) by benjaminmarchand

## [1.2.0-alpha.4] - 2026-06-26

### 🐛 Fixed
- fix(csrf): add the missing CSRF token to SuperPDP and FactPulse forms (#9) (fd38b74) by benjaminmarchand

## [1.2.0-alpha.3] - 2026-06-26

### 🐛 Fixed

- fix(csrf): add the missing CSRF token to SuperPDP and FactPulse forms for Dolibarr 20+ compatibility (#9) by benjaminmarchand

## [1.2.0-alpha.2] - 2026-06-06

### 📝 Documentation
- docs: add user guide for configuration and usage with screenshots (a5b5752) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #7 from cyberbobjr/feature/add-user-guide (75ab0c9) by Benjamin MARCHAND

## [1.2.0-alpha.1] - 2026-06-05

### ✨ Added
- feat(ci): enforce Conventional Commits in Pull Requests to block invalid commits (47bcf52) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #6 from cyberbobjr/feature/commit-msg-enforcer (83fb2bc) by Benjamin MARCHAND

## [1.1.0-alpha.1] - 2026-06-05

### ✨ Added
- feat(ci): implement fully automated changelog generation and version bump on merge (58102e4) by benjaminmarchand
- feat(ci): redesign release workflow to publish without committing to main (90e1f73) by benjaminmarchand
- feat(ci): automate changelog generation and SemVer release notes (c44dfb2) by benjaminmarchand
- feat(ci): add CI/CD workflows, unit testing, and contributing guidelines (6534da2) by benjaminmarchand

### 🐛 Fixed
- fix(ci): use composer update and ignore composer.lock to support multiple PHP versions (825f886) by benjaminmarchand

### 📝 Documentation
- docs: correct configuration instructions in README.md (8a7533a) by benjaminmarchand
- docs: translate CONTRIBUTING.md into French (230bb93) by benjaminmarchand

### 🔧 Maintenance & Refactoring
- Merge pull request #5 from cyberbobjr/feature/fully-automated-releases (c6836bc) by Benjamin MARCHAND
- Merge pull request #4 from cyberbobjr/feature/no-admin-bypass-release (9a31c53) by Benjamin MARCHAND
- Merge pull request #3 from cyberbobjr/feature/changelog-automation (e58e2a3) by Benjamin MARCHAND
- Merge pull request #2 from cyberbobjr/feature/fix-release-workflow (57d8c21) by Benjamin MARCHAND
- chore(ci): use RELEASE_TOKEN secret to bypass main branch protection on version bump (049a802) by benjaminmarchand
- Merge pull request #1 from cyberbobjr/feature/ci-testing (39bdc75) by Benjamin MARCHAND
- initial: First release 1.0.0-alpha for volunteer testing (3f857e5) by benjaminmarchand
