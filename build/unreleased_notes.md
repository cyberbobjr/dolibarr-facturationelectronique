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
