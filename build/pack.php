<?php
/**
 * Automated packaging script for Dolibarr Facturation Electronique module
 * Generates a ZIP package formatted for DoliStore distribution.
 */

// Define directory paths
$moduleDir = dirname(__DIR__);
$buildDir = $moduleDir . '/build';
$stagingDir = $buildDir . '/facturationelectronique';

echo "=== Dolibarr Module Packaging Script ===\n";

// 1. Resolve module version from descriptor
$descriptorPath = $moduleDir . '/core/modules/modFacturationElectronique.class.php';
if (!file_exists($descriptorPath)) {
	die("Error: Module descriptor not found at " . $descriptorPath . "\n");
}

$descriptorContent = file_get_contents($descriptorPath);
if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $descriptorContent, $matches)) {
	$version = $matches[1];
} else {
	$version = 'unknown';
}
echo "Resolved module version: " . $version . "\n";

// Strip pre-release suffix (-alpha.N, -beta.N, etc.) — Dolibarr's installer regex only accepts digits and dots
$zip_version = preg_replace('/-[a-zA-Z].*$/', '', $version);
$zipFileName = 'module_facturationelectronique-' . $zip_version . '.zip';
$zipFilePath = $buildDir . '/' . $zipFileName;

// 2. Prepare build and staging directories
if (!is_dir($buildDir)) {
	mkdir($buildDir, 0755, true);
}

// Clean old staging or target files if they exist
if (is_dir($stagingDir)) {
	rrmdir($stagingDir);
}
if (file_exists($zipFilePath)) {
	unlink($zipFilePath);
}

mkdir($stagingDir, 0755, true);

// 3. Excluded file patterns from packaging
$excludes = array(
	'.git',
	'.gitignore',
	'.gitattributes',
	'.editorconfig',
	'.tx',
	'build',
	'test_lookup.php',
	'test_payments_trigger.php',
	'Thumbs.db',
	'.DS_Store',
	'vendor',
	'composer.json',
	'composer.lock',
	'.github',
	'tests',
	'phpunit.xml',
	'.phpunit.result.cache',
	'.phpunit.cache'
);

echo "Copying files to staging directory...\n";
copyRecursive($moduleDir, $stagingDir, $excludes);

// 4. Create ZIP archive
echo "Compressing files into ZIP package: " . $zipFileName . "...\n";
if (createZipFromFolder($stagingDir, $zipFilePath)) {
	echo "Success! Package created at: " . $zipFilePath . "\n";
} else {
	echo "Error: Failed to create ZIP archive.\n";
}

// 5. Clean up temporary staging directory
echo "Cleaning up temporary staging directory...\n";
rrmdir($stagingDir);
echo "Cleanup completed. Done!\n";


/**
 * Helper to recursively delete a directory
 */
function rrmdir($dir) {
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object)) {
					rrmdir($dir . "/" . $object);
				} else {
					unlink($dir . "/" . $object);
				}
			}
		}
		rmdir($dir);
	}
}

/**
 * Helper to recursively copy directories with exclusion filter
 */
function copyRecursive($src, $dst, $excludes = array()) {
	$dir = opendir($src);
	@mkdir($dst);
	while (false !== ($file = readdir($dir))) {
		if (($file != '.') && ($file != '..')) {
			// Check if file or directory matches any exclusion pattern
			$shouldExclude = false;
			foreach ($excludes as $exclude) {
				if ($file === $exclude || strpos($file, $exclude . '/') === 0) {
					$shouldExclude = true;
					break;
				}
			}
			if ($shouldExclude) {
				continue;
			}

			if (is_dir($src . '/' . $file)) {
				copyRecursive($src . '/' . $file, $dst . '/' . $file, $excludes);
			} else {
				copy($src . '/' . $file, $dst . '/' . $file);
			}
		}
	}
	closedir($dir);
}

/**
 * Helper to compress folder into a ZIP archive preserving structure
 */
function createZipFromFolder($source, $destination) {
	if (!extension_loaded('zip') || !file_exists($source)) {
		return false;
	}

	$zip = new ZipArchive();
	if (!$zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
		return false;
	}

	$source = str_replace('\\', '/', realpath($source));

	if (is_dir($source) === true) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::LEAVES_ONLY);

		foreach ($files as $file) {
			$file = str_replace('\\', '/', $file);

			// Skip parent/current dir pointers
			if (in_array(substr($file, -2), array('/.', '/..'))) {
				continue;
			}

			$filePath = realpath($file);
			if (is_dir($filePath)) {
				continue;
			}
			// Calculate relative path inside ZIP, keeping the parent staging folder name
			$relativePath = 'facturationelectronique/' . substr($file, strlen($source) + 1);

			$zip->addFile($filePath, $relativePath);
		}
	} else if (is_file($source) === true) {
		$zip->addFromString(basename($source), file_get_contents($source));
	}

	return $zip->close();
}
