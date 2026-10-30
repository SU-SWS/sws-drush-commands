<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use AcquiaCloudApi\Response\OperationResponse;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drupal\SwsDrush\Helpers\AcquiaApi;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Commands for Acquia Cloud (ACE) multisite applications.
 *
 * Commands that act on every site run each site through Drush's own process
 * manager (`drush @self --uri=<site>`). Several sites can run at once: each
 * worker takes the next site from a shared queue as soon as it finishes, so
 * no worker sits idle while others still have work. Sites that aren't
 * installed are skipped rather than reported as failures.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class MultisiteDrushCommands extends DrushCommands {

  use AutowireTrait;
  use SwsCommandsTrait;

  public function __construct(
    private readonly SiteAliasManagerInterface $siteAliasManager,
  ) {
    parent::__construct();
  }

  /**
   * Generates a new multisite.
   *
   * Copies the default site directory, creates the drush aliases from
   * drush/sites/default.site.yml and adds the site to the multisites list in
   * drush/drush.yml.
   */
  #[CLI\Command(name: 'sws:multisite:new-site', aliases: ['multisite'])]
  #[CLI\Argument(name: 'site_name', description: 'Machine name of the multisite.')]
  #[CLI\Option(name: 'no-update-drush', description: 'Don\'t add the new site to the multisites list in drush/drush.yml.')]
  #[CLI\Usage(name: 'drush multisite foobar', description: 'New site and updates to drush config')]
  #[CLI\Usage(name: 'drush multisite foobar --no-update-drush', description: 'New site and no update to drush config')]
  public function newMultisite(string $site_name, array $options = [
    'no-update-drush' => FALSE,
  ]
  ): void {
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $site_name)) {
      throw new CommandFailedException('The site name must start with a letter and contain only lowercase letters, numbers and underscores.');
    }

    $siteDir = $this->siteDir($site_name);
    $defaultDir = $this->siteDir('default');
    if (file_exists($siteDir)) {
      throw new CommandFailedException("Cannot generate new multisite, $siteDir already exists!");
    }

    $created = [];

    // Copy the default site, leaving out uploaded files and local overrides.
    $finder = new Finder()->in($defaultDir)
      ->ignoreDotFiles(FALSE)
      ->exclude('files')
      ->notName('local.*');
    new Filesystem()->mirror($defaultDir, $siteDir, $finder);
    $created[] = $siteDir;

    // Point the site's remote alias at its own production alias.
    $swsYml = "$siteDir/sws.yml";
    if (file_exists($swsYml)) {
      $contents = (string) file_get_contents($swsYml);
      if (preg_match('/^(\s*remote-alias:).*$/m', $contents)) {
        // Edit the line in place to keep any comments in the file.
        $contents = preg_replace('/^(\s*remote-alias:).*$/m', "\$1 $site_name.prod", $contents);
      }
      else {
        $sws = Yaml::parse($contents) ?: [];
        $sws['site']['remote-alias'] = "$site_name.prod";
        $contents = Yaml::dump($sws, 2, 2);
      }
      file_put_contents($swsYml, $contents);
    }

    $defaultAlias = Path::join($this->getDir(), 'drush', 'sites', 'default.site.yml');
    if (file_exists($defaultAlias)) {
      $aliases = Yaml::parseFile($defaultAlias) ?: [];
      foreach ($aliases as $env => &$alias) {
        $alias['uri'] = $env === 'local' ? $site_name : "$site_name-$env.stanford.edu";
      }
      $aliasFile = Path::join($this->getDir(), 'drush', 'sites', "$site_name.site.yml");
      file_put_contents($aliasFile, Yaml::dump($aliases, 99, 2));
      $created[] = $aliasFile;
    }

    if (!$options['no-update-drush']) {
      $this->addToMultisitesList($site_name);
    }

    $this->io()->success("Created the $site_name multisite.");
    $this->io()->listing($created);
    $this->io()->text([
      'Next steps:',
      " - Run <comment>drush sws:multisite:settings</comment> to create the local settings for $site_name.",
      " - Install it with <comment>drush sws:multisite:install --site=$site_name</comment>.",
      ' - Commit the new files.',
    ]);
  }

  /**
   * Install Drupal.
   *
   * The install profile comes from the site's sws.yml (`site.profile`), then
   * the `project.profile` drush config, and falls back to stanford_profile.
   */
  #[CLI\Command(name: 'sws:multisite:install', aliases: [
    'drupal:install',
    'di',
  ])]
  #[CLI\Option(name: 'site', description: 'Machine name of site.')]
  #[CLI\Usage(name: 'drush drupal:install --site=foo -n', description: 'Install the foo site.')]
  public function siteInstall(array $options = [
    'site' => 'default',
  ]
  ): void {
    $site = $options['site'] ?: 'default';
    $siteDir = $this->siteDir($site);
    if (!is_dir($siteDir)) {
      throw new CommandFailedException("There is no $site site. $siteDir does not exist.");
    }

    $swsYml = "$siteDir/sws.yml";
    $siteProfile = file_exists($swsYml) ? (Yaml::parseFile($swsYml)['site']['profile'] ?? NULL) : NULL;
    $profile = $siteProfile ?: ($this->getConfig()
      ->get('project.profile') ?: 'stanford_profile');

    $this->io()
      ->writeln("Installing <comment>$site</comment> with the <comment>$profile</comment> profile...");
    $started = microtime(TRUE);
    $process = $this->drushProcess($site, [
      'site:install',
      $profile,
    ], ['verbose' => TRUE]);
    $process->run(fn($type, $buffer) => $this->output()->write($buffer));

    if (!$process->isSuccessful()) {
      throw new CommandFailedException("Failed to install $site.", $process->getExitCode() ?: 1);
    }
    $this->io()
      ->success(sprintf('Installed %s in %s.', $site, $this->formatDuration(microtime(TRUE) - $started)));
  }

  /**
   * Run database and config updates on all multisites, one at a time.
   */
  #[CLI\Command(name: 'sws:multisite:update')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to update')]
  #[CLI\Option(name: 'partial', description: 'Run updatedb, config:import --partial and deploy:hook instead of drush deploy.')]
  #[CLI\Option(name: 'show-output', description: 'Display each site\'s full output when it finishes.')]
  public function updateSites(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
    'show-output' => FALSE,
  ]
  ): void {
    $this->runUpdates($options, 1);
  }

  /**
   * Run database and config updates on all multisites in parallel.
   */
  #[CLI\Command(name: 'sws:multisite:update:parallel')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to update')]
  #[CLI\Option(name: 'partial', description: 'Run updatedb, config:import --partial and deploy:hook instead of drush deploy.')]
  #[CLI\Option(name: 'parallel-processes', description: 'How many sites to update at the same time. The UPDATE_PARALLEL_PROCESSES environment variable takes precedence.')]
  #[CLI\Option(name: 'show-output', description: 'Display each site\'s full output when it finishes.')]
  public function updateSitesParallel(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
    'parallel-processes' => 5,
    'show-output' => FALSE,
  ]
  ): void {
    $this->runUpdates($options, (int) getenv('UPDATE_PARALLEL_PROCESSES') ?: (int) $options['parallel-processes']);
  }

  /**
   * Update every site and report the results.
   *
   * @param array $options
   *   Command options.
   * @param int $concurrency
   *   Number of sites to update at the same time.
   */
  private function runUpdates(array $options, int $concurrency): void {
    $steps = $options['partial'] ? [
      ['updatedb'],
      ['config:import', '--partial'],
      ['deploy:hook'],
    ] : [
      ['deploy'],
    ];

    $env = getenv('AH_SITE_ENVIRONMENT') ?: 'local';
    $results = $this->runOnSites('Updating', $this->getMultisites($options), $steps, max(1, $concurrency), (bool) $options['show-output']);
    $updated = array_keys(array_filter($results, fn($result) => $result['status'] === 'success'));
    $failed = array_keys(array_filter($results, fn($result) => $result['status'] === 'failed'));

    $this->sendWebhookNotification(sprintf('Deployment to %s: %d sites updated successfully.', $env, count($updated)));
    if ($failed) {
      $this->sendWebhookNotification(sprintf('Deployment to %s failed for: %s', $env, implode(', ', $failed)));
      throw new CommandFailedException(sprintf('Update failed for %d site(s): %s', count($failed), implode(', ', $failed)));
    }
  }

  /**
   * Run the Drupal cron on all multisites.
   */
  #[CLI\Command(name: 'sws:multisite:cron')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to run cron on.')]
  public function cron(array $options = ['multisites' => ['default']]): void {
    $this->runCron('Drupal cron', ['core:cron'], $options);
  }

  /**
   * Run the Drupal Scheduler cron on all multisites to publish/unpublish nodes.
   */
  #[CLI\Command(name: 'sws:multisite:cron:scheduler')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to run the scheduler on.')]
  public function cronScheduler(array $options = ['multisites' => ['default']]): void {
    $this->runCron('Scheduler cron', ['cron:run', 'scheduler_cron'], $options);
  }

  /**
   * Run a cron command on every site, one at a time.
   *
   * @param string $label
   *   Name of the cron for messages.
   * @param string[] $command
   *   Drush command and arguments.
   * @param array $options
   *   Command options.
   */
  private function runCron(string $label, array $command, array $options): void {
    $results = $this->runOnSites("Running $label on", $this->getMultisites($options), [$command], 1, FALSE);
    $failed = array_keys(array_filter($results, fn($result) => $result['status'] === 'failed'));
    if ($failed) {
      $this->sendWebhookNotification(sprintf('%s failed on: %s', $label, implode(', ', $failed)));
      throw new CommandFailedException(sprintf('%s failed on: %s', $label, implode(', ', $failed)));
    }
  }

  /**
   * Copy every multisite database from production to another environment.
   *
   * Databases are copied one at a time, and each copy is followed until
   * Acquia reports it finished, so the results reflect what actually
   * happened.
   */
  #[CLI\Command(name: 'sws:multisite:sync-stage')]
  #[CLI\Option(name: 'exclude', description: 'Comma separated list of database names to skip.')]
  #[CLI\Option(name: 'force', description: 'Don\'t ask for confirmation. Same as -y.')]
  #[CLI\Option(name: 'env', description: 'Target environment. Can\'t be prod.')]
  #[CLI\Option(name: 'no-notify', description: 'Suppress Slack notification.')]
  #[CLI\Option(name: 'dry-run', description: 'List the databases that would be copied without copying them.')]
  #[CLI\Option(name: 'wait-timeout', description: 'Maximum seconds to wait for each copy to finish.')]
  #[CLI\Option(name: 'app-id', description: 'Acquia application ID')]
  #[CLI\Option(name: 'app-key', description: 'Acquia API key')]
  #[CLI\Option(name: 'app-secret', description: 'Acquia API secret')]
  #[CLI\Usage(name: 'drush sws:multisite:sync-stage --dry-run', description: 'List the databases that would be copied from prod to test.')]
  #[CLI\Usage(name: 'drush sws:multisite:sync-stage --env=dev --exclude=foo,bar -y', description: 'Copy every database except foo and bar to dev without asking.')]
  public function syncSitesStaging(array $options = [
    'exclude' => NULL,
    'force' => FALSE,
    'env' => 'test',
    'no-notify' => FALSE,
    'dry-run' => FALSE,
    'wait-timeout' => 1800,
    'app-id' => InputOption::VALUE_REQUIRED,
    'app-key' => InputOption::VALUE_REQUIRED,
    'app-secret' => InputOption::VALUE_REQUIRED,
  ]
  ): void {
    $fromEnv = 'prod';
    $toEnv = $options['env'] ?: 'test';
    if ($toEnv === $fromEnv) {
      throw new CommandFailedException('Refusing to copy databases over production.');
    }

    $acquiaApi = $this->getAcquiaApi();
    $appId = $this->input()->getOption('app-id');

    $uuids = [];
    foreach ($acquiaApi->acquiaEnvironments->getAll($appId) as $environment) {
      $uuids[$environment->name] = $environment->uuid;
    }
    foreach ([$fromEnv, $toEnv] as $env) {
      if (!isset($uuids[$env])) {
        throw new CommandFailedException(sprintf('There is no %s environment. Available environments: %s', $env, implode(', ', array_keys($uuids))));
      }
    }

    $databases = array_map(fn($database) => $database->name, $acquiaApi->acquiaDatabases->getNames($appId)
      ->getArrayCopy());
    $exclude = array_filter(array_map('trim', explode(',', (string) $options['exclude'])));
    if ($unknown = array_diff($exclude, $databases)) {
      $this->logger()
        ->warning('These excluded databases do not exist: ' . implode(', ', $unknown));
    }
    $databases = array_values(array_diff($databases, $exclude));
    if (!$databases) {
      $this->io()->success('No databases to copy.');
      return;
    }

    $this->io()
      ->writeln(sprintf('Databases to copy from <comment>%s</comment> to <comment>%s</comment>:', $fromEnv, $toEnv));
    $this->io()->listing($databases);
    if ($options['dry-run']) {
      $this->io()
        ->note(sprintf('Dry run: %d database(s) would be copied.', count($databases)));
      return;
    }
    if (!$options['force'] && !$this->io()
        ->confirm(sprintf('Overwrite %d database(s) on %s with copies from %s?', count($databases), $toEnv, $fromEnv))) {
      throw new UserAbortException();
    }

    $failed = [];
    foreach ($databases as $position => $database) {
      $this->io()
        ->writeln(sprintf(' <comment>[%d/%d]</comment> Copying %s...', $position + 1, count($databases), $database));
      $started = microtime(TRUE);
      try {
        $operation = $acquiaApi->acquiaDatabases->copy($uuids[$fromEnv], $database, $uuids[$toEnv]);
        $this->waitForOperation($acquiaApi, $operation, (int) $options['wait-timeout']);
        $this->io()
          ->writeln(sprintf(' <info>✔</info> %s <fg=gray>(%s)</>', $database, $this->formatDuration(microtime(TRUE) - $started)));
      }
      catch (\Throwable $e) {
        $failed[$database] = $e->getMessage();
        $this->io()
          ->writeln(sprintf(' <error>✘</error> %s: %s', $database, $e->getMessage()));
      }
    }

    $copied = count($databases) - count($failed);
    if (!$options['no-notify']) {
      $this->sendWebhookNotification(sprintf('%d databases synced from %s to %s.', $copied, $fromEnv, $toEnv) . ($failed ? ' Failed: ' . implode(', ', array_keys($failed)) : ''));
    }
    if ($failed) {
      throw new CommandFailedException(sprintf('Copied %d database(s), but %d failed: %s', $copied, count($failed), implode(', ', array_keys($failed))));
    }
    $this->io()->success("$copied database(s) copied to $toEnv.");
  }

  /**
   * Wait for an Acquia operation to finish.
   *
   * @throws \RuntimeException
   *   If the operation fails or doesn't finish in time.
   */
  private function waitForOperation(AcquiaApi $acquiaApi, OperationResponse $operation, int $timeout): void {
    $href = $operation->links->notification->href ?? NULL;
    if (!$href) {
      // Without a notification to follow, give the copy time to finish
      // before starting the next one.
      sleep(60);
      return;
    }

    $deadline = time() + $timeout;
    $notificationId = basename(parse_url($href, PHP_URL_PATH));
    while (time() < $deadline) {
      sleep(10);
      $status = $acquiaApi->acquiaNotifications->get($notificationId)->status;
      if ($status === 'completed') {
        return;
      }
      if ($status === 'failed') {
        throw new \RuntimeException('Acquia reported the copy failed.');
      }
    }
    throw new \RuntimeException(sprintf('The copy did not finish within %s.', $this->formatDuration($timeout)));
  }

  /**
   * Run drush commands on each site using a pool of workers.
   *
   * Each site first gets a status check, and is skipped if it isn't
   * installed. Then the steps run in order, stopping at the first failure.
   *
   * @param string $label
   *   What is being done, for messages.
   * @param string[] $sites
   *   Site names.
   * @param array $steps
   *   Drush command arguments for each step.
   * @param int $concurrency
   *   Number of sites to work on at the same time.
   * @param bool $showOutput
   *   Print each site's full output when it finishes.
   *
   * @return array
   *   Results keyed by site, each with 'status' (success, failed or skipped),
   *   'duration' and 'message'.
   */
  private function runOnSites(string $label, array $sites, array $steps, int $concurrency, bool $showOutput): array {
    $started = microtime(TRUE);
    $statusCheck = ['status', '--fields=install-profile', '--format=string'];
    $results = [];
    $running = [];

    // Sites without a directory can't be run at all.
    $queue = [];
    foreach ($sites as $site) {
      if (is_dir($this->siteDir($site))) {
        $queue[] = $site;
        continue;
      }
      $results[$site] = [
        'status' => 'skipped',
        'duration' => 0,
        'message' => 'no site directory',
      ];
      $this->output()
        ->writeln(" <fg=gray>-</> $site skipped: no site directory");
    }

    $this->io()
      ->writeln(sprintf('%s %d site(s)%s...', $label, count($queue), $concurrency > 1 ? ", $concurrency at a time" : ''));

    while ($queue || $running) {
      while ($queue && count($running) < $concurrency) {
        $site = array_shift($queue);
        $running[$site] = [
          'step' => -1,
          'started' => microtime(TRUE),
          'output' => '',
          'process' => $this->startDrush($site, $statusCheck),
        ];
      }

      usleep(100000);
      foreach ($running as $site => &$job) {
        $process = $job['process'];
        if ($process->isRunning()) {
          continue;
        }
        $command = $job['step'] < 0 ? $statusCheck : $steps[$job['step']];
        $job['output'] .= sprintf("$ drush %s\n%s%s\n", implode(' ', $command), $process->getOutput(), $process->getErrorOutput());

        $result = NULL;
        if (!$process->isSuccessful()) {
          $result = [
            'status' => 'failed',
            'message' => sprintf('%s failed (exit %d)', $command[0], $process->getExitCode()),
          ];
        }
        elseif ($job['step'] < 0 && !preg_match('/\w/', $process->getOutput())) {
          $result = ['status' => 'skipped', 'message' => 'not installed'];
        }
        elseif (isset($steps[$job['step'] + 1])) {
          $job['step']++;
          $job['process'] = $this->startDrush($site, $steps[$job['step']]);
          continue;
        }
        else {
          $result = ['status' => 'success', 'message' => ''];
        }

        $result['duration'] = microtime(TRUE) - $job['started'];
        $results[$site] = $result;
        $this->printSiteResult($site, $result, $job['output'], $showOutput);
        unset($running[$site]);
      }
      unset($job);
    }

    $counts = array_count_values(array_column($results, 'status'));
    $this->io()->newLine();
    $this->io()->definitionList(
      ['Succeeded' => $counts['success'] ?? 0],
      ['Failed' => $counts['failed'] ?? 0],
      ['Skipped' => $counts['skipped'] ?? 0],
      ['Time' => $this->formatDuration(microtime(TRUE) - $started)],
    );
    return $results;
  }

  /**
   * Print the outcome of one site.
   */
  private function printSiteResult(string $site, array $result, string $output, bool $showOutput): void {
    $duration = $this->formatDuration($result['duration']);
    match ($result['status']) {
      'success' => $this->output()
        ->writeln(" <info>✔</info> $site <fg=gray>($duration)</>"),
      'skipped' => $this->output()
        ->writeln(" <fg=gray>-</> $site skipped: {$result['message']}"),
      'failed' => $this->output()
        ->writeln(" <error>✘</error> $site: {$result['message']} <fg=gray>($duration)</>"),
    };

    if ($showOutput) {
      $this->output()->writeln(preg_replace('/^/m', '   │ ', rtrim($output)));
    }
    elseif ($result['status'] === 'failed') {
      // The end of the output is usually enough to see what went wrong.
      $lines = array_slice(array_filter(explode("\n", $output), 'trim'), -15);
      $this->output()
        ->writeln(preg_replace('/^/m', '   │ ', implode("\n", $lines)));
    }
  }

  /**
   * Start a drush command on a site without waiting for it.
   *
   * @param string $site
   *   Site name.
   * @param string[] $command
   *   Drush command, arguments and --options.
   */
  private function startDrush(string $site, array $command): Process {
    $process = $this->drushProcess($site, $command);
    $process->start();
    return $process;
  }

  /**
   * Build a drush process for a site.
   *
   * @param string $site
   *   Site name.
   * @param string[] $command
   *   Drush command, arguments and --options.
   * @param array $options
   *   Additional drush options.
   */
  private function drushProcess(string $site, array $command, array $options = []): Process {
    $process = $this->processManager()
      ->drush($this->siteAliasManager->getSelf(), array_shift($command), $command, $options + [
          'uri' => $site,
          'yes' => TRUE,
        ]);
    $process->setTimeout(NULL);
    return $process;
  }

  /**
   * Get the sites from the --multisites option or drush config.
   *
   * Accepts the option repeated or comma separated.
   */
  private function getMultisites(array $options): array {
    $sites = (array) ($options['multisites'] ?? []) ?: (array) $this->getConfig()
      ->get('command.sws.options.multisites', ['default']);
    return array_values(array_unique(array_filter(array_map('trim', explode(',', implode(',', $sites))))));
  }

  /**
   * Add a site to the multisites list in drush/drush.yml.
   *
   * The file is edited as text so its comments and formatting are kept.
   */
  private function addToMultisitesList(string $site): void {
    $file = Path::join($this->getDir(), 'drush', 'drush.yml');
    $contents = (string) file_get_contents($file);

    if (!preg_match('/^(?<indent>\s*)multisites:\s*\n(?<items>(?:\k<indent>\s+- .*\n?)+)/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
      $this->logger()
        ->warning("Couldn't find the multisites list in $file. Add $site to command.sws.options.multisites yourself.");
      return;
    }

    $items = $matches['items'][0];
    preg_match('/^(\s+- )/', $items, $prefix);
    $sites = array_map(fn($line) => trim(substr($line, strlen($prefix[1]))), array_filter(explode("\n", $items)));
    if (in_array($site, $sites, TRUE)) {
      return;
    }
    $sites[] = $site;
    sort($sites);

    $newItems = implode('', array_map(fn($name) => $prefix[1] . $name . "\n", $sites));
    $contents = substr_replace($contents, $newItems, $matches['items'][1], strlen($items));
    file_put_contents($file, $contents);
    $this->io()->writeln("Added $site to the multisites list in $file.");
  }

  /**
   * Absolute path to a site's directory.
   */
  private function siteDir(string $site): string {
    return Path::join($this->getDir(), 'docroot', 'sites', $site);
  }

  /**
   * Format seconds as "42s" or "3m 05s".
   */
  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
  }

}
