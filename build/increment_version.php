<?php
/**
 * Increment the module version in the descriptor file.
 */

$moduleDir = dirname(__DIR__);
$descriptorPath = $moduleDir . '/core/modules/modFacturationElectronique.class.php';

if (!file_exists($descriptorPath)) {
	die("Error: Module descriptor not found at " . $descriptorPath . "\n");
}

$content = file_get_contents($descriptorPath);

// Find version line
if (!preg_match('/(\$this->version\s*=\s*[\'"])([^\'"]+)([\'"];)/', $content, $matches)) {
	die("Error: Could not find version line in descriptor.\n");
}

$prefix = $matches[1];
$currentVersion = $matches[2];
$suffix = $matches[3];

echo "Current version: " . $currentVersion . "\n";

// Logic to increment version
// If format is X.Y.Z-alpha.N
if (preg_match('/^(\d+\.\d+\.\d+-alpha)\.(\d+)$/', $currentVersion, $verMatches)) {
	$base = $verMatches[1];
	$num = (int)$verMatches[2] + 1;
	$newVersion = $base . '.' . $num;
} elseif (preg_match('/^(\d+\.\d+\.\d+)-alpha$/', $currentVersion, $verMatches)) {
	// If it is just X.Y.Z-alpha, start with X.Y.Z-alpha.1
	$newVersion = $verMatches[1] . '-alpha.1';
} elseif (preg_match('/^(\d+\.\d+)\.(\d+)$/', $currentVersion, $verMatches)) {
	// If it is X.Y.N (stable versioning fallback)
	$base = $verMatches[1];
	$num = (int)$verMatches[2] + 1;
	$newVersion = $base . '.' . $num;
} else {
	// Default fallback: append build number or raise patch
	$newVersion = $currentVersion . '.1';
}

echo "New version: " . $newVersion . "\n";

// Replace version in content
$newContent = preg_replace(
	'/(\$this->version\s*=\s*[\'"])[^\'"]+([\'"];)/',
	'${1}' . $newVersion . '${2}',
	$content
);

if (file_put_contents($descriptorPath, $newContent) === false) {
	die("Error: Failed to write updated version to descriptor.\n");
}

echo "Successfully updated version to " . $newVersion . " in descriptor.\n";
// Save to GitHub output if running in GitHub Actions
if (getenv('GITHUB_OUTPUT')) {
	file_put_contents(getenv('GITHUB_OUTPUT'), "new_version=" . $newVersion . "\n", FILE_APPEND);
}
