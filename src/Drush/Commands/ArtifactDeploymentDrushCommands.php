<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Build a deployable artifact of the codebase and push it to git.
 *
 * The artifact is the committed source code plus production composer
 * dependencies, sanitized of text files that shouldn't be public, committed
 * on top of the destination branch and pushed to one or more git remotes.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class ArtifactDeploymentDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Text files removed from the docroot when sanitizing.
   */
  private const SANITIZE_FILENAMES = [
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

  /**
   * Total number of steps, for the step counter.
   */
  private int $totalSteps = 0;

  /**
   * Current step number.
   */
  private int $currentStep = 0;

  /**
   * Build and push an artifact based on the current drupal installation.
   */
  #[CLI\Command(name: 'sws:artifact:deploy')]
  #[CLI\Option(name: 'drupal-core-folder', description: 'Drupal install folder e.g. docroot or web')]
  #[CLI\Option(name: 'git-url', description: 'Destination git repo url. Use multiple options for multiple urls. --git-url=foo --git-url=bar')]
  #[CLI\Option(name: 'branch', description: 'Destination branch name. Defaults to the current branch name with "-build" appended.')]
  #[CLI\Option(name: 'tag', description: 'Destination tag name. When given, only the tag is pushed.')]
  #[CLI\Option(name: 'commit-msg', description: 'Commit message string. Defaults to the message of the current commit.')]
  #[CLI\Option(name: 'no-sanitize', description: 'Do not sanitize the build artifact')]
  #[CLI\Option(name: 'no-push', description: 'Build and commit the artifact, but do not push it.')]
  #[CLI\Option(name: 'post-build-script', description: 'Shell script to run in the artifact directory after the build')]
  #[CLI\Option(name: 'artifact-dir', description: 'Directory to build the artifact')]
  #[CLI\Usage(name: 'drush sws:artifact:deploy -n', description: 'Deploy code to the branch name using all default settings')]
  #[CLI\Usage(name: 'drush sws:artifact:deploy --tag=2026-10-30_7.0.3 -n', description: 'Build the artifact and push it as a tag.')]
  #[CLI\Usage(name: 'drush sws:artifact:deploy --no-push -v', description: 'Build and commit the artifact locally to inspect it, showing every command\'s output.')]
  #[CLI\Help(description: 'The options can be configured in a drush.yml file and committed to the repository. See the example file for more information.')]
  public function buildCommand(
    array $options = [
      'drupal-core-folder' => 'docroot',
      'git-url' => [],
      'branch' => NULL,
      'tag' => NULL,
      'commit-msg' => NULL,
      'no-sanitize' => FALSE,
      'no-push' => FALSE,
      'post-build-script' => NULL,
      'artifact-dir' => NULL,
    ]
  ): void {
    $started = microtime(TRUE);
    $this->requireBinaries(['git', 'composer', 'tar']);

    $sourceDir = $this->getDir();
    $coreFolder = trim($options['drupal-core-folder'] ?: 'docroot', '/');
    foreach (['composer.json', $coreFolder] as $required) {
      if (!file_exists(Path::join($sourceDir, $required))) {
        throw new CommandFailedException("Your current directory does not look like a valid Drupal application. $required is missing.");
      }
    }

    // Check the source before asking any questions.
    $source = $this->getSourceInfo($sourceDir);

    $this->ensureOption('git-url', fn() => [
      $this->io()
        ->askRequired('Remote Git URL'),
    ], TRUE);
    $this->ensureOption('branch', fn() => $this->io()
      ->ask('Target branch', $source['branch'] ? "{$source['branch']}-build" : NULL));
    $this->ensureOption('tag', fn() => $this->io()->ask('Provide a tag name'));

    $gitUrls = array_values(array_filter((array) $this->input()
      ->getOption('git-url')));
    $tag = $this->input()->getOption('tag') ?: NULL;
    $branch = $this->input()
      ->getOption('branch') ?: ($source['branch'] ? "{$source['branch']}-build" : NULL);
    if (!$branch && !$tag) {
      throw new CommandFailedException('Unable to determine the destination branch from a detached HEAD. Pass --branch or --tag.');
    }

    $artifactDir = $options['artifact-dir'] ? Path::makeAbsolute($options['artifact-dir'], $sourceDir) : Path::join(sys_get_temp_dir(), 'drupal-artifact-build');
    // The artifact directory is deleted before each build.
    if (Path::isBasePath($artifactDir, $sourceDir)) {
      throw new CommandFailedException('The artifact directory cannot be the source directory or one of its parents.');
    }

    $this->io()->title('Artifact deploy');
    $this->io()->definitionList(
      ['Source' => sprintf('%s @ %s', $source['branch'] ?: 'detached HEAD', substr($source['hash'], 0, 10))],
      ['Destination' => $tag ? "tag $tag" . ($branch ? " (built on $branch)" : '') : "branch $branch"],
      ['Remotes' => implode("\n", $gitUrls)],
      ['Artifact dir' => $artifactDir],
    );

    $this->totalSteps = 5 + (int) !$options['no-sanitize'] + (int) (bool) $options['post-build-script'] + (int) !$options['no-push'];

    $this->step('Checking remotes', fn() => $this->checkRemotes($gitUrls, $branch, $tag));
    $baseRef = $this->step('Fetching destination branch', fn() => $this->prepareArtifactDir($artifactDir, $gitUrls, $branch));
    $this->step('Exporting source code', fn() => $this->exportSource($sourceDir, $artifactDir));
    $this->step('Installing production dependencies', fn() => $this->run([
      'composer',
      'install',
      '--no-dev',
      '--no-interaction',
      '--no-progress',
      '--optimize-autoloader',
    ], $artifactDir));

    if (!$options['no-sanitize']) {
      $this->step('Sanitizing artifact', fn() => $this->sanitize($artifactDir, $coreFolder));
    }
    if ($options['post-build-script']) {
      $this->step('Running post-build script', fn() => $this->run($options['post-build-script'], $artifactDir));
    }

    $message = $options['commit-msg'] ?: $source['message'];
    $message .= sprintf("\n\nBuilt from %s@%s", $source['branch'] ?: 'HEAD', $source['hash']);
    $commit = $this->step('Committing artifact', fn() => $this->commit($artifactDir, $coreFolder, $message, $tag));

    if (!$options['no-push']) {
      $refspec = $tag ? "refs/tags/$tag" : "HEAD:refs/heads/$branch";
      $this->step('Pushing to ' . count($gitUrls) . ' remote' . (count($gitUrls) > 1 ? 's' : ''), fn() => $this->push($artifactDir, $gitUrls, $refspec));
    }

    $this->io()->newLine();
    $this->io()->definitionList(
      ['Artifact commit' => $commit['hash']],
      ['Based on' => $baseRef],
      ['Changes' => $commit['stat']],
      ['Pushed' => $options['no-push'] ? 'no (--no-push)' : ($tag ? "tag $tag" : "branch $branch")],
      ['Time' => $this->formatDuration(microtime(TRUE) - $started)],
    );
    $this->io()
      ->success($options['no-push'] ? "Artifact built and committed in $artifactDir but not pushed." : 'Artifact successfully built and pushed.');
  }

  /**
   * Get the branch, commit and message of the source repository.
   *
   * The artifact is exported from the committed code, so uncommitted changes
   * would be silently left out. Refuse to continue if there are any.
   */
  private function getSourceInfo(string $sourceDir): array {
    $status = trim($this->run([
      'git',
      'status',
      '--porcelain',
      '--untracked-files=normal',
    ], $sourceDir, FALSE)->getOutput());
    if ($status) {
      $files = explode("\n", $status);
      $list = implode("\n", array_slice($files, 0, 20)) . (count($files) > 20 ? sprintf("\n... and %d more", count($files) - 20) : '');
      throw new CommandFailedException("Your local Git repository has uncommitted changes. Commit, reset, or stash them before deploying:\n$list");
    }

    $branch = trim($this->run([
      'git',
      'rev-parse',
      '--abbrev-ref',
      'HEAD',
    ], $sourceDir, FALSE)->getOutput());
    return [
      'branch' => $branch === 'HEAD' ? NULL : $branch,
      'hash' => trim($this->run(['git', 'rev-parse', 'HEAD'], $sourceDir, FALSE)
        ->getOutput()),
      'message' => trim($this->run([
        'git',
        'log',
        '-1',
        '--pretty=%B',
      ], $sourceDir, FALSE)->getOutput()),
    ];
  }

  /**
   * Make sure every remote is reachable and the tag isn't already taken.
   *
   * Doing this first means a typo or a permissions problem fails in seconds
   * instead of after the whole build.
   */
  private function checkRemotes(array $gitUrls, ?string $branch, ?string $tag): string {
    $processes = [];
    foreach ($gitUrls as $url) {
      $processes[$url] = $this->start([
        'git',
        'ls-remote',
        '--heads',
        '--tags',
        $url,
      ]);
    }

    $errors = [];
    foreach ($processes as $url => $process) {
      $process->wait();
      if (!$process->isSuccessful()) {
        $errors[] = "$url: " . trim($process->getErrorOutput());
        continue;
      }
      if ($tag && preg_match('#\srefs/tags/' . preg_quote($tag, '#') . '$#m', $process->getOutput())) {
        $errors[] = "$url: tag $tag already exists.";
      }
    }
    if ($errors) {
      throw new \RuntimeException(implode("\n", $errors));
    }

    if (!$branch) {
      return 'ok';
    }
    $exists = preg_match('#\srefs/heads/' . preg_quote($branch, '#') . '$#m', $processes[$gitUrls[0]]->getOutput());
    return $exists ? "$branch exists" : "$branch will be created";
  }

  /**
   * Check out the destination branch into an empty artifact directory.
   *
   * Only the latest commit is fetched when there is a single remote. With
   * several remotes the full branch history is fetched, since the other
   * remotes may not have the parent commits and would reject a shallow push.
   *
   * @return string
   *   Description of the commit the artifact is built on.
   */
  private function prepareArtifactDir(string $artifactDir, array $gitUrls, ?string $branch): string {
    $fs = new Filesystem();
    $fs->remove($artifactDir);
    $fs->mkdir($artifactDir);

    $depth = count($gitUrls) === 1 ? ['--depth=1'] : [];
    $this->run(['git', 'init', '--quiet'], $artifactDir);
    $this->run(['git', 'remote', 'add', 'origin', $gitUrls[0]], $artifactDir);
    // Don't let a global gitignore leave files out of the artifact.
    $this->run([
      'git',
      'config',
      '--local',
      'core.excludesFile',
      'false',
    ], $artifactDir);
    $this->run([
      'git',
      'config',
      '--local',
      'core.fileMode',
      'true',
    ], $artifactDir);

    if ($branch && $this->run([
        'git',
        'fetch',
        '--quiet',
        '--no-tags',
        ...$depth,
        'origin',
        "refs/heads/$branch",
      ], $artifactDir, FALSE)->isSuccessful()) {
      $base = "origin/$branch";
    }
    else {
      // A new branch, or a tag without a branch, starts from the remote's
      // default branch.
      $this->run([
        'git',
        'fetch',
        '--quiet',
        '--no-tags',
        ...$depth,
        'origin',
        'HEAD',
      ], $artifactDir);
      $base = 'the default branch';
    }
    $this->run([
      'git',
      'checkout',
      '--quiet',
      '-B',
      $branch ?: 'artifact',
      'FETCH_HEAD',
    ], $artifactDir);

    return sprintf('%s (%s)', $base, trim($this->run([
      'git',
      'log',
      '-1',
      '--format=%h %s',
    ], $artifactDir)->getOutput()));
  }

  /**
   * Replace the artifact's files with the committed source code.
   *
   * `git archive` exports exactly what is committed, which is faster than
   * walking the file system and can't pick up stray local files.
   */
  private function exportSource(string $sourceDir, string $artifactDir): string {
    $fs = new Filesystem();
    $fs->remove(new Finder()->in($artifactDir)
      ->depth(0)
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(TRUE));

    $this->run(sprintf('git -C %s archive --format=tar HEAD | tar -x -C %s', escapeshellarg($sourceDir), escapeshellarg($artifactDir)), $artifactDir);
    return sprintf('%d files', iterator_count(new Finder()->files()
      ->in($artifactDir)
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(TRUE)));
  }

  /**
   * Remove text files that reveal versions or aren't needed in production.
   *
   * @return string
   *   Summary of what was removed.
   */
  private function sanitize(string $artifactDir, string $coreFolder): string {
    $docroot = Path::join($artifactDir, $coreFolder);
    $finders = [];

    if (is_dir("$docroot/core")) {
      $finders[] = new Finder()->files()
        ->in("$docroot/core")
        ->name('*.txt')
        ->notName('LICENSE.txt');
    }
    $finders[] = new Finder()->files()
      ->in($artifactDir)
      ->exclude('.git')
      ->name('/INSTALL\.[a-z]+\.(md|txt)$/');
    $finders[] = new Finder()->files()
      ->in($docroot)
      ->name('/^(' . implode('|', self::SANITIZE_FILENAMES) . ')\.(md|txt)$/');

    // Nested repositories from packages installed from source.
    $vcsDirs = array_filter([
      $docroot,
      "$artifactDir/vendor",
      "$artifactDir/drush",
    ], 'is_dir');
    $finders[] = new Finder()->directories()
      ->in($vcsDirs)
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE)
      ->name('.git');

    $paths = [];
    foreach ($finders as $finder) {
      foreach ($finder as $file) {
        $paths[$file->getPathname()] = $file->getPathname();
      }
    }
    (new Filesystem())->remove($paths);
    return sprintf('%d files removed', count($paths));
  }

  /**
   * Commit the artifact, and tag it if requested.
   *
   * @return array
   *   Keyed array with the commit 'hash' and a 'stat' summary of the changes.
   */
  private function commit(string $artifactDir, string $coreFolder, string $message, ?string $tag): array {
    $this->run(['git', 'add', '--all'], $artifactDir);

    // Composer managed directories and scaffold files are ignored in the
    // source repository, but they must be in the artifact.
    $forced = array_filter([
      ...$this->vendorDirs($artifactDir),
      ...$this->scaffoldFiles($artifactDir, $coreFolder),
    ], fn($path) => file_exists(Path::join($artifactDir, $path)));
    if ($forced) {
      $this->run(['git', 'add', '--force', '--', ...$forced], $artifactDir);
    }

    $stat = trim($this->run([
      'git',
      'diff',
      '--cached',
      '--shortstat',
    ], $artifactDir)->getOutput());
    if ($stat) {
      $this->run([
        'git',
        'commit',
        '--quiet',
        '--no-verify',
        '-m',
        $message,
      ], $artifactDir);
    }
    if ($tag) {
      $this->run(['git', 'tag', '--annotate', $tag, '-m', $tag], $artifactDir);
    }

    return [
      'hash' => trim($this->run([
        'git',
        'rev-parse',
        '--short',
        'HEAD',
      ], $artifactDir)->getOutput()),
      'stat' => $stat ?: 'none, the destination already matches this build',
    ];
  }

  /**
   * Push to every remote at once.
   */
  private function push(string $artifactDir, array $gitUrls, string $refspec): string {
    $processes = [];
    foreach ($gitUrls as $url) {
      $processes[$url] = $this->start([
        'git',
        'push',
        '--porcelain',
        $url,
        $refspec,
      ], $artifactDir);
    }

    $errors = [];
    foreach ($processes as $url => $process) {
      $process->wait();
      if (!$process->isSuccessful()) {
        $errors[] = "$url:\n" . $this->tail($process->getErrorOutput() . $process->getOutput());
      }
    }
    if ($errors) {
      throw new \RuntimeException("Unable to push to:\n" . implode("\n", $errors));
    }
    return $refspec;
  }

  /**
   * Composer installer paths and the vendor directory, relative to the root.
   */
  private function vendorDirs(string $artifactDir): array {
    $composerJson = json_decode((string) file_get_contents("$artifactDir/composer.json"), TRUE, 512, JSON_THROW_ON_ERROR);
    $dirs = [$composerJson['config']['vendor-dir'] ?? 'vendor'];
    foreach (array_keys($composerJson['extra']['installer-paths'] ?? []) as $path) {
      $dirs[] = rtrim(str_replace('{$name}', '', $path), '/');
    }
    return array_unique($dirs);
  }

  /**
   * Drupal core's scaffold files that are placed in the docroot.
   */
  private function scaffoldFiles(string $artifactDir, string $coreFolder): array {
    $file = Path::join($artifactDir, $coreFolder, 'core', 'composer.json');
    if (!file_exists($file)) {
      return ["$coreFolder/autoload.php"];
    }

    $composerJson = json_decode((string) file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
    $files = ["$coreFolder/autoload.php"];
    foreach (array_keys($composerJson['extra']['drupal-scaffold']['file-mapping'] ?? []) as $path) {
      if (str_starts_with($path, '[web-root]')) {
        $files[] = str_replace('[web-root]', $coreFolder, $path);
      }
    }
    return $files;
  }

  /**
   * Run one step of the deployment, showing its progress and duration.
   *
   * @param string $label
   *   What the step does.
   * @param callable $callback
   *   The work. A string return value is shown as the step's result.
   *
   * @return mixed
   *   Whatever the callback returns.
   */
  private function step(string $label, callable $callback): mixed {
    $this->currentStep++;
    $prefix = sprintf('[%d/%d]', $this->currentStep, $this->totalSteps);
    $this->output()
      ->writeln(sprintf(' <comment>%s</comment> %s...', $prefix, $label));

    $started = microtime(TRUE);
    try {
      $result = $callback();
    }
    catch (\Throwable $e) {
      $this->output()
        ->writeln(sprintf(' <error>✘</error> %s failed after %s', $label, $this->formatDuration(microtime(TRUE) - $started)));
      throw new CommandFailedException("$label failed.\n\n" . $e->getMessage(), 1, $e);
    }

    $detail = is_string($result) ? $result : (is_array($result) && isset($result['stat']) ? $result['stat'] : '');
    $this->output()
      ->writeln(sprintf(' <info>✔</info> %s%s <fg=gray>(%s)</>', $label, $detail ? ": $detail" : '', $this->formatDuration(microtime(TRUE) - $started)));
    return $result;
  }

  /**
   * Run a command and wait for it.
   *
   * Output is streamed in verbose mode. Otherwise it's captured and the end
   * of it is included in the error if the command fails.
   *
   * @param string[]|string $command
   *   Command arguments, or a shell command line.
   * @param string|null $cwd
   *   Working directory.
   * @param bool $mustSucceed
   *   Throw an exception if the command fails.
   */
  private function run(array|string $command, ?string $cwd = NULL, bool $mustSucceed = TRUE): Process {
    $process = is_string($command) ? Process::fromShellCommandline($command, $cwd) : new Process($command, $cwd);
    $process->setTimeout(NULL);

    $verbose = $this->output()->isVerbose();
    if ($verbose) {
      $this->output()
        ->writeln('   <fg=gray>$ ' . $process->getCommandLine() . '</>');
    }
    $process->run(function($type, $buffer) use ($verbose) {
      if ($verbose) {
        $this->output()->write(preg_replace('/^/m', '   │ ', $buffer));
      }
    });

    if ($mustSucceed && !$process->isSuccessful()) {
      throw new \RuntimeException(sprintf("Command failed (exit %d): %s\n%s", $process->getExitCode(), $process->getCommandLine(), $this->tail($process->getErrorOutput() . $process->getOutput())));
    }
    return $process;
  }

  /**
   * Start a command without waiting for it.
   */
  private function start(array $command, ?string $cwd = NULL): Process {
    $process = new Process($command, $cwd);
    $process->setTimeout(NULL);
    $process->start();
    return $process;
  }

  /**
   * Make sure the required programs are installed.
   */
  private function requireBinaries(array $binaries): void {
    $finder = new ExecutableFinder();
    $missing = array_filter($binaries, fn($binary) => !$finder->find($binary));
    if ($missing) {
      throw new CommandFailedException('Required programs are not installed or not in your $PATH: ' . implode(', ', $missing));
    }
  }

  /**
   * Get the last lines of command output.
   */
  private function tail(string $output, int $lines = 20): string {
    return implode("\n", array_slice(explode("\n", trim($output)), -$lines));
  }

  /**
   * Format seconds as "42s" or "3m 05s".
   */
  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
  }

}
