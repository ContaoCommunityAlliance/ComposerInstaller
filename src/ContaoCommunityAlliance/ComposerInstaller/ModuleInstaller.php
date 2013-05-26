<?php

namespace ContaoCommunityAlliance\ComposerInstaller;

use Composer\Autoload\ClassMapGenerator;
use Composer\Composer;
use Composer\Package\RootPackageInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Installer\LibraryInstaller;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;

class ModuleInstaller extends LibraryInstaller
{
	const MODULE_TYPE = 'contao-module';

	const LEGACY_MODULE_TYPE = 'legacy-contao-module';

	static protected $runonces = array();

	static public function getContaoRoot(PackageInterface $package)
	{
		if (!defined('TL_ROOT')) {
			$root = dirname(getcwd());

			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra) && array_key_exists('root', $extra['contao'])) {
				$root = getcwd() . '/' . $extra['contao']['root'];
			}
			// test, do we have the core within vendor/contao/core.
			else if (is_dir(getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core')) {
				$root = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'contao' . DIRECTORY_SEPARATOR . 'core';
			}

			define('TL_ROOT', $root);
		}
		else {
			$root = TL_ROOT;
		}

		if (!defined('VERSION')) {
			// Contao 3+
			if (file_exists(
				$constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			// Contao 2+
			else if (file_exists(
				$constantsFile = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'constants.php'
			)
			) {
				require_once($constantsFile);
			}
			else {
				throw new \Exception('Could not find constants.php in ' . $root);
			}
		}

		if (empty($GLOBALS['TL_CONFIG'])) {
			if (version_compare(VERSION, '3', '>=')) {
				// load default.php
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.php');
			}
			else {
				// load config.php
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
			}

			// load localconfig.php
			if (file_exists(
				$root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'localconfig.php'
			)
			) {
				require_once($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'localconfig.php');
			}
		}

		return $root;
	}

	static public function getPreferredInstall(Composer $composer)
	{
		return $composer->getConfig()->get('preferred-install');
	}

	static public function isDistInstallPreferred(Composer $composer)
	{
		return static::getPreferredInstall($composer) == 'dist';
	}

	/**
	 * @deprecated
	 * @param Event $event
	 */
	static public function updateContaoPackage(Event $event)
	{
		static::preUpdate($event);
	}

	/**
	 * @deprecated
	 * @param Event $event
	 */
	static public function updateComposerConfig(Event $event)
	{
		static::preUpdate($event);
	}

	static public function preUpdate(Event $event)
	{
		$composer = $event->getComposer();

		/** @var \Composer\Package\RootPackage $package */
		$package = $composer->getPackage();

		// load constants
		static::getContaoRoot($package);


		$messages     = array();
		$jsonModified = false;
		$configFile   = new JsonFile('composer.json');
		$configJson   = $configFile->read();


		// remove old installer scripts
		foreach (
			array(
				'pre-update-cmd' =>array(
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateContaoPackage',
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::updateComposerConfig'
				),
				'post-update-cmd' => array(
					'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::createRunonce'
				),
			) as $key => $scripts
		) {
			foreach ($scripts as $script) {
				if (array_key_exists($key, $configJson['scripts'])) {
					if (is_array($configJson['scripts'][$key])) {
						$index = array_search($script, $configJson['scripts'][$key]);
						if ($index !== false) {
							unset($configJson['scripts'][$key][$index]);
							if (empty($configJson['scripts'][$key])) {
								unset($configJson['scripts'][$key]);
							}

							$jsonModified = true;
							$messages[] = 'obsolete ' . $key . ' script was removed!';
						}
					}
					else if ($configJson['scripts'][$key] == $script) {
						unset($configJson['scripts'][$key]);

						$jsonModified = true;
						$messages[] = 'obsolete ' . $key . ' script was removed!';
					}
				}
			}
		}


		// add installer scripts
		foreach (
			array(
				'pre-update-cmd' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::preUpdate',
				'post-update-cmd' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postUpdate',
				'post-autoload-dump' => 'ContaoCommunityAlliance\\ComposerInstaller\\ModuleInstaller::postAutoloadDump',
			) as $key => $script
		) {
			if (!array_key_exists($key, $configJson['scripts']) || empty($configJson['scripts'][$key])) {
				$configJson['scripts'][$key] = $script;

				$jsonModified = true;
				$messages[] = $key . ' script was missing and readded!';
			}
			else if (is_array($configJson['scripts'][$key])) {
				if (!in_array($script, $configJson['scripts'][$key])) {
					$configJson['scripts'][$key][] = $script;

					$jsonModified = true;
					$messages[] = $key . ' script was missing and readded!';
				}
			}
			else if ($configJson['scripts'][$key] != $script) {
				$configJson['scripts'][$key] = $script;

				$jsonModified = true;
				$messages[] = $key . ' script was missing and readded!';
			}
		}


		// add contao-community-alliance/composer dependency
		if (!array_key_exists('contao-community-alliance/composer', $configJson['require'])) {
			$configJson['require']['contao-community-alliance/composer'] = '*';

			$jsonModified = true;
			$messages[]   = 'The contao integration contao-community-alliance/composer is missing and has been readded to dependencies!';
		}


		// update contao version
		$versionParser = new VersionParser();
		$version       = VERSION . (is_numeric(BUILD) ? '.' . BUILD : '-' . BUILD);
		$prettyVersion = $versionParser->normalize($version);
		if ($package->getVersion() !== $prettyVersion) {
			$configJson['version'] = $version;

			$jsonModified = true;
			$messages[]   = sprintf(
				'Contao version changed from <info>%s</info> to <info>%s</info>!',
				$package->getPrettyVersion(),
				$version
			);
		}


		if ($jsonModified) {
			$configFile->write($configJson);
		}
		if (count($messages)) {
			$exception = null;
			foreach (array_reverse($messages) as $message) {
				$exception = new \RuntimeException($message, 0, $exception);
			}
			throw $exception;
		}
	}

	static public function createRunonce(Event $event)
	{
		static::postUpdate($event);
	}

	static public function postUpdate(Event $event)
	{
		$root = static::getContaoRoot($event->getComposer()->getPackage());

		$runonces = & static::$runonces;
		if (count($runonces)) {
			$file = 'system/runonce.php';
			$n    = 0;
			while (file_exists($root . DIRECTORY_SEPARATOR . $file)) {
				$n++;
				$file = 'system/runonce_' . $n . '.php';
			}
			if ($n > 0) {
				rename(
					$root . '/system/runonce.php',
					$root . DIRECTORY_SEPARATOR . $file
				);
				array_unshift(
					$runonces,
					$file
				);
			}

			$template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'RunonceExecutorTemplate.php');
			$template = str_replace(
				'TEMPLATE_RUNONCE_ARRAY',
				var_export($runonces, true),
				$template
			);
			file_put_contents($root . '/system/runonce.php', $template);

			$io = $event->getIO();
			$io->write("<info>Runonce created with " . count($runonces) . " updates</info>");
			foreach ($runonces as $runonce) {
				$io->write("  - " . $runonce);
			}
		}
	}

	static public function postAutoloadDump(Event $event)
	{
		$root = static::getContaoRoot($event->getComposer()->getPackage());

		$localconfig = $root . '/system/config/localconfig.php';
		$lines = file($localconfig);
		$remove = false;
		foreach ($lines as $index => $line) {
			$tline = trim($line);
			if ($tline == '### COMPOSER CLASSES START ###') {
				$remove = true;
				unset($lines[$index]);
			}
			else if ($tline == '### COMPOSER CLASSES STOP ###') {
				$remove = true;
				unset($lines[$index]);
			}
			else if ($remove || $tline == '?>') {
				unset($lines[$index]);
			}
		}
		$file = implode('', $lines);
		$file = rtrim($file);

		if (version_compare(VERSION, '3', '<')) {
			$classmapGenerator = new ClassMapGenerator();
			$classmapClasses = array();
			$installationManager = $event->getComposer()->getInstallationManager();
			$localRepository = $event->getComposer()
				->getRepositoryManager()
				->getLocalRepository();
			/** @var PackageInterface $package */
			foreach ($localRepository->getPackages() as $package) {
				if ($package->getType() == 'contao-module' || $package->getType() == 'legacy-contao-module') {
					$installPath = $installationManager->getInstallPath($package);
					$autoload = $package->getAutoload();
					if (array_key_exists('psr-0', $autoload)) {
						foreach ($autoload['psr-0'] as $source) {
							if (file_exists($installPath . '/' . $source)) {
								$classmapClasses = array_merge(
									$classmapClasses,
									$classmapGenerator->createMap($installPath . '/' . $source)
								);
							}
						}
					}
					if (array_key_exists('classmap', $autoload)) {
						foreach ($autoload['classmap'] as $source) {
							if ($installPath . '/' . $source) {
								$classmapClasses = array_merge(
									$classmapClasses,
									$classmapGenerator->createMap($installPath . '/' . $source)
								);
							}
						}
					}
				}
			}
			$classmapClasses = array_keys($classmapClasses);
			$classmapClasses = array_map(
				function($className) {
					return var_export($className, true);
				},
				$classmapClasses
			);
			$classmapClasses = implode(",\n\t\t", $classmapClasses);

			$file .= <<<EOF


### COMPOSER CLASSES START ###
if (version_compare(VERSION, '3', '<') && class_exists('FileCache')) {
	\$classes = array(
		$classmapClasses
	);
	\$cache = FileCache::getInstance('classes');
	foreach (\$classes as \$class) {
		if (!\$cache->\$class) {
			\$cache->\$class = true;
		}
	}
}
### COMPOSER CLASSES STOP ###


EOF;
		}
		else {
			$file .= "\n";
		}
		file_put_contents($root . '/system/config/localconfig.php', $file);
	}

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
		$this->updateShadowCopies(array(), $package);
		$this->updateSymlinks($package);
		$this->updateUserfiles($package);
		$this->updateRunonce($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		if (static::isDistInstallPreferred($this->composer)) {
			$this->removeSymlinks($initial);
		}
		$map = $this->playBackShadowCopies($initial);
		parent::updateCode($initial, $target);
		$this->updateShadowCopies($map, $target, $initial);
		$this->updateSymlinks($target, $initial);
		$this->updateUserfiles($target);
		$this->updateRunonce($target);
	}

	protected function removeCode(PackageInterface $package)
	{
		$this->removeSymlinks($package);
		$this->removeShadowCopies($package);
		parent::removeCode($package);
	}

	protected function playBackShadowCopies(PackageInterface $package)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->io->write("  - Play back shadow copies for package <info>" . $package->getName(
						) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

		$map = $this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($root) {
				// copy back existing files
				if (file_exists($targetFile->getPathname()) &&
					md5_file($sourceFile->getPathname()) != md5_file($targetFile->getPathname())
				) {
					$this->io->write(
						sprintf(
							"  - cp <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $targetFile->getPathname()),
							str_replace($root, '', $sourceFile->getPathname())
						)
					);
					copy($targetFile->getPathname(), $sourceFile->getPathname());
				}
			},
			false,
			true
		);

		$this->io->write('');

		return $map;
	}

	protected function updateShadowCopies($previousMap, PackageInterface $package, PackageInterface $initial = null)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->io->write("  - Update shadow copies for package <info>" . $package->getName(
						) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

		$updatedMap = $this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($root) {
				// copy non existing files
				if (!file_exists($targetFile->getPathname())) {
					$dir = dirname($targetFile->getPathname());
					if (!is_dir($dir)) {
						mkdir($dir, 0777, true);
					}
					$this->io->write(
						sprintf(
							"  - cp <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $sourceFile->getPathname()),
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					copy($sourceFile->getPathname(), $targetFile->getPathname());
				}

				// copy if file changed
				else if (md5_file($sourceFile->getPathname()) != md5_file($targetFile->getPathname())) {
					$this->io->write(
						sprintf(
							"  - cp <info>%s</info> -> <info>%s</info>",
							str_replace($root, '', $sourceFile->getPathname()),
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					copy($sourceFile->getPathname(), $targetFile->getPathname());
				}
			},
			true,
			false
		);

		foreach ($previousMap as $type => $paths) {
			foreach ($paths as $path) {
				if (!in_array($path, $updatedMap[$type])) {
					if (file_exists($path)) {
						$this->io->write(
							sprintf(
								"  - rm <info>%s</info>",
								str_replace($root, '', $path)
							)
						);
						unlink($path);
					}
					$this->removeEmptyDirectories(dirname($path));
				}
			}
		}

		$this->io->write('');
	}

	protected function removeShadowCopies(PackageInterface $package)
	{
		$self = $this;
		$root = static::getContaoRoot($this->composer->getPackage());

		$this->io->write("  - Remove shadow copies for package <info>" . $package->getName(
						) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

		$this->walkShadowCopies(
			$package,
			function (\SplFileInfo $sourceFile, \SplFileInfo $targetFile, $userfile) use ($self, $root) {
				// remove existing shadow copies
				if (file_exists($targetFile->getPathname())) {
					$this->io->write(
						sprintf(
							"  - rm <info>%s</info>",
							str_replace($root, '', $targetFile->getPathname())
						)
					);
					unlink($targetFile->getPathname());
					$self->removeEmptyDirectories(dirname($targetFile->getPathname()));
				}
			},
			false,
			true
		);

		$this->io->write('');
	}

	protected function walkShadowCopies(PackageInterface $package, $closure, $registerRunonce, $evenSymlinks)
	{
		$map = array('userfile' => array(), 'module' => array());
		$downloadPath = $this->getInstallPath($package);
		$root = static::getContaoRoot($this->composer->getPackage());

		if ($package->getType() == self::LEGACY_MODULE_TYPE) {
			$this->walkShadowCopyPaths(
				true,
				$downloadPath,
				$root,
				$closure,
				$registerRunonce,
				$map
			);
		}

		else {
			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (array_key_exists('shadow-copies', $contao)) {
					$shadowCopies = (array) $contao['shadow-copies'];

					foreach ($shadowCopies as $source => $target) {
						$this->walkShadowCopyPaths(
							false,
							$downloadPath . '/' . $source,
							$root . '/' . $target,
							$closure,
							$registerRunonce,
							$map
						);
					}
				}

				if ((static::isDistInstallPreferred($this->composer) || $evenSymlinks) && array_key_exists('symlinks', $contao)) {
					$symlinks = (array) $contao['symlinks'];

					foreach ($symlinks as $source => $target) {
						if (!is_link($root . '/' . $target)) {
							$this->walkShadowCopyPaths(
								false,
								$downloadPath . '/' . $source,
								$root . '/' . $target,
								$closure,
								$registerRunonce,
								$map
							);
						}
					}
				}
			}
		}

		return $map;
	}

	protected function walkShadowCopyPaths($legacy, $sourcePath, $targetPath, $closure, $registerRunonce, &$map)
	{
		$root = static::getContaoRoot($this->composer->getPackage());

		if (is_file($sourcePath)) {
			$sourceFile = new \SplFileInfo($sourcePath);
			$targetFile = new \SplFileInfo($targetPath);
			$closure($sourceFile, $targetFile, false);
			$map['module'][] = $targetPath;
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$sourcePath,
				\FilesystemIterator::SKIP_DOTS
			)
		);

		/** @var \SplFileInfo $sourceFile */
		foreach ($iterator as $sourceFile) {
			$pathname = str_replace($sourcePath . '/', '', $sourceFile->getRealPath());

			if ($legacy && preg_match('#^(TL_ROOT|TL_FILES)/(.*)$#e', $pathname, $matches)) {
				if ($matches[2] == 'system/runonce.php') {
					if ($registerRunonce) {
						static::$runonces[] = str_replace($root . '/', '', $sourceFile->getRealPath());
					}
					continue;
				}

				switch ($matches[1]) {
					case 'TL_ROOT':
						$base     = $targetPath;
						$userfile = false;
						break;
					case 'TL_FILES':
						$base     = $GLOBALS['TL_CONFIG']['uploadPath'];
						$userfile = true;
						break;
					default:
						continue;
				}

				$target     = $base . '/' . $matches[2];
				$targetFile = new \SplFileInfo($target);

				$closure($sourceFile, $targetFile, $userfile);
				$map[$userfile ? 'userfile' : 'module'][] = $target;
			}
			else if (!$legacy) {
				$target     = $targetPath . '/' . $pathname;
				$targetFile = new \SplFileInfo($target);

				$closure($sourceFile, $targetFile, false);
				$map['module'][] = $target;
			}
		}
	}

	protected function removeEmptyDirectories($pathname)
	{
		if (is_dir($pathname)) {
			$root = static::getContaoRoot($this->composer->getPackage());

			$contents = array_filter(
				scandir($pathname),
				function ($item) {
					return $item != '.' && $item != '..';
				}
			);
			if (empty($contents)) {
				$this->io->write(
					sprintf(
						"  - remove empty directory <info>%s</info>",
						str_replace($root, '', $pathname)
					)
				);
				rmdir($pathname);
				$this->removeEmptyDirectories(dirname($pathname));
			}
		}
	}

	protected function calculateSymlinkMap(PackageInterface $package, $complainNonSymlinks)
	{
		$map   = array();
		$extra = $package->getExtra();
		if (array_key_exists('contao', $extra)) {
			$contao = $extra['contao'];

			if (!is_array($contao)) {
				return;
			}
			if (!array_key_exists('symlinks', $contao)) {
				$contao['symlinks'] = array();
			}

			// symlinks disabled
			if ($contao['symlinks'] === false) {
				return array();
			}

			$symlinks = (array) $contao['symlinks'];

			// add fallback symlink
			if (empty($symlinks)) {
				$symlinks[''] = 'system/modules/' . preg_replace('#^.*/#', '', $package->getName());
			}

			$root = static::getContaoRoot($this->composer->getPackage());
			$installPath = $this->getInstallPath($package);

			foreach ($symlinks as $target => $link) {
				$targetReal = realpath($installPath . DIRECTORY_SEPARATOR . $target);
				$linkReal   = $root . DIRECTORY_SEPARATOR . $link;

				if (file_exists($linkReal)) {
					if (!is_link($linkReal)) {
						// special behavior for composer extension
						if ($package->getName() == 'contao-community-alliance/composer') {
							$this->filesystem->removeDirectory($root . '/system/modules/!composer');
						}
						else if ($complainNonSymlinks) {
							throw new \Exception('Cannot create symlink ' . $target . ', file exists and is not a link');
						}
					}
				}

				$targetParts = array_values(
					array_filter(
						explode(DIRECTORY_SEPARATOR, $targetReal)
					)
				);
				$linkParts   = array_values(
					array_filter(
						explode(DIRECTORY_SEPARATOR, $linkReal)
					)
				);

				// calculate a relative link target
				$linkTargetParts = array();

				while (count($targetParts) && count($linkParts) && $targetParts[0] == $linkParts[0]) {
					array_shift($targetParts);
					array_shift($linkParts);
				}

				$n = count($linkParts);
				// start on $i=1 -> skip the link name itself
				for ($i = 1; $i < $n; $i++) {
					$linkTargetParts[] = '..';
				}

				$linkTargetParts = array_merge(
					$linkTargetParts,
					$targetParts
				);

				$linkTarget = implode(DIRECTORY_SEPARATOR, $linkTargetParts);

				$map[$linkReal] = $linkTarget;
			}
		}
		return $map;
	}

	protected function updateSymlinks(PackageInterface $package, PackageInterface $initial = null)
	{
		if (!static::isDistInstallPreferred($this->composer) && $package->getType() == self::MODULE_TYPE) {
			$this->io->write("  - Update symlinks for package <info>" . $package->getName(
							) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

			$map = $this->calculateSymlinkMap($package, true);

			$root = static::getContaoRoot($this->composer->getPackage());

			if ($initial) {
				$previousMap = $this->calculateSymlinkMap($initial, true);

				$obsoleteLinks = array_diff(
					array_keys($previousMap),
					array_keys($map)
				);

				foreach ($obsoleteLinks as $linkReal) {
					if (is_link($linkReal)) {
						$this->io->write(
							"  - rm <info>" . str_replace(
								$root,
								'',
								$linkReal
							) . "</info> to <info>" . readlink(
								$linkReal
							) . "</info>"
						);
						unlink($linkReal);
					}
				}
			}

			foreach ($map as $linkReal => $linkTarget) {
				if (!is_link($linkReal) || readlink($linkReal) != $linkTarget) {
					if (is_link($linkReal)) {
						unlink($linkReal);
					}
					$this->io->write(
						"  - ln <info>" . str_replace(
							$root,
							'',
							$linkReal
						) . "</info> to <info>" . $linkTarget . "</info>"
					);
					$dir = dirname($linkReal);
					if (!is_dir($dir)) {
						mkdir($dir, 0777, true);
					}
					symlink($linkTarget, $linkReal);
				}
			}

			$this->io->write('');
		}
	}

	protected function removeSymlinks(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$this->io->write("  - Remove symlinks for package <info>" . $package->getName(
							) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

			$map = $this->calculateSymlinkMap($package, false);

			$root = static::getContaoRoot($this->composer->getPackage());

			foreach ($map as $linkReal => $linkTarget) {
				if (is_link($linkReal)) {
					$this->io->write(
						"  - rm <info>" . str_replace(
							$root,
							'',
							$linkReal
						) . "</info> to <info>" . readlink(
							$linkReal
						) . "</info>"
					);
					unlink($linkReal);
				}
			}

			$this->io->write('');
		}
	}

	protected function updateUserfiles(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$this->io->write("  - Update userfiles for package <info>" . $package->getName(
							) . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (is_array($contao) && array_key_exists('userfiles', $contao)) {
					$root = static::getContaoRoot($this->composer->getPackage());
					$uploadPath = $GLOBALS['TL_CONFIG']['uploadPath'];

					$userfiles   = (array) $contao['userfiles'];
					$installPath = $this->getInstallPath($package);

					foreach ($userfiles as $source => $target) {
						$target = $uploadPath . DIRECTORY_SEPARATOR . $target;

						$sourceReal = $installPath . DIRECTORY_SEPARATOR . $source;
						$targetReal = $root . DIRECTORY_SEPARATOR . $target;

						$it = new RecursiveDirectoryIterator($sourceReal, RecursiveDirectoryIterator::SKIP_DOTS);
						$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);

						if (!file_exists($targetReal)) {
							mkdir($targetReal, 0777, true);
						}

						foreach ($ri as $file) {
							$targetPath = $targetReal . DIRECTORY_SEPARATOR . $ri->getSubPathName();
							if (!file_exists($targetPath)) {
								if ($file->isDir()) {
									mkdir($targetPath);
								}
								else {
									$this->io->write(
										"  - cp <info>" . $ri->getSubPathName(
										) . "</info> to <info>" . $target . DIRECTORY_SEPARATOR . $ri->getSubPathName(
										) . "</info>"
									);
									copy($file->getPathname(), $targetPath);
								}
							}
						}
					}
				}
			}

			$this->io->write('');
		}
	}

	protected function updateRunonce(PackageInterface $package)
	{
		if ($package->getType() == self::MODULE_TYPE) {
			$extra = $package->getExtra();
			if (array_key_exists('contao', $extra)) {
				$contao = $extra['contao'];

				if (is_array($contao) && array_key_exists('runonce', $contao)) {
					$root     = static::getContaoRoot($this->composer->getPackage()) . DIRECTORY_SEPARATOR;
					$runonces = (array) $contao['runonce'];

					$installPath = str_replace($root, '', $this->getInstallPath($package));

					foreach ($runonces as $file) {
						static::$runonces[] = $installPath . DIRECTORY_SEPARATOR . $file;
					}
				}
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType)
	{
		return self::MODULE_TYPE === $packageType || self::LEGACY_MODULE_TYPE == $packageType;
	}
}