<?php
use PHPUnit\Framework\TestCase;

/** Exercise changelog generation in isolated directories without network or writes to the working repository. */
class GenerateChangelogTest extends TestCase
{
	/** @var string Temporary module fixture */
	private $moduleDir;

	/** @return void */
	protected function setUp(): void
	{
		$this->moduleDir = sys_get_temp_dir().'/facturelect-changelog-'.bin2hex(random_bytes(8));
		mkdir($this->moduleDir.'/build', 0777, true);
		mkdir($this->moduleDir.'/core/modules', 0777, true);
		copy(dirname(__DIR__).'/build/generate_changelog.php', $this->moduleDir.'/build/generate_changelog.php');
		file_put_contents($this->moduleDir.'/core/modules/modFacturationElectronique.class.php', '<?php $this->version = "1.10.0-beta.2";');
		file_put_contents($this->moduleDir.'/build/unreleased_notes.md', "### Added\n\n- Choose a buyer address for each invoice.\n");
		$intro = "Toutes les modifications notables apportées à ce projet seront consignées dans ce fichier.\n\n";
		$intro .= "Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versionnage Sémantique](https://semver.org/lang/fr/).\n\n---\n\n";
		$legacy = "# Journal des Modifications (Changelog) - Facturation Électronique B2B\n\n".$intro;
		$legacy .= "## [1.10.0-beta.2] - 2026-10-09\n\n### 🐛 Corrections de Bugs\n- fix(invoice): reset transmission fields (be2d118) par benjaminmarchand\n\n".$intro;
		file_put_contents($this->moduleDir.'/CHANGELOG.md', $legacy);
	}

	/** @return void */
	protected function tearDown(): void
	{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
		}
		rmdir($this->moduleDir);
	}

	/** @param bool $unreleased Draft mode @param array|null $environment Child environment @return int Generator exit code */
	private function generate($unreleased = true, $environment = null)
	{
		$command = array(PHP_BINARY, $this->moduleDir.'/build/generate_changelog.php');
		if ($unreleased) { $command[] = '--unreleased'; }
		$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $this->moduleDir, $environment);
		$this->assertIsResource($process);
		foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
		return proc_close($process);
	}

	/** @return void */
	public function testUnreleasedGenerationIsEnglishIdempotentAndPreservesVersionHistory()
	{
		$descriptor = file_get_contents($this->moduleDir.'/core/modules/modFacturationElectronique.class.php');
		$this->assertSame(0, $this->generate());
		$content = file_get_contents($this->moduleDir.'/CHANGELOG.md');
		$this->assertStringContainsString('## [Unreleased]', $content);
		$this->assertStringContainsString('Choose a buyer address for each invoice.', $content);
		$this->assertStringContainsString('## [1.10.0-beta.2] - 2026-10-09', $content);
		$this->assertStringContainsString('(be2d118) by benjaminmarchand', $content);
		$this->assertStringNotContainsString('Corrections de Bugs', $content);
		$this->assertStringNotContainsString('Toutes les modifications', $content);
		$this->assertSame(1, substr_count($content, '# Changelog -'));
		$this->assertSame($descriptor, file_get_contents($this->moduleDir.'/core/modules/modFacturationElectronique.class.php'));
		$this->assertFileDoesNotExist($this->moduleDir.'/build/release_notes.md');
		$this->assertSame(0, $this->generate());
		$this->assertSame($content, file_get_contents($this->moduleDir.'/CHANGELOG.md'));
		file_put_contents($this->moduleDir.'/build/unreleased_notes.md', "### Fixed\n\n- Correct invoice routing.\n");
		$this->assertSame(0, $this->generate());
		$updated = file_get_contents($this->moduleDir.'/CHANGELOG.md');
		$this->assertSame(1, substr_count($updated, '## [Unreleased]'));
		$this->assertStringContainsString('Correct invoice routing.', $updated);
		$this->assertStringNotContainsString('Choose a buyer address for each invoice.', $updated);
		$this->assertStringContainsString('(be2d118)', $updated);
	}

	/** @return void */
	public function testMissingNotesFailWithoutChangingFiles()
	{
		$previous = file_get_contents($this->moduleDir.'/CHANGELOG.md');
		unlink($this->moduleDir.'/build/unreleased_notes.md');
		$this->assertSame(1, $this->generate());
		$this->assertSame($previous, file_get_contents($this->moduleDir.'/CHANGELOG.md'));
	}
	/** @return void */
	public function testNormalReleasePromotesDraftNotesAndPreservesEnglishCommitReferences()
	{
		$this->assertSame(0, $this->generate());
		mkdir($this->moduleDir.'/bin');
		$git = "#!/bin/sh\ncase \"$1\" in\n describe) echo v1.10.0-beta.1 ;;\n log) echo 'abcdef0|feat(routing): validate buyer routing|Fixture Author|2026-10-09T12:00:00+02:00' ;;\n *) exit 1 ;;\nesac\n";
		file_put_contents($this->moduleDir.'/bin/git', $git);
		chmod($this->moduleDir.'/bin/git', 0755);
		$this->assertSame(0, $this->generate(false, array('PATH' => $this->moduleDir.'/bin:'.getenv('PATH'))));
		$content = file_get_contents($this->moduleDir.'/CHANGELOG.md');
		$this->assertStringNotContainsString('[Unreleased]', $content);
		$this->assertStringContainsString('## [1.11.0]', $content);
		$this->assertStringContainsString('Choose a buyer address for each invoice.', $content);
		$this->assertStringContainsString('#### ✨ Added', $content);
		$this->assertStringContainsString('(abcdef0) by Fixture Author', $content);
		$this->assertStringContainsString('(be2d118)', $content);
		$this->assertSame('', file_get_contents($this->moduleDir.'/build/unreleased_notes.md'));
		$this->assertStringContainsString('Choose a buyer address for each invoice.', file_get_contents($this->moduleDir.'/build/release_notes.md'));
	}
	/** @return void */
	public function testStableMaintenanceDoesNotWriteReleaseArtifacts()
	{
		file_put_contents($this->moduleDir.'/core/modules/modFacturationElectronique.class.php', '<?php $this->version = "1.10.1";');
		$this->assertSame(0, $this->generate());
		file_put_contents($this->moduleDir.'/build/release_notes.md', 'Existing release notes');
		file_put_contents($this->moduleDir.'/build/github_output', 'Existing outputs');
		$paths = array('CHANGELOG.md', 'core/modules/modFacturationElectronique.class.php', 'build/unreleased_notes.md', 'build/release_notes.md', 'build/github_output');
		$before = array();
		foreach ($paths as $path) { $before[$path] = file_get_contents($this->moduleDir.'/'.$path); }
		mkdir($this->moduleDir.'/bin');
		$git = "#!/bin/sh\ncase \"$1\" in\n describe) echo v1.10.1 ;;\n log) printf '%s\\n' 'abcdef0|docs: update guide|Fixture Author|2026-10-09T12:00:00+02:00' 'abcdef1|chore: tidy build|Fixture Author|2026-10-09T12:00:00+02:00' ;;\n *) exit 1 ;;\nesac\n";
		file_put_contents($this->moduleDir.'/bin/git', $git);
		chmod($this->moduleDir.'/bin/git', 0755);
		$environment = array('PATH' => $this->moduleDir.'/bin:'.getenv('PATH'), 'GITHUB_OUTPUT' => $this->moduleDir.'/build/github_output');
		$this->assertSame(0, $this->generate(false, $environment));
		$this->assertSame(0, $this->generate(false, $environment));
		foreach ($before as $path => $content) { $this->assertSame($content, file_get_contents($this->moduleDir.'/'.$path), $path); }
		unlink($this->moduleDir.'/build/release_notes.md');
		$this->assertSame(0, $this->generate(false, $environment));
		$this->assertFileDoesNotExist($this->moduleDir.'/build/release_notes.md');
	}

}
