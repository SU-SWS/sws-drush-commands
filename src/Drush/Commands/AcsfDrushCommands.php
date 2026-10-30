<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\CommandFailedException;
use GuzzleHttp\Client;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Run database updates and config imports across every site in parallel.
 *
 * Instead of spawning one long-lived
 * process per webhost that walks its own fixed list of sites (and syncing
 * progress through JSON files on disk), this command keeps a single shared
 * queue in memory and a pool of worker slots. As soon as a slot finishes a
 * site it pulls the next one off the queue, so every slot stays busy until
 * the queue is empty. Each site runs through Drush's own SiteProcess, which
 * talks to the remote host over SSH directly.
 *
 * Progress is saved to a state file in the log directory after every site, so
 * an interrupted run (computer crash, closed laptop, Ctrl+C) can be picked up
 * with --resume. If the network drops mid-run, the workers pause until the
 * webhosts are reachable again instead of burning through every site's
 * retries.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class AcsfDrushCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Set when the user presses Ctrl+C. No new sites are started.
   */
  private bool $stopRequested = FALSE;

  /**
   * Set when the user presses Ctrl+C twice. Running sites are terminated.
   */
  private bool $abortRequested = FALSE;

  /**
   * Directory where each site's output is logged.
   */
  private string $logDir;

  /**
   * Slack thread timestamp so all messages are replies to the first one.
   */
  private ?string $slackThread = NULL;

  /**
   * Stop trying to send Slack messages after the first failure.
   */
  private bool $slackFailed = FALSE;

  /**
   * Persisted progress of the run, saved to state.json in the log directory.
   */
  private array $state = [];

  /**
   * Open handle holding the exclusive lock on the log directory.
   *
   * @var resource|null
   */
  private $lock = NULL;

  public function __construct(
    private readonly SiteAliasManagerInterface $siteAliasManager,
  ) {
    parent::__construct();
  }

  /**
   * Perform database updates and config imports across all sites.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment')]
  #[CLI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[CLI\Option(name: 'concurrency', description: 'Number of sites to update at the same time. Defaults to 3 on dev and test, which share a single staging host, and 10 on live.')]
  #[CLI\Option(name: 'max-per-host', description: 'Maximum number of simultaneous sites on a single webhost. 0 for no limit.')]
  #[CLI\Option(name: 'separate-db-config', description: 'Run all database updates first across all sites before starting config imports.')]
  #[CLI\Option(name: 'delay-config-import', description: 'Delay the configuration import by the given number of seconds. Only with --separate-db-config.')]
  #[CLI\Option(name: 'delay-between-sites', description: 'Seconds each worker waits before starting its next site.')]
  #[CLI\Option(name: 'attempts', description: 'Number of times to try each site before marking it as failed.')]
  #[CLI\Option(name: 'timeout', description: 'Maximum seconds for a single site attempt. 0 for no limit.')]
  #[CLI\Option(name: 'sites', description: 'Comma separated list of site names to limit the update to.')]
  #[CLI\Option(name: 'log-dir', description: 'Directory to write each site\'s output and progress to. Defaults to a new directory in ~/.drush/cs-update.')]
  #[CLI\Option(name: 'resume', description: 'Resume an interrupted run. Pass the log directory of the run, or "last" for the most recent unfinished run. Previously failed sites are tried again.')]
  #[CLI\Option(name: 'connection-retries', description: 'Number of times a site can fail from a lost SSH connection before it counts as a failed attempt.')]
  #[CLI\Option(name: 'slack-token', description: 'Bot User OAuth Token. Must have "chat:write" scope permissions.')]
  #[CLI\Option(name: 'slack-channel', description: 'An encoded ID or channel name that represents a channel, private group, or IM channel to send the message to.')]
  #[CLI\Usage(name: 'drush sws:acsf:update-environment --env=test --concurrency=12', description: 'Run `drush deploy` on every test site, 12 at a time.')]
  #[CLI\Usage(name: 'drush sws:acsf:update-environment --env=live --separate-db-config --delay-config-import=600', description: 'Run updatedb on all live sites, wait 10 minutes, then import config.')]
  #[CLI\Usage(name: 'drush sws:acsf:update-environment --env=dev --sites=foo,bar', description: 'Only update the foo and bar dev sites.')]
  #[CLI\Usage(name: 'drush sws:acsf:update-environment --env=dev --simulate', description: 'Show what would run without running it.')]
  #[CLI\Usage(name: 'drush sws:acsf:update-environment --resume=last', description: 'Pick up the most recent unfinished run where it left off.')]
  public function updateEnvironment(array $options = [
    'env' => 'dev',
    'concurrency' => NULL,
    'max-per-host' => 0,
    'separate-db-config' => FALSE,
    'delay-config-import' => 0,
    'delay-between-sites' => 0,
    'attempts' => 3,
    'timeout' => 1800,
    'sites' => NULL,
    'log-dir' => NULL,
    'resume' => NULL,
    'connection-retries' => 5,
    'slack-token' => NULL,
    'slack-channel' => NULL,
  ]
  ): void {
    if ($options['resume']) {
      $sites = $this->resumeState((string) $options['resume']);
    }
    else {
      $sites = $this->initializeState($options);
    }

    $env = $this->state['env'];
    $stack = $this->state['stack'];
    $options['concurrency'] ??= $env === 'live' ? 10 : 3;
    $hosts = array_unique(array_map(fn(SiteAliasInterface $alias) => (string) $alias->get('host'), $sites));

    $this->io()->title(($options['resume'] ? 'Resuming' : 'Updating') . " $stack.01$env");
    $this->io()->definitionList(
      ['Sites' => count($sites)],
      ['Webhosts' => count($hosts)],
      ['Concurrency' => (int) $options['concurrency']],
      ['Logs' => $this->logDir],
    );

    $this->registerSignalHandlers();
    $this->sendSlackMessage(($options['resume'] ? 'Resuming' : 'Beginning') . " updates on `$stack.01$env`.");

    $commands = $this->state['separate'] ? ['updatedb', 'config:import'] : ['deploy'];
    foreach ($commands as $command) {
      // Config is only imported on sites whose database updates succeeded.
      $phaseSites = $command === 'config:import' ? array_intersect_key($sites, array_flip($this->state['phases']['updatedb']['completed'])) : $sites;

      $this->state['phases'][$command] ??= ['completed' => [], 'failed' => []];
      $completed = $this->state['phases'][$command]['completed'];
      // Anything that failed on a previous run gets another chance.
      $this->state['phases'][$command]['failed'] = [];
      $remaining = array_diff_key($phaseSites, array_flip($completed));

      if (!$remaining) {
        continue;
      }

      if ($command === 'config:import') {
        if (!isset($this->state['configImportAfter'])) {
          $this->state['configImportAfter'] = time() + max(0, (int) $options['delay-config-import']);
          $this->saveState();
        }
        $this->countdown($this->state['configImportAfter'] - time());
        if ($this->stopRequested) {
          break;
        }
        $this->sendSlackMessage('Beginning config imports.');
      }

      $this->runQueue($command, $remaining, $options, count($phaseSites));
      if ($this->stopRequested) {
        break;
      }
    }

    $this->restoreSignalHandlers();
    $this->reportResults($sites);
  }

  /**
   * Set up the log directory and state for a new run.
   *
   * @param array $options
   *   Command options.
   *
   * @return \Consolidation\SiteAlias\SiteAliasInterface[]
   *   Site aliases keyed by alias name.
   */
  private function initializeState(array $options): array {
    $env = $options['env'];
    if (!in_array($env, ['dev', 'test', 'live'], TRUE)) {
      throw new CommandFailedException('Invalid environment option. Use dev, test, or live.');
    }

    $sites = $this->getSiteAliases($env, $options['sites'] ? explode(',', $options['sites']) : []);
    if (!$sites) {
      throw new CommandFailedException('No sites to update. Make sure aliases are created by using the `drush aliases` command.');
    }
    $stack = preg_replace('/\..*/', '', (string) reset($sites)->get('user'));

    // Simulated runs don't touch the default directory so they can never be
    // mistaken for a real run when resuming.
    $baseDir = Drush::simulate() ? sys_get_temp_dir() : $this->stateBaseDir();
    $this->logDir = rtrim($options['log-dir'] ?: "$baseDir/$stack-$env-" . date('Ymd-His'), '/');
    if (file_exists("{$this->logDir}/state.json")) {
      throw new CommandFailedException("{$this->logDir} already contains a run. Use --resume={$this->logDir} to continue it.");
    }
    if (!is_dir($this->logDir) && !mkdir($this->logDir, 0775, TRUE)) {
      throw new CommandFailedException("Unable to create log directory {$this->logDir}.");
    }
    $this->acquireLock();

    $this->state = [
      'stack' => $stack,
      'env' => $env,
      'separate' => (bool) $options['separate-db-config'],
      'sites' => array_keys($sites),
      'slackThread' => NULL,
      'started' => date('c'),
      'updated' => NULL,
      'finished' => FALSE,
      'phases' => [],
    ];
    $this->saveState();
    return $sites;
  }

  /**
   * Load the state of a previous run so it can continue where it stopped.
   *
   * @param string $resume
   *   Log directory of the run, or "last" for the newest unfinished run.
   *
   * @return \Consolidation\SiteAlias\SiteAliasInterface[]
   *   Site aliases keyed by alias name.
   */
  private function resumeState(string $resume): array {
    $dir = $resume;
    if (in_array($resume, ['last', '1'], TRUE)) {
      $dir = NULL;
      $stateFiles = glob($this->stateBaseDir() . '/*/state.json') ?: [];
      usort($stateFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
      foreach ($stateFiles as $file) {
        $data = json_decode((string) file_get_contents($file), TRUE);
        if ($data && empty($data['finished'])) {
          $dir = dirname($file);
          break;
        }
      }
      if (!$dir) {
        throw new CommandFailedException('There are no unfinished runs in ' . $this->stateBaseDir() . '.');
      }
    }

    $this->logDir = rtrim($dir, '/');
    $file = "{$this->logDir}/state.json";
    if (!is_file($file)) {
      throw new CommandFailedException("No saved progress found at $file.");
    }
    $this->acquireLock();

    $this->state = json_decode((string) file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!empty($this->state['finished'])) {
      throw new CommandFailedException("The run in {$this->logDir} already finished successfully.");
    }
    $this->slackThread = $this->state['slackThread'] ?? NULL;

    $sites = [];
    foreach ($this->state['sites'] as $name) {
      if ($alias = $this->siteAliasManager->get($name)) {
        $sites[$name] = $this->prepareAlias($alias);
        continue;
      }
      $this->logger()->warning("The $name alias no longer exists and will be skipped.");
    }
    if (!$sites) {
      throw new CommandFailedException('None of the sites in the saved run have aliases any more.');
    }
    return $sites;
  }

  /**
   * Directory where each run's log directory is created by default.
   */
  private function stateBaseDir(): string {
    return rtrim((string) getenv('HOME'), '/') . '/.drush/cs-update';
  }

  /**
   * Make sure no other process is working on the same run.
   *
   * The lock is released automatically if this process dies, so a crashed
   * run can always be resumed.
   */
  private function acquireLock(): void {
    $this->lock = fopen("{$this->logDir}/.lock", 'c');
    if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) {
      throw new CommandFailedException("Another process is already running the update in {$this->logDir}.");
    }
  }

  /**
   * Write the state to disk.
   *
   * Written to a temporary file and renamed so a crash mid-write can't leave
   * a corrupt state file behind.
   */
  private function saveState(): void {
    if (Drush::simulate()) {
      return;
    }
    $this->state['updated'] = date('c');
    $this->state['slackThread'] = $this->slackThread;
    $file = "{$this->logDir}/state.json";
    file_put_contents("$file.tmp", json_encode($this->state, JSON_PRETTY_PRINT));
    rename("$file.tmp", $file);
  }

  /**
   * Run a drush command on every site using a pool of worker slots.
   *
   * @param string $command
   *   Drush command to run on each site.
   * @param \Consolidation\SiteAlias\SiteAliasInterface[] $sites
   *   Site aliases keyed by alias name.
   * @param array $options
   *   Command options.
   * @param int $total
   *   Total number of sites in this phase, including ones already completed.
   */
  private function runQueue(string $command, array $sites, array $options, int $total): void {
    $concurrency = max(1, (int) $options['concurrency']);
    $maxPerHost = max(0, (int) $options['max-per-host']);
    $maxAttempts = max(1, (int) $options['attempts']);
    $delay = max(0, (float) $options['delay-between-sites']);
    $timeout = (float) $options['timeout'] ?: NULL;
    $connectionRetries = max(0, (int) $options['connection-retries']);

    $queue = $this->buildQueue($sites);

    // Each running job keyed by worker slot.
    $running = [];
    // When each slot is allowed to start its next site.
    $slotReadyAt = array_fill(0, $concurrency, 0.0);
    // Number of running jobs on each host.
    $hostLoad = [];
    $completed = $total - count($queue);
    $failed = [];
    $lastDecile = (int) floor($completed / $total * 10);
    // Set while the network is down: the host being probed and when to next
    // check it.
    $outage = NULL;

    $this->io()->section(sprintf('Running `drush %s` on %d sites', $command, count($queue)) . ($completed ? " ($completed already done)" : ''));
    $progress = $this->createProgressBar($total, $command, $completed);

    while ($queue || $running) {
      // Collect finished jobs.
      foreach ($running as $slot => $job) {
        /** @var \Symfony\Component\Process\Process $process */
        $process = $job['process'];

        if ($this->abortRequested) {
          $process->stop(5);
        }

        try {
          $process->checkTimeout();
        }
        catch (ProcessTimedOutException) {
          // The process has been stopped, handle it as a failed attempt.
          $job['timedOut'] = TRUE;
        }

        if ($process->isRunning()) {
          continue;
        }

        unset($running[$slot]);
        $hostLoad[$job['host']]--;
        $slotReadyAt[$slot] = microtime(TRUE) + $delay;
        $this->logAttempt($command, $job, $process);

        if (!$process->isSuccessful() && $this->isConnectionFailure($process) && $job['connectionFailures'] < $connectionRetries && !$this->stopRequested) {
          // The site never got a fair attempt, so put it back at the front
          // without using up one of its attempts, and pause new work until
          // the network is back.
          $job['connectionFailures']++;
          array_unshift($queue, array_intersect_key($job, array_flip(['alias', 'host', 'attempt', 'connectionFailures'])));
          if (!$outage) {
            $outage = ['alias' => $sites[$job['alias']], 'nextCheck' => microtime(TRUE) + 5];
            $this->writeAboveProgress($progress, sprintf('<comment>⚠</comment> Lost connection to %s. Pausing until it is reachable again.', $job['host']));
            $this->sendSlackMessage(sprintf('Lost connection to `%s`. Updates paused until it is reachable again.', $job['host']));
          }
          continue;
        }

        if ($process->isSuccessful()) {
          $completed++;
          $this->state['phases'][$command]['completed'][] = $job['alias'];
          $this->saveState();
          $this->writeAboveProgress($progress, sprintf('<info>✔</info> [%d/%d] %s', $completed, $total, $job['alias']), $this->output()->isDecorated());
        }
        elseif ($job['attempt'] < $maxAttempts && !$this->stopRequested) {
          // Send it to the back of the queue so a struggling site doesn't
          // hold up the rest of the work.
          $job['attempt']++;
          $queue[] = array_intersect_key($job, array_flip(['alias', 'host', 'attempt', 'connectionFailures']));
          $this->writeAboveProgress($progress, sprintf('<comment>↻</comment> %s failed (%s), will retry. Attempt %d of %d. Log: %s', $job['alias'], $this->exitCodeLabel($process, $job), $job['attempt'], $maxAttempts, $this->logFile($job['alias'])));
          continue;
        }
        else {
          $completed++;
          $failed[] = $job['alias'];
          $this->state['phases'][$command]['failed'][] = $job['alias'];
          $this->saveState();
          $this->writeAboveProgress($progress, sprintf('<error>✘</error> %s failed (%s). Log: %s%s', $job['alias'], $this->exitCodeLabel($process, $job), $this->logFile($job['alias']), $this->tail($process->getErrorOutput() ?: $process->getOutput())));
        }

        $progress->setMessage((string) count($failed), 'failed');
        $progress->advance();

        // Post progress to slack at every 10%.
        $decile = (int) floor($completed / $total * 10);
        if ($decile > $lastDecile && $decile < 10) {
          $lastDecile = $decile;
          $this->sendSlackMessage(sprintf('%d%% completed `%s`.', $decile * 10, $command));
        }
      }

      // While the network is down, check every so often if it's back.
      if ($outage && microtime(TRUE) >= $outage['nextCheck']) {
        if ($this->isReachable($outage['alias'])) {
          $outage = NULL;
          $this->writeAboveProgress($progress, '<info>✔</info> Connection restored. Resuming updates.');
          $this->sendSlackMessage('Connection restored. Resuming updates.');
        }
        else {
          $outage['nextCheck'] = microtime(TRUE) + 15;
        }
      }

      // Hand the next site to every free slot.
      $now = microtime(TRUE);
      for ($slot = 0; $slot < $concurrency && $queue && !$outage && !$this->stopRequested; $slot++) {
        if (isset($running[$slot]) || $slotReadyAt[$slot] > $now) {
          continue;
        }

        $key = $this->nextQueueKey($queue, $hostLoad, $maxPerHost);
        if ($key === NULL) {
          // Every remaining site is on a host that is at capacity.
          break;
        }
        $task = $queue[$key];
        unset($queue[$key]);

        $process = $this->startSiteProcess($sites[$task['alias']], $command, $timeout);

        $running[$slot] = $task + ['process' => $process, 'started' => $now];
        $hostLoad[$task['host']] = ($hostLoad[$task['host']] ?? 0) + 1;
      }

      if ($this->stopRequested && !$running) {
        break;
      }

      $progress->setMessage($outage ? 'paused, waiting for network' : (string) count($running), 'running');
      $progress->display();
      usleep(100000);
    }

    if (!$queue) {
      $progress->finish();
    }
    $this->io()->newLine(2);
  }

  /**
   * Check if the process failed because the SSH connection failed or dropped.
   *
   * SSH exits with 255 for connection errors, but so does a PHP fatal error on
   * the remote side, so the error output is checked as well.
   */
  private function isConnectionFailure(Process $process): bool {
    return $process->getExitCode() === 255 && preg_match('/^ssh: |Connection (timed out|refused|reset|closed)|Broken pipe|Could not resolve hostname|Network is unreachable|No route to host|Timeout, server .* not responding|Operation timed out/mi', $process->getErrorOutput());
  }

  /**
   * Check if the site's webhost accepts connections on its SSH port.
   */
  private function isReachable(SiteAliasInterface $alias): bool {
    $port = preg_match('/-p\s*(\d+)/', (string) $alias->get('ssh.options'), $matches) ? (int) $matches[1] : 22;
    $socket = @fsockopen((string) $alias->get('host'), $port, $errorCode, $errorMessage, 5);
    if ($socket) {
      fclose($socket);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Make SSH notice a dead connection instead of hanging indefinitely.
   *
   * Without keepalives, a dropped network can leave ssh waiting forever. With
   * them, it exits after about two minutes so the site can be retried.
   */
  private function prepareAlias(SiteAliasInterface $alias): SiteAliasInterface {
    $sshOptions = (string) $alias->get('ssh.options', '-o PasswordAuthentication=no');
    if (!str_contains($sshOptions, 'ServerAliveInterval')) {
      $alias->set('ssh.options', "-o ConnectTimeout=20 -o ServerAliveInterval=30 -o ServerAliveCountMax=4 $sshOptions");
    }
    return $alias;
  }

  /**
   * Start the drush command on the site without waiting for it to finish.
   *
   * @param \Consolidation\SiteAlias\SiteAliasInterface $alias
   *   Site alias.
   * @param string $command
   *   Drush command.
   * @param float|null $timeout
   *   Timeout in seconds.
   *
   * @return \Symfony\Component\Process\Process
   *   The running process.
   */
  private function startSiteProcess(SiteAliasInterface $alias, string $command, ?float $timeout): Process {
    $process = $this->processManager()->drush($alias, $command, [], ['yes' => TRUE]);

    // Run each site in its own session so that Ctrl+C in the terminal only
    // reaches this command, which can then let running sites finish cleanly
    // instead of killing them mid-update.
    static $setsid;
    $setsid ??= (new ExecutableFinder())->find('setsid') ?: FALSE;
    if ($setsid && !Drush::simulate()) {
      $process = Process::fromShellCommandline('exec ' . escapeshellarg($setsid) . ' ' . $process->getCommandLine());
    }

    $process->setTimeout($timeout);
    $process->start();
    return $process;
  }

  /**
   * Build the work queue, interleaving sites by webhost.
   *
   * Alternating hosts means the workers spread their load across every
   * webhost instead of all hitting the same one at the start.
   *
   * @param \Consolidation\SiteAlias\SiteAliasInterface[] $sites
   *   Site aliases keyed by alias name.
   *
   * @return array
   *   List of tasks with 'alias', 'host' and 'attempt' keys.
   */
  private function buildQueue(array $sites): array {
    $byHost = [];
    foreach ($sites as $name => $alias) {
      $byHost[(string) $alias->get('host')][] = $name;
    }

    $queue = [];
    while ($byHost) {
      foreach ($byHost as $host => &$names) {
        $queue[] = [
          'alias' => array_shift($names),
          'host' => $host,
          'attempt' => 1,
          'connectionFailures' => 0,
        ];
        if (!$names) {
          unset($byHost[$host]);
        }
      }
      unset($names);
    }
    return $queue;
  }

  /**
   * Find the next task in the queue whose host has capacity.
   *
   * @param array $queue
   *   Remaining tasks.
   * @param array $hostLoad
   *   Number of running jobs keyed by host.
   * @param int $maxPerHost
   *   Maximum jobs per host, 0 for no limit.
   *
   * @return int|null
   *   Queue key, or null if nothing can be started right now.
   */
  private function nextQueueKey(array $queue, array $hostLoad, int $maxPerHost): ?int {
    foreach ($queue as $key => $task) {
      if (!$maxPerHost || ($hostLoad[$task['host']] ?? 0) < $maxPerHost) {
        return $key;
      }
    }
    return NULL;
  }

  /**
   * Get the site aliases for the environment.
   *
   * @param string $env
   *   Dev, test, or live.
   * @param string[] $limitTo
   *   Optional list of site names to limit to.
   *
   * @return \Consolidation\SiteAlias\SiteAliasInterface[]
   *   Site aliases keyed by alias name, sorted alphabetically.
   */
  private function getSiteAliases(string $env, array $limitTo = []): array {
    $limitTo = array_filter(array_map(fn($site) => $this->siteName(trim($site)), $limitTo));

    $sites = [];
    foreach ($this->siteAliasManager->getMultiple() ?: [] as $alias) {
      $name = $alias->name();
      if (!str_ends_with($name, ".01$env") || !$alias->get('host')) {
        continue;
      }
      if ($limitTo && !in_array($this->siteName($name), $limitTo, TRUE)) {
        continue;
      }
      $sites[$name] = $this->prepareAlias($alias);
    }
    ksort($sites);
    return $sites;
  }

  /**
   * Get the bare site name from an alias name.
   *
   * Accepts "foo", "@foo.01live" or "@location.foo.01live" and returns "foo".
   */
  private function siteName(string $alias): string {
    return preg_replace('/^(?:.*\.)?([^.]+)$/', '$1', preg_replace('/\.01\w+$/', '', ltrim($alias, '@')));
  }

  /**
   * Path to the site's log file.
   */
  private function logFile(string $alias): string {
    return "{$this->logDir}/{$this->siteName($alias)}.log";
  }

  /**
   * Append the output of a site attempt to its log file.
   */
  private function logAttempt(string $command, array $job, Process $process): void {
    $file = $this->logFile($job['alias']);
    $contents = sprintf(
      "===== drush %s | attempt %d | %s | %ds =====\n$ %s\n\n--- stdout ---\n%s\n--- stderr ---\n%s\n\n",
      $command,
      $job['attempt'],
      $this->exitCodeLabel($process, $job),
      round(microtime(TRUE) - $job['started']),
      $process->getCommandLine(),
      $process->getOutput(),
      $process->getErrorOutput(),
    );
    file_put_contents($file, $contents, FILE_APPEND);
  }

  /**
   * Print the final summary, notify slack, and fail if any sites failed.
   *
   * @param \Consolidation\SiteAlias\SiteAliasInterface[] $sites
   *   Site aliases keyed by alias name.
   */
  private function reportResults(array $sites): void {
    $phases = $this->state['phases'];
    $failed = array_unique(array_merge([], ...array_values(array_column($phases, 'failed'))));
    // A site is done once it has completed the last phase.
    $lastPhase = $this->state['separate'] ? 'config:import' : 'deploy';
    $skipped = array_values(array_diff(array_keys($sites), $phases[$lastPhase]['completed'] ?? [], $failed));

    $this->state['finished'] = !$failed && !$skipped;
    $this->saveState();

    $message = sprintf('Deployment %s on `%s.01%s`.', $skipped ? 'stopped' : 'complete', $this->state['stack'], $this->state['env']);
    if ($failed) {
      $message .= ' ' . count($failed) . ' had errors. Please review.';
    }
    if ($skipped) {
      $message .= ' ' . count($skipped) . ' have not been updated yet.';
    }
    $this->sendSlackMessage($message, TRUE);

    if ($this->state['finished']) {
      $this->io()->success($message);
      return;
    }

    $this->io()->warning($message);
    if ($failed) {
      $this->io()->listing(array_map(fn($alias) => "$alias (log: {$this->logFile($alias)})", $failed));
    }
    if (!Drush::simulate()) {
      $this->io()->writeln(sprintf('Resume with: <comment>drush sws:acsf:update-environment --resume=%s</comment>', $this->logDir));
    }

    throw new CommandFailedException(sprintf('%d sites failed and %d have not been updated yet.', count($failed), count($skipped)));
  }

  /**
   * Create the progress bar used while the queue runs.
   */
  private function createProgressBar(int $total, string $command, int $startAt = 0): ProgressBar {
    // A redrawn progress bar is only useful in a terminal. When the output is
    // being logged, the per-site lines are written instead.
    $output = $this->output()->isDecorated() ? $this->output() : new NullOutput();
    $progress = new ProgressBar($output, $total);
    $progress->setFormat(' %command% %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% | running: %running% | failed: %failed%');
    $progress->setMessage($command, 'command');
    $progress->setMessage('0', 'running');
    $progress->setMessage('0', 'failed');
    $progress->start(NULL, $startAt);
    return $progress;
  }

  /**
   * Write a line above the progress bar without breaking it.
   *
   * @param \Symfony\Component\Console\Helper\ProgressBar $progress
   *   Progress bar.
   * @param string $message
   *   Message to write.
   * @param bool $verboseOnly
   *   Only write the message in verbose mode.
   */
  private function writeAboveProgress(ProgressBar $progress, string $message, bool $verboseOnly = FALSE): void {
    if ($verboseOnly && !$this->output()->isVerbose()) {
      return;
    }
    $progress->clear();
    $this->output()->writeln($message);
    $progress->display();
  }

  /**
   * Get the last few lines of command output for display.
   */
  private function tail(string $output, int $lines = 10): string {
    $output = array_slice(array_filter(explode("\n", trim($output))), -$lines);
    return $output ? "\n    " . implode("\n    ", $output) : '';
  }

  /**
   * Human readable exit code, accounting for timeouts and signals.
   */
  private function exitCodeLabel(Process $process, array $job): string {
    if (!empty($job['timedOut'])) {
      return 'timed out';
    }
    if ($process->hasBeenSignaled()) {
      return 'signal ' . $process->getTermSignal();
    }
    return 'exit ' . ($process->getExitCode() ?? 'unknown');
  }

  /**
   * Wait the given number of seconds, displaying a countdown.
   */
  private function countdown(int $seconds): void {
    if ($seconds <= 0) {
      return;
    }

    $resume = new \DateTime('now', new \DateTimeZone('America/Los_Angeles'));
    $resume->add(new \DateInterval("PT{$seconds}S"));
    $message = sprintf('Delayed config import until %s.', $resume->format('g:i a'));
    $this->io()->note($message);
    $this->sendSlackMessage($message);

    $progress = $this->io()->createProgressBar($seconds);
    $progress->setFormat(' Waiting %remaining% [%bar%]');
    $progress->setPlaceholderFormatter('remaining', fn(ProgressBar $bar) => gmdate('G:i:s', $bar->getMaxSteps() - $bar->getProgress()));
    $progress->start();
    $end = time() + $seconds;
    while (time() < $end && !$this->stopRequested) {
      sleep(1);
      $progress->setProgress(min($seconds, $seconds - ($end - time())));
    }
    $progress->finish();
    $this->io()->newLine(2);
  }

  /**
   * Gracefully stop on Ctrl+C: finish running sites, start no new ones.
   */
  private function registerSignalHandlers(): void {
    if (!function_exists('pcntl_signal')) {
      return;
    }
    pcntl_async_signals(TRUE);
    $handler = function () {
      if ($this->stopRequested) {
        $this->abortRequested = TRUE;
        $this->output()->writeln("\n<error>Terminating running sites.</error>");
        return;
      }
      $this->stopRequested = TRUE;
      $this->output()->writeln("\n<comment>Stopping after running sites complete. Press Ctrl+C again to terminate them.</comment>");
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
  }

  /**
   * Restore default signal handling.
   */
  private function restoreSignalHandlers(): void {
    if (!function_exists('pcntl_signal')) {
      return;
    }
    pcntl_signal(SIGINT, SIG_DFL);
    pcntl_signal(SIGTERM, SIG_DFL);
  }

  /**
   * Send a Slack message to the Slack API.
   */
  #[CLI\Command(name: 'sws:slack-message')]
  #[CLI\Argument(name: 'message', description: 'Message text to send to slack.')]
  #[CLI\Option(name: 'slack-token', description: 'Bot User OAuth Token. Must have "chat:write" scope permissions.')]
  #[CLI\Option(name: 'slack-channel', description: 'An encoded ID or channel name that represents a channel, private group, or IM channel to send the message to.')]
  #[CLI\Option(name: 'slack-thread', description: 'Provide another message\'s ts value to make this message a reply. Avoid using a reply\'s ts value; use its parent instead.')]
  public function slackMessage(string $message, array $options = [
    'slack-token' => NULL,
    'slack-channel' => NULL,
    'slack-thread' => NULL,
  ]
  ): ?string {
    $this->slackThread = $options['slack-thread'] ?: NULL;
    return $this->sendSlackMessage($message);
  }

  /**
   * Send a message to Slack, threaded under the first message.
   *
   * @param string $message
   *   Message text.
   * @param bool $broadcast
   *   Also show the thread reply in the channel.
   *
   * @return string|null
   *   The posted message's timestamp, or null if it wasn't sent.
   */
  private function sendSlackMessage(string $message, bool $broadcast = FALSE): ?string {
    $token = $this->input()->getOption('slack-token');
    $channel = $this->input()->getOption('slack-channel');
    if ($this->slackFailed || !$token || !$channel) {
      return NULL;
    }

    try {
      $response = (new Client(['timeout' => 10]))->post('https://slack.com/api/chat.postMessage', [
        'headers' => ['Authorization' => "Bearer $token"],
        'json' => [
          'channel' => $channel,
          'text' => $message,
          'thread_ts' => $this->slackThread,
          'reply_broadcast' => $broadcast && $this->slackThread,
        ],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (empty($data['ok'])) {
        throw new \RuntimeException($data['error'] ?? 'Unknown error');
      }
      if (!$this->slackThread) {
        // Save the thread right away so a resumed run replies to it even if
        // this run dies before the first site finishes.
        $this->slackThread = $data['ts'];
        if ($this->state) {
          $this->saveState();
        }
      }
      return $data['ts'];
    }
    catch (\Throwable $e) {
      $this->slackFailed = TRUE;
      $this->logger()->warning('Failed to send slack notification. ' . $e->getMessage());
    }
    return NULL;
  }

}
