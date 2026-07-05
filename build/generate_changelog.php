<?php
/**
 * Automated Changelog Generator and Semantic Versioning Calculator.
 * Parses git commits since the last tag (or initial commit) and:
 * 1. Categorizes commits (Features, Bug Fixes, Breaking Changes, etc.)
 * 2. Formats a new changelog entry in French.
 * 3. Prepends the entry to CHANGELOG.md.
 * 4. Automatically computes the new SemVer version.
 */

$moduleDir = dirname(__DIR__);
$descriptorPath = $moduleDir . '/core/modules/modFacturationElectronique.class.php';
$changelogPath = $moduleDir . '/CHANGELOG.md';

echo "=== Dolibarr Module Changelog & Version Generator ===\n";

// 1. Get Current Version from Descriptor
if (!file_exists($descriptorPath)) {
	die("Error: Module descriptor not found at " . $descriptorPath . "\n");
}
$descriptorContent = file_get_contents($descriptorPath);
if (!preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $descriptorContent, $matches)) {
	die("Error: Could not find version in descriptor.\n");
}
$currentVersion = $matches[1];
echo "Current version in descriptor: " . $currentVersion . "\n";

// 2. Find Last Git Tag
$lastTag = shell_exec('git describe --tags --abbrev=0 2>/dev/null');
$lastTag = $lastTag ? trim($lastTag) : '';

if ($lastTag) {
	echo "Last git tag found: " . $lastTag . "\n";
	$gitLogCmd = 'git log ' . escapeshellarg($lastTag) . '..HEAD --pretty=format:"%h|%s|%an|%cI"';
} else {
	echo "No git tag found. Reading all commits from initial commit.\n";
	$gitLogCmd = 'git log --pretty=format:"%h|%s|%an|%cI"';
}

// 3. Retrieve and Parse Commits
$commitsOutput = shell_exec($gitLogCmd);
if (empty($commitsOutput)) {
	echo "No new commits found since last tag/initial commit. Nothing to do.\n";
	exit(0);
}

$commits = explode("\n", trim($commitsOutput));
$categorized = array(
	'breaking' => array(),
	'feat' => array(),
	'fix' => array(),
	'docs' => array(),
	'refactor' => array(),
	'chore' => array()
);

$hasBreakingChange = false;
$hasFeature = false;
$hasFix = false;

foreach ($commits as $commitLine) {
	if (empty($commitLine)) continue;
	
	$parts = explode('|', $commitLine, 4);
	if (count($parts) < 2) continue;
	
	$hash = $parts[0];
	$subject = $parts[1];
	$author = isset($parts[2]) ? $parts[2] : 'unknown';
	$date = isset($parts[3]) ? substr($parts[3], 0, 10) : '';

	// Detect breaking change indicator in title (e.g. feat!: or fix!:)
	$isBreaking = false;
	if (preg_match('/^[a-z]+(\([a-z0-9_-]+\))?!:/i', $subject) || strpos($subject, 'BREAKING CHANGE') !== false) {
		$isBreaking = true;
		$hasBreakingChange = true;
	}

	// Clean subject and assign category
	if ($isBreaking) {
		$categorized['breaking'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
	} elseif (preg_match('/^feat(\([a-z0-9_-]+\))?:/i', $subject)) {
		$categorized['feat'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
		$hasFeature = true;
	} elseif (preg_match('/^fix(\([a-z0-9_-]+\))?:/i', $subject)) {
		$categorized['fix'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
		$hasFix = true;
	} elseif (preg_match('/^docs(\([a-z0-9_-]+\))?:/i', $subject)) {
		$categorized['docs'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
	} elseif (preg_match('/^refactor(\([a-z0-9_-]+\))?:/i', $subject)) {
		$categorized['refactor'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
	} else {
		$categorized['chore'][] = array('hash' => $hash, 'msg' => $subject, 'author' => $author, 'date' => $date);
	}
}

// 4. Calculate New Version (Semantic Versioning)
// Target SemVer format: X.Y.Z or X.Y.Z-<channel>.N (channel = alpha | beta).
//
// Pre-release channel emitted for the NEXT version. Set to 'beta' during the beta
// phase, 'alpha' for early builds, or '' to promote the line to a stable release.
$targetChannel = 'beta';

$versionBase = '';
$currentChannel = '';
$preReleaseNum = 0;

if (preg_match('/^(\d+\.\d+\.\d+)-(alpha|beta)\.(\d+)$/', $currentVersion, $verMatches)) {
	$versionBase = $verMatches[1];
	$currentChannel = $verMatches[2];
	$preReleaseNum = (int)$verMatches[3];
} elseif (preg_match('/^(\d+\.\d+\.\d+)-(alpha|beta)$/', $currentVersion, $verMatches)) {
	$versionBase = $verMatches[1];
	$currentChannel = $verMatches[2];
	$preReleaseNum = 0;
} elseif (preg_match('/^(\d+\.\d+\.\d+)$/', $currentVersion, $verMatches)) {
	$versionBase = $verMatches[1];
	$currentChannel = '';
	$preReleaseNum = 0;
} else {
	// Fallback
	$versionBase = $currentVersion;
	$currentChannel = '';
}

// The suffix we emit. An empty target channel promotes the line to a stable release.
$preReleaseSuffix = $targetChannel !== '' ? '-' . $targetChannel : '';

$parts = explode('.', $versionBase);
$major = (int)$parts[0];
$minor = (int)$parts[1];
$patch = (int)$parts[2];

if ($hasBreakingChange) {
	// Breaking change always bumps major version
	$major++;
	$minor = 0;
	$patch = 0;
	if (!empty($preReleaseSuffix)) {
		$preReleaseNum = 1;
	}
} elseif ($hasFeature) {
	// Feature bumps minor version
	if (empty($preReleaseSuffix)) {
		$minor++;
		$patch = 0;
	} else {
		// Pre-release line: bump the minor base and restart the pre-release counter.
		$minor++;
		$patch = 0;
		$preReleaseNum = 1;
	}
} elseif ($hasFix) {
	// Fix bumps patch version (or just increments the pre-release count on a pre-release line)
	if (empty($preReleaseSuffix)) {
		$patch++;
	} else {
		$preReleaseNum++;
	}
} else {
	// No code changes (only chores, docs, etc.): increment the pre-release count if any
	if (!empty($preReleaseSuffix)) {
		$preReleaseNum++;
	}
}

// Switching pre-release channel (e.g. alpha -> beta) restarts the counter at 1 on the
// same base version, so beta.1 cleanly supersedes the last alpha build. SemVer orders
// alpha < beta, so 1.9.0-beta.1 > 1.9.0-alpha.N as intended.
if (!empty($preReleaseSuffix) && $currentChannel !== '' && $currentChannel !== $targetChannel) {
	$preReleaseNum = 1;
}

// Build the new version string
$newVersionBase = "$major.$minor.$patch";
$newVersion = $newVersionBase;
if (!empty($preReleaseSuffix)) {
	$newVersion .= $preReleaseSuffix . '.' . $preReleaseNum;
}

echo "Computed new version: " . $newVersion . "\n";

// 5. Generate Markdown Changelog Section (in French)
$today = date('Y-m-d');
$changelogEntry = "## [" . $newVersion . "] - " . $today . "\n\n";

if (!empty($categorized['breaking'])) {
	$changelogEntry .= "### ⚠️ Changements Majeurs (Breaking Changes)\n";
	foreach ($categorized['breaking'] as $c) {
		$changelogEntry .= "- " . $c['msg'] . " (" . $c['hash'] . ") par " . $c['author'] . "\n";
	}
	$changelogEntry .= "\n";
}

if (!empty($categorized['feat'])) {
	$changelogEntry .= "### ✨ Nouvelles Fonctionnalités\n";
	foreach ($categorized['feat'] as $c) {
		$changelogEntry .= "- " . $c['msg'] . " (" . $c['hash'] . ") par " . $c['author'] . "\n";
	}
	$changelogEntry .= "\n";
}

if (!empty($categorized['fix'])) {
	$changelogEntry .= "### 🐛 Corrections de Bugs\n";
	foreach ($categorized['fix'] as $c) {
		$changelogEntry .= "- " . $c['msg'] . " (" . $c['hash'] . ") par " . $c['author'] . "\n";
	}
	$changelogEntry .= "\n";
}

if (!empty($categorized['docs'])) {
	$changelogEntry .= "### 📝 Documentation\n";
	foreach ($categorized['docs'] as $c) {
		$changelogEntry .= "- " . $c['msg'] . " (" . $c['hash'] . ") par " . $c['author'] . "\n";
	}
	$changelogEntry .= "\n";
}

if (!empty($categorized['refactor']) || !empty($categorized['chore'])) {
	$changelogEntry .= "### 🔧 Maintenance & Refactoring\n";
	$otherCommits = array_merge($categorized['refactor'], $categorized['chore']);
	foreach ($otherCommits as $c) {
		$changelogEntry .= "- " . $c['msg'] . " (" . $c['hash'] . ") par " . $c['author'] . "\n";
	}
	$changelogEntry .= "\n";
}

// 6. Prepend Entry to CHANGELOG.md
$header = "# Journal des Modifications (Changelog) - Facturation Électronique B2B\n\n";
$header .= "Toutes les modifications notables apportées à ce projet seront consignées dans ce fichier.\n\n";
$header .= "Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versionnage Sémantique](https://semver.org/lang/fr/).\n\n---\n\n";

if (file_exists($changelogPath)) {
	$existingContent = file_get_contents($changelogPath);
	// Remove old header if present
	$cleanContent = str_replace($header, '', $existingContent);
	// Ensure the separator line is clean
	$cleanContent = preg_replace('/^# Journal des Modifications.*?\n\n/s', '', $cleanContent);
	
	$newChangelog = $header . $changelogEntry . $cleanContent;
} else {
	$newChangelog = $header . $changelogEntry;
}

if (file_put_contents($changelogPath, $newChangelog) === false) {
	die("Error: Failed to write to CHANGELOG.md\n");
}
echo "Successfully updated CHANGELOG.md\n";

// Write temporary release notes for CI
$releaseNotesPath = $moduleDir . '/build/release_notes.md';
if (!is_dir($moduleDir . '/build')) {
	mkdir($moduleDir . '/build', 0755, true);
}
$releaseNotesContent = str_replace("## [" . $newVersion . "] - " . $today . "\n\n", "", $changelogEntry);
if (file_put_contents($releaseNotesPath, $releaseNotesContent) === false) {
	echo "Warning: Failed to write release_notes.md\n";
} else {
	echo "Successfully generated build/release_notes.md\n";
}

// 7. Update Descriptor version
$newDescriptorContent = preg_replace(
	'/(\$this->version\s*=\s*[\'"])[^\'"]+([\'"];)/',
	'${1}' . $newVersion . '${2}',
	$descriptorContent
);
if (file_put_contents($descriptorPath, $newDescriptorContent) === false) {
	die("Error: Failed to update version in descriptor.\n");
}
echo "Successfully updated version to " . $newVersion . " in descriptor.\n";

// Output version to environment variables if running in GitHub Actions
if (getenv('GITHUB_OUTPUT')) {
	$zipVersion = preg_replace('/-[a-zA-Z].*$/', '', $newVersion);
	file_put_contents(getenv('GITHUB_OUTPUT'), "new_version=" . $newVersion . "\n", FILE_APPEND);
	file_put_contents(getenv('GITHUB_OUTPUT'), "zip_version=" . $zipVersion . "\n", FILE_APPEND);
}
