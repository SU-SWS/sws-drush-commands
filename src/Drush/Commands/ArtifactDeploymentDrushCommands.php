<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drupal\SwsDrush\Helpers\LocalMachineHelper;
use Drupal\SwsDrush\Output\Checklist;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

/**
 * A Drush command file.
 */
final class ArtifactDeploymentDrushCommands extends DrushCommands {

  protected string $dir;

  protected array $vendorDirs;

  protected array $scaffoldFiles;

  private string $composerJsonPath;

  private string $docrootPath;

  private string $destinationGitRef;

  protected Checklist $checklist;

  protected LocalMachineHelper $localMachineHelper;

  /**
   * Build and push an artifact based on the current drupal installation.
   */
  #[CLI\Command(name: 'artifact_deployment:build', aliases: ['ab'])]
  #[CLI\Option(name: 'drupal-core-folder', description: 'Drupal install folder e.g. docroot or web')]
  #[CLI\Option(name: 'git-url', description: 'Destination git repo url')]
  #[CLI\Option(name: 'branch', description: 'Destination branch')]
  #[CLI\Option(name: 'tag', description: 'Destination Tag name')]
  #[CLI\Option(name: 'no-sanitize', description: 'Do not sanitize the build artifact')]
  #[CLI\Option(name: 'no-push', description: 'Do not push changes to VCS repository')]
  #[CLI\Option(name: 'post-build-script', description: 'Shell script to run after the build')]
  #[CLI\Option(name: 'artifact-dir', description: 'Directory to build the artifact')]
  #[CLI\Usage(name: 'artifact_deployment:build', description: 'Usage description')]
  public function buildCommand(
    $options = [
      'drupal-core-folder' => 'docroot',
      'git-url' => InputOption::VALUE_REQUIRED,
      'branch' => InputOption::VALUE_OPTIONAL,
      'tag' => InputOption::VALUE_OPTIONAL,
      'no-sanitize' => FALSE,
      'no-push' => FALSE,
      'post-build-script' => NULL,
      'artifact-dir' => NULL,
    ]
  ): void {
    $this->ensureOption('git-url', [$this, 'askGitUrl'], TRUE);
    $this->ensureOption('branch', [$this, 'askGitBranch'], TRUE);

    /** @var \Drush\Boot\BootstrapManager $bootstrap */
    $bootstrap = Drush::bootstrapManager();
    $this->dir = $bootstrap->getComposerRoot();

    $this->localMachineHelper = new LocalMachineHelper();
    $this->localMachineHelper->setLogger($this->logger());
    $this->localMachineHelper->setInput($this->input());
    $this->localMachineHelper->setOutput($this->output());

    $artifactDir = $options['artifact-dir'] ?: Path::join(sys_get_temp_dir(), 'drupal-artifact-build');
    $this->composerJsonPath = Path::join($this->dir, 'composer.json');
    $this->docrootPath = Path::join($this->dir, $options['drupal-core-folder']);
    $this->validateSourceCode();

    $isDirty = $this->isLocalGitRepoDirty();
    $commitHash = $this->getLocalGitCommitHash();
    if ($isDirty) {
      throw new \RuntimeException(
        'Pushing code was aborted because your local Git repository has uncommitted changes. Either commit, reset, or stash your changes via git.'
      );
    }
    $this->checklist = new Checklist($this->output());
    $outputCallback = $this->getOutputCallback($this->output(), $this->checklist);

    $destinationGitUrls = [];
    $destinationGitUrls[] = $this->input->getOption('git-url');

    $this->destinationGitRef = $this->input->getOption('branch');
    $sourceGitBranch = $this->destinationGitRef;
    $destinationGitUrlsString = implode(',', $destinationGitUrls);
    $refType = 'branch';
    $this->io()->note([
      'The command will:',
      "- git clone $sourceGitBranch from $destinationGitUrls[0]",
      "- Compile the contents of $this->dir into an artifact in a temporary directory",
      "- Copy the artifact files into the checked out copy of $sourceGitBranch",
      "- Run provided post-build {$options['post-build-script']} script if specified",
      "- Commit changes and push the $this->destinationGitRef $refType to the following git remote(s):",
      "  $destinationGitUrlsString",
    ]);

    $this->checklist->addItem('Preparing artifact directory');
    $this->cloneSourceBranch($outputCallback, $artifactDir, $destinationGitUrls[0], $sourceGitBranch);
    $this->checklist->completePreviousItem();

    $this->checklist->addItem('Generating build artifact');
    $this->buildArtifact($outputCallback, $artifactDir);
    $this->checklist->completePreviousItem();

    if (!$options['no-sanitize']) {
      $this->checklist->addItem('Sanitizing build artifact');
      $this->sanitizeArtifact($outputCallback, $artifactDir);
      $this->checklist->completePreviousItem();
    }

    if ($options['post-build-script']) {
      $this->checklist->addItem('Running post-build script');
      $process = $this->localMachineHelper->executeFromCmd($options['post-build-script'], $outputCallback, $artifactDir, TRUE);
      if (!$process->isSuccessful()) {
        $this->io()->error($process->getCommandLine());
        $this->io()->error($process->getOutput());
        throw new \RuntimeException('Failed to run post build script');
      }
      $this->checklist->completePreviousItem();
    }

    $this->checklist->addItem("Committing changes (commit hash: $commitHash)");
    $this->commit($outputCallback, $artifactDir, $commitHash, $options['tag']);
    $this->checklist->completePreviousItem();

    if (!$options['no-push']) {
      // TODO push tag instead of branch.
      //      $this->checklist->addItem("Pushing changes to <options=bold>$this->destinationGitRef</> branch.");
      //      $this->pushArtifact($outputCallback, $artifactDir, $destinationGitUrls, $this->destinationGitRef . ':' . $this->destinationGitRef);
      //      $this->checklist->completePreviousItem();
    }

    $this->logger()->success(dt('Artifact successfully built and pushed.'));
  }

  protected function ensureOption(string $name, callable $asker, bool $required): void {
    $value = $this->input->getOption($name);

    if ($value === NULL && $this->input->isInteractive()) {
      $value = $asker();
    }

    if ($required && $value === NULL) {
      throw new \InvalidArgumentException(dt('The !optionName option is required.', [
        '!optionName' => $name,
      ]));
    }

    $this->input->setOption($name, $value);
  }

  protected function askRepoRoot(): string {
    return $this->io()->askRequired('Repository root directory');
  }

  protected function askGitUrl(): string {
    return $this->io()->askRequired('Remote Git URL');
  }

  protected function askGitBranch(): string {
    return $this->io()->ask('Target branch');
  }

  private function validateSourceCode(): void {
    $requiredPaths = [
      $this->composerJsonPath,
      $this->docrootPath,
    ];
    foreach ($requiredPaths as $requiredPath) {
      if (!file_exists($requiredPath)) {
        throw new \RuntimeException("Your current directory does not look like a valid Drupal application. $requiredPath is missing.");
      }
    }
  }

  protected function isLocalGitRepoDirty(): bool {
    $this->localMachineHelper->checkRequiredBinariesExist(['git']);
    $process = $this->localMachineHelper->executeFromCmd(
    // Problem with this is that it stages changes for the user. They may
    // not want that.
      'git add . && git diff-index --cached --quiet HEAD',
      NULL,
      $this->dir,
      FALSE
    );

    return !$process->isSuccessful();
  }

  protected function getLocalGitCommitHash(): string {
    $this->localMachineHelper->checkRequiredBinariesExist(['git']);
    $process = $this->localMachineHelper->execute([
      'git',
      'rev-parse',
      'HEAD',
    ], NULL, $this->dir, FALSE);

    if (!$process->isSuccessful()) {
      throw new \RuntimeException('Unable to determine Git commit hash.');
    }

    return trim($process->getOutput());
  }

  protected function getOutputCallback(
    OutputInterface $output,
    Checklist $checklist
  ): \Closure {
    return static function (mixed $type, mixed $buffer) use ($checklist, $output): void {
      if (!$output->isVerbose() && $checklist->getItems()) {
        $checklist->updateProgressBar($buffer);
      }
      $output->writeln($buffer, OutputInterface::VERBOSITY_VERY_VERBOSE);
    };
  }

  /**
   * Prepare a directory to build the artifact.
   */
  private function cloneSourceBranch(
    \Closure $outputCallback,
    string $artifactDir,
    string $vcsUrl,
    string $vcsPath
  ): void {
    $fs = $this->localMachineHelper->getFilesystem();

    $outputCallback('out', "Removing $artifactDir if it exists");
    $fs->remove($artifactDir);

    $outputCallback('out', "Initializing Git in $artifactDir");
    $this->localMachineHelper->checkRequiredBinariesExist(['git']);
    $process = $this->localMachineHelper->execute(['git', 'clone', '--depth=1', $vcsUrl, $artifactDir],
      $outputCallback,
      NULL,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(sprintf('Failed to clone repository from the Cloud Platform: %s', $process->getErrorOutput()));
    }
    $process = $this->localMachineHelper->execute(['git', 'fetch', '--depth=1', '--update-head-ok', $vcsUrl, $vcsPath . ':' . $vcsPath],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    if (!$process->isSuccessful()) {
      // Remote branch does not exist. Just create it locally. This will create
      // the new branch off of the current commit.
      $process = $this->localMachineHelper->execute(['git', 'checkout', '-b', $vcsPath],
        $outputCallback,
        $artifactDir,
        ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    }
    else {
      $process = $this->localMachineHelper->execute(['git', 'checkout', $vcsPath],
        $outputCallback,
        $artifactDir,
        ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    }
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(
        sprintf('Could not checkout %s branch locally: %s%s', $vcsPath, $process->getErrorOutput(), $process->getOutput())
      );
    }

    $outputCallback('out', 'Global .gitignore file is temporarily disabled during artifact builds.');
    $this->localMachineHelper->execute(['git', 'config', '--local', 'core.excludesFile', 'false'],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    $this->localMachineHelper->execute(['git', 'config', '--local', 'core.fileMode', 'true'],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));

    // Vendor directories can be "corrupt" (i.e. missing scaffold files due to earlier sanitization) in ways that break composer install.
    $outputCallback('out', 'Removing vendor directories');
    foreach ($this->vendorDirs() as $vendorDirectory) {
      $fs->remove(Path::join($artifactDir, $vendorDirectory));
    }
  }

  private function vendorDirs($relativeDrupalDir = ''): array {
    if (!empty($this->vendorDirs) && empty($relativeDrupalDir)) {
      return $this->vendorDirs;
    }

    $this->vendorDirs = [
      $relativeDrupalDir . 'vendor',
    ];
    if (file_exists($this->composerJsonPath)) {
      $composerJson = json_decode($this->localMachineHelper->readFile($this->composerJsonPath), TRUE, 512, JSON_THROW_ON_ERROR);

      foreach ($composerJson['extra']['installer-paths'] as $path => $type) {
        $path = str_replace('/{$name}', '', $path);
        $this->vendorDirs[] = $relativeDrupalDir . str_replace('/{$name}', '', $path);
      }
      return $this->vendorDirs;
    }
    return [];
  }

  /**
   * Build the artifact.
   */
  private function buildArtifact(\Closure $outputCallback, string $artifactDir): void {
    $outputCallback('out', "Mirroring source files from $this->dir to $artifactDir");
    $originFinder = $this->localMachineHelper->getFinder();
    $originFinder->in($this->dir)
      // Include dot files like .htaccess.
      ->ignoreDotFiles(FALSE)
      // Ignore VCS ignored files (e.g. vendor) to speed up the mirror (Composer will restore them later).
      ->ignoreVCSIgnored(TRUE);
    $targetFinder = $this->localMachineHelper->getFinder();
    $targetFinder->in($artifactDir)->ignoreDotFiles(FALSE);
    $this->localMachineHelper->getFilesystem()->remove($targetFinder);
    $this->localMachineHelper->getFilesystem()->mirror($this->dir, $artifactDir, $originFinder, ['override' => TRUE]);

    $this->localMachineHelper->checkRequiredBinariesExist(['composer']);
    $outputCallback('out', 'Installing Composer production dependencies');
    $process = $this->localMachineHelper->execute(['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader'],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(
        sprintf('Unable to install composer dependencies: %s%s', $process->getOutput(), $process->getErrorOutput())
      );
    }
  }

  /**
   * Sanitize the artifact.
   */
  private function sanitizeArtifact(\Closure $outputCallback, string $artifactDir): void {
    $outputCallback('out', 'Finding Drupal core text files');
    $sanitizeFinder = $this->localMachineHelper->getFinder()
      ->files()
      ->name('*.txt')
      ->notName('LICENSE.txt')
      ->in("$artifactDir/docroot/core");

    $outputCallback('out', 'Finding VCS directories');
    $vcsFinder = $this->localMachineHelper->getFinder()
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE)
      ->directories()
      ->in([
        "$artifactDir/docroot",
        "$artifactDir/vendor",
      ])
      ->name('.git');
    $drushDir = "$artifactDir/drush";
    if (file_exists($drushDir)) {
      $vcsFinder->in($drushDir);
    }
    if ($vcsFinder->hasResults()) {
      $sanitizeFinder->append($vcsFinder);
    }

    $outputCallback('out', 'Finding INSTALL database text files');
    $dbInstallFinder = $this->localMachineHelper->getFinder()
      ->files()
      ->in([$artifactDir])
      ->name('/INSTALL\.[a-z]+\.(md|txt)$/');
    if ($dbInstallFinder->hasResults()) {
      $sanitizeFinder->append($dbInstallFinder);
    }

    $outputCallback('out', 'Finding other common text files');
    $filenames = [
      'AUTHORS',
      'CHANGELOG',
      'CONDUCT',
      'CONTRIBUTING',
      'INSTALL',
      'MAINTAINERS',
      'PATCHES',
      'TESTING',
      'UPDATE',
    ];
    $textFileFinder = $this->localMachineHelper->getFinder()
      ->files()
      ->in(["$artifactDir/docroot"])
      ->name('/(' . implode('|', $filenames) . ')\.(md|txt)$/');
    if ($textFileFinder->hasResults()) {
      $sanitizeFinder->append($textFileFinder);
    }

    $outputCallback('out', 'Removing sensitive files from build');
    $this->localMachineHelper->getFilesystem()->remove($sanitizeFinder);
  }

  /**
   * Commit the artifact.
   */
  private function commit(\Closure $outputCallback, string $artifactDir, string $commitHash, ?string $tag, string $relativeDrupalDir = ''): void {
    $outputCallback('out', 'Adding and committing changed files');
    $this->localMachineHelper->checkRequiredBinariesExist(['git']);
    $process = $this->localMachineHelper->execute(['git', 'add', '-A'],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(
        sprintf('Could not add files to artifact via git: %s%s', $process->getErrorOutput(), $process->getOutput())
      );
    }
    foreach (array_merge($this->vendorDirs($relativeDrupalDir), $this->scaffoldFiles($artifactDir, $relativeDrupalDir)) as $file) {
      $this->logger->debug("Forcibly adding $file");
      $this->localMachineHelper->execute(['git', 'add', '-f', $file], NULL, $artifactDir, FALSE);
      if (!$process->isSuccessful()) {
        // This will fatally error if the file doesn't exist. Suppress error output.
        $this->io->warning("Unable to forcibly add $file to new branch");
      }
    }
    $commitMessage = $this->generateCommitMessage($commitHash);
    $process = $this->localMachineHelper->execute(['git', 'commit', '-m', $commitMessage],
      $outputCallback,
      $artifactDir,
      ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(
        sprintf('Could not commit via git: %s%s', $process->getErrorOutput(), $process->getOutput())
      );
    }

    if ($tag) {
      $process = $this->localMachineHelper->execute(['git', 'tag', '-a', $tag, '-m', $tag],
        $outputCallback,
        $artifactDir,
        ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL));

      if (!$process->isSuccessful()) {
        throw new \RuntimeException(
          sprintf('Could not create git tag via git: %s%s', $process->getErrorOutput(), $process->getOutput())
        );
      }
    }
  }

  private function generateCommitMessage(string $commitHash): array|string {
    // TODO, get last commit message instead.
    return "Automated commit by SWS Deployment (source commit: $commitHash)";
  }

  /**
   * Get a list of scaffold files from Drupal core's composer.json.
   */
  private function scaffoldFiles(string $artifactDir, $relativeDrupalDir = ''): array {
    if (!empty($this->scaffoldFiles)) {
      return $this->scaffoldFiles;
    }

    $this->scaffoldFiles = [];
    $composerJson = json_decode(
      $this->localMachineHelper->readFile(Path::join($artifactDir . '/' . $relativeDrupalDir, 'docroot', 'core', 'composer.json')),
      TRUE,
      512,
      JSON_THROW_ON_ERROR
    );
    foreach ($composerJson['extra']['drupal-scaffold']['file-mapping'] as $file => $assetPath) {
      if (str_starts_with($file, '[web-root]')) {
        $this->scaffoldFiles[] = $relativeDrupalDir . str_replace('[web-root]', 'docroot', $file);
      }
    }
    $this->scaffoldFiles[] = $relativeDrupalDir . 'docroot/autoload.php';

    return $this->scaffoldFiles;
  }

  /**
   * Push the artifact.
   */
  private function pushArtifact(\Closure $outputCallback, string $artifactDir, array $vcsUrls, string $destGitBranch): void {
    $this->localMachineHelper->checkRequiredBinariesExist(['git']);
    foreach ($vcsUrls as $vcsUrl) {
      $outputCallback('out', "Pushing changes to Git ($vcsUrl)");
      $args = [
        'git',
        'push',
        $vcsUrl,
        $destGitBranch,
      ];
      $process = $this->localMachineHelper->execute(
        $args,
        $outputCallback,
        $artifactDir,
        ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL)
      );
      if (!$process->isSuccessful()) {
        throw new \RuntimeException(
          sprintf(
            'Unable to push artifact to remote repository: %s %s',
            $process->getOutput(),
            $process->getErrorOutput()
          )
        );
      }
    }
  }

}
