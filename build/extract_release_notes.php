<?php
/**
 * Extract the changelog section for the current version to build/release_notes.md
 */

$moduleDir = dirname(__DIR__);
$descriptorPath = $moduleDir . '/core/modules/modFacturationElectronique.class.php';
$changelogPath = $moduleDir . '/CHANGELOG.md';
$releaseNotesPath = $moduleDir . '/build/release_notes.md';

if (!file_exists($descriptorPath)) {
	die("Error: Module descriptor not found.\n");
}
$descriptorContent = file_get_contents($descriptorPath);
if (!preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $descriptorContent, $matches)) {
	die("Error: Could not find version in descriptor.\n");
}
$version = $matches[1];
echo "Current version: " . $version . "\n";

if (!file_exists($changelogPath)) {
	die("Error: CHANGELOG.md not found.\n");
}
$changelog = file_get_contents($changelogPath);

// Find the section for the current version
// Format in changelog: ## [X.Y.Z] - YYYY-MM-DD
// Stop at the next ## [ or ---
$pattern = '/##\s*\[(' . preg_quote($version, '/') . ')\]\s*-\s*[^\n]+\n(.*?)(?=\n##\s*\[|\n\s*---)/s';

if (preg_match($pattern, $changelog, $matches)) {
	$notes = trim($matches[2]);
} else {
	// Fallback if not found
	$notes = "Release version " . $version;
}

if (!is_dir(dirname($releaseNotesPath))) {
	mkdir(dirname($releaseNotesPath), 0755, true);
}

file_put_contents($releaseNotesPath, $notes . "\n");
echo "Successfully extracted release notes for version " . $version . " to build/release_notes.md\n";

// Save version to GitHub Actions output if available
if (getenv('GITHUB_OUTPUT')) {
	file_put_contents(getenv('GITHUB_OUTPUT'), "new_version=" . $version . "\n", FILE_APPEND);
}
