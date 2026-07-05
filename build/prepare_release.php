<?php
/**
 * Read-only release preparation for the merge-triggered CI.
 *
 * Version bumping now happens IN the pull request (see CONTRIBUTING.md), so the
 * post-merge release pipeline must never modify a tracked file nor push to the
 * protected `main` branch. This script only READS the already-bumped state:
 *   1. reads the current version from the module descriptor,
 *   2. extracts that version's section from CHANGELOG.md into build/release_notes.md,
 *   3. exposes new_version + zip_version to GitHub Actions via $GITHUB_OUTPUT.
 *
 * It is intentionally side-effect-free on tracked files (build/release_notes.md is
 * gitignored) so it is safe to run on a protected branch.
 */

$moduleDir = dirname(__DIR__);
$descriptorPath = $moduleDir . '/core/modules/modFacturationElectronique.class.php';
$changelogPath = $moduleDir . '/CHANGELOG.md';

$descriptor = file_get_contents($descriptorPath);
if ($descriptor === false || !preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $descriptor, $m)) {
	fwrite(STDERR, "Error: could not read \$this->version from the module descriptor.\n");
	exit(1);
}
$version = $m[1];
// Dolibarr's installer regex only accepts digits and dots — strip any -beta.N / -alpha.N suffix.
$zipVersion = preg_replace('/-[a-zA-Z].*$/', '', $version);
echo "Release version: " . $version . " (zip: " . $zipVersion . ")\n";

// Extract the CHANGELOG section for this exact version, from its "## [version] - date"
// heading up to (but excluding) the next "## [" heading or end of file.
$notes = '';
if (file_exists($changelogPath)) {
	$changelog = file_get_contents($changelogPath);
	$pattern = '/##\s*\[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=\n##\s*\[|\z)/s';
	if (preg_match($pattern, $changelog, $cm)) {
		$notes = trim($cm[1]);
	}
}
if ($notes === '') {
	// Never ship an empty release body.
	$notes = "Release " . $version;
	fwrite(STDERR, "Warning: no CHANGELOG section found for " . $version . ", using a generic body.\n");
}

$buildDir = $moduleDir . '/build';
if (!is_dir($buildDir)) {
	mkdir($buildDir, 0755, true);
}
$releaseNotesPath = $buildDir . '/release_notes.md';
if (file_put_contents($releaseNotesPath, $notes . "\n") === false) {
	fwrite(STDERR, "Error: failed to write build/release_notes.md\n");
	exit(1);
}
echo "Wrote build/release_notes.md\n";

// Expose outputs to GitHub Actions.
$githubOutput = getenv('GITHUB_OUTPUT');
if ($githubOutput) {
	file_put_contents($githubOutput, "new_version=" . $version . "\n", FILE_APPEND);
	file_put_contents($githubOutput, "zip_version=" . $zipVersion . "\n", FILE_APPEND);
}
