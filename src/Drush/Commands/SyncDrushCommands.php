<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Commands to copy a site, its files and secret keys from Acquia to local.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class SyncDrushCommands extends DrushCommands {

  use AutowireTrait;
  use SwsCommandsTrait;

  /**
   * Generated files that don't need to be copied.
   */
  private const EXCLUDE_FILE_PATHS = 'styles:css:js';

  public function __construct(
    private readonly SiteAliasManagerInterface $siteAliasManager,
  ) {
    parent::__construct();
  }

  /**
   * Sync a site from production to local and perform database updates.
   *
   * The remote alias comes from the site's sws.yml (`site.remote-alias`) and
   * defaults to @<site>.prod. The local database is replaced, sanitized, and
   * updated with `drush deploy`.
   */
  #[CLI\Command(name: 'sws:site:sync', aliases: ['drupal:sync', 'sync'])]
  #[CLI\Option(name: 'site', description: 'Site name to sync.')]
  #[CLI\Option(name: 'with-files', description: 'Sync files after the database.')]
  #[CLI\Option(name: 'partial', description: 'Run updatedb, config:import --partial and deploy:hook instead of drush deploy.')]
  #[CLI\Usage(name: 'drush sws:site:sync --site=summer', description: 'Replace the local summer database with production and update it.')]
  #[CLI\Usage(name: 'drush sws:site:sync --site=summer --with-files -y', description: 'Also copy the public and private files, without asking.')]
  public function syncSite(array $options = [
    'site' => 'default',
    'with-files' => FALSE,
    'partial' => FALSE,
  ]
  ): void {
    $started = microtime(TRUE);
    $site = $options['site'] ?: 'default';
    $local = $this->getLocalAlias($site);
    $remote = $this->getRemoteAlias($site);

    if (!$this->io()->confirm(sprintf('Replace the local %s database with a copy from %s?', $site, $remote->name()))) {
      throw new UserAbortException();
    }

    $this->step('Dropping the local database', fn() => $this->drush($local, ['sql:drop']));
    $this->step("Copying the database from {$remote->name()}", fn() => $this->drush($this->siteAliasManager->getSelf(), ['sql:sync', $remote->name(), $local->name()]));
    $this->step('Sanitizing the database', fn() => $this->drush($local, ['sql:sanitize']));

    $updates = $options['partial'] ? [['updatedb'], ['config:import', '--partial'], ['deploy:hook']] : [['deploy']];
    foreach ($updates as $command) {
      $this->step("Running drush {$command[0]}", fn() => $this->drush($local, $command));
    }

    if ($options['with-files']) {
      $this->copyFiles($remote, $local);
    }

    $this->io()->success(sprintf('Synced %s from %s in %s.', $site, $remote->name(), $this->formatDuration(microtime(TRUE) - $started)));
  }

  /**
   * Sync public and private files from prod site.
   */
  #[CLI\Command(name: 'sws:site:sync-files', aliases: ['drupal:sync-files'])]
  #[CLI\Argument(name: 'site_name', description: 'Site name to sync.')]
  #[CLI\Usage(name: 'drush sws:site:sync-files summer', description: 'Copy the summer site\'s files from production.')]
  public function syncSiteFiles(string $site_name = 'default'): void {
    $started = microtime(TRUE);
    $local = $this->getLocalAlias($site_name);
    $remote = $this->getRemoteAlias($site_name);
    $this->copyFiles($remote, $local);
    $this->io()->success(sprintf('Synced %s files from %s in %s.', $site_name, $remote->name(), $this->formatDuration(microtime(TRUE) - $started)));
  }

  /**
   * Sync key secret files.
   *
   * Copies files such as secrets.settings.php and the SAML certificates from
   * an Acquia server into the keys directory.
   */
  #[CLI\Command(name: 'sws:keys', aliases: ['keys'])]
  #[CLI\Option(name: 'sync-ssh', description: 'SSH connection to copy from, e.g. site.test@host.')]
  #[CLI\Option(name: 'sync-files', description: 'Remote files or directories to copy. Use "--sync-files=foo --sync-files=bar" for multiple.')]
  public function syncKeys(array $options = [
    'sync-ssh' => InputOption::VALUE_REQUIRED,
    'sync-files' => [],
  ]
  ): void {
    if (!(new ExecutableFinder())->find('rsync')) {
      throw new CommandFailedException('rsync is not installed or not in your $PATH.');
    }
    $this->ensureOption('sync-ssh', fn() => $this->io()->ask('SSH string'), TRUE);
    $this->ensureOption('sync-files', fn() => array_filter(array_map('trim', explode(',', (string) $this->io()->ask('Files to sync (comma separated)')))), TRUE);

    $ssh = $this->input()->getOption('sync-ssh');
    $files = array_values(array_filter(array_map('trim', explode(',', implode(',', (array) $this->input()->getOption('sync-files'))))));
    if (!$files) {
      throw new CommandFailedException('No files to sync.');
    }

    $keysDir = Path::join($this->getDir(), 'keys');
    (new Filesystem())->mkdir($keysDir);

    $this->io()->writeln(sprintf('Copying %d item(s) from <comment>%s</comment> to %s...', count($files), $ssh, $keysDir));
    $command = ['rsync', '--recursive', '--exclude=.git', '--exclude=.svn', '--exclude=.hg', '--verbose', '--human-readable'];
    foreach ($files as $file) {
      $command[] = "$ssh:$file";
    }
    $command[] = "$keysDir/";

    $process = new Process($command);
    $process->setTimeout(NULL);
    $process->run(fn($type, $buffer) => $this->output()->write($buffer));
    if (!$process->isSuccessful()) {
      throw new CommandFailedException('Failed to copy the keys.', $process->getExitCode() ?: 1);
    }
    $this->io()->success('Keys copied to ' . $keysDir);
  }

  /**
   * Copy the public and private files from the remote site.
   *
   * Not every site has private files, so a failure there is only a warning.
   */
  private function copyFiles(SiteAliasInterface $remote, SiteAliasInterface $local): void {
    $self = $this->siteAliasManager->getSelf();
    $exclude = ['exclude-paths' => self::EXCLUDE_FILE_PATHS];

    $this->step('Copying public files', fn() => $this->drush($self, ['core:rsync', "{$remote->name()}:%files/", "{$local->name()}:%files"], $exclude));
    $this->step('Copying private files', fn() => $this->drush($self, ['core:rsync', "{$remote->name()}:%private/", "{$local->name()}:%private"], $exclude), 'The site may not have private files.');
  }

  /**
   * Get the site's local alias and make sure it really is local.
   *
   * The local database gets dropped, so this guards against a misconfigured
   * alias pointing somewhere else.
   */
  private function getLocalAlias(string $site): SiteAliasInterface {
    $alias = $this->siteAliasManager->get("@$site.local");
    if (!$alias) {
      throw new CommandFailedException("The @$site.local alias does not exist. Check drush/sites/$site.site.yml.");
    }
    if ($alias->isRemote()) {
      throw new CommandFailedException("@$site.local points to {$alias->remoteHost()}. Refusing to replace a remote database.");
    }
    return $alias;
  }

  /**
   * Get the remote alias from the site's sws.yml, defaulting to @site.prod.
   */
  private function getRemoteAlias(string $site): SiteAliasInterface {
    $configFile = Path::join($this->getDir(), 'docroot', 'sites', $site, 'sws.yml');
    $name = file_exists($configFile) ? (Yaml::parseFile($configFile)['site']['remote-alias'] ?? NULL) : NULL;
    $name = '@' . ltrim($name ?: "$site.prod", '@');

    $alias = $this->siteAliasManager->get($name);
    if (!$alias) {
      throw new CommandFailedException("The remote alias $name does not exist. Build the aliases or set site.remote-alias in $configFile.");
    }
    return $alias;
  }

  /**
   * Run one step, showing its progress and how long it took.
   *
   * @param string $label
   *   What the step does.
   * @param callable $callback
   *   The work.
   * @param string|null $optional
   *   If given, a failure is only a warning, with this explanation.
   */
  private function step(string $label, callable $callback, ?string $optional = NULL): void {
    $this->output()->writeln(" <comment>›</comment> $label...");
    $started = microtime(TRUE);
    try {
      $callback();
    }
    catch (\Throwable $e) {
      if ($optional) {
        $this->output()->writeln(" <comment>!</comment> $label skipped. $optional");
        $this->output()->writeln(preg_replace('/^/m', '   │ ', $e->getMessage()), OutputInterface::VERBOSITY_VERBOSE);
        return;
      }
      $this->output()->writeln(" <error>✘</error> $label failed");
      throw new CommandFailedException("$label failed.\n\n" . $e->getMessage(), 1, $e);
    }
    $this->output()->writeln(sprintf(' <info>✔</info> %s <fg=gray>(%s)</>', $label, $this->formatDuration(microtime(TRUE) - $started)));
  }

  /**
   * Run a drush command on a site alias and wait for it.
   *
   * Output is streamed in verbose mode. Otherwise the end of it is included
   * in the error if the command fails.
   *
   * @param \Consolidation\SiteAlias\SiteAliasInterface $alias
   *   Site to run the command on.
   * @param string[] $command
   *   Drush command, arguments and --options.
   * @param array $options
   *   Additional drush options.
   */
  private function drush(SiteAliasInterface $alias, array $command, array $options = []): void {
    $process = $this->processManager()->drush($alias, array_shift($command), $command, $options + ['yes' => TRUE]);
    $process->setTimeout(NULL);

    $verbose = $this->output()->isVerbose();
    $process->run(function ($type, $buffer) use ($verbose) {
      if ($verbose) {
        $this->output()->write(preg_replace('/^/m', '   │ ', $buffer));
      }
    });

    if (!$process->isSuccessful()) {
      $output = array_slice(array_filter(explode("\n", $process->getErrorOutput() . $process->getOutput()), 'trim'), -15);
      throw new \RuntimeException(sprintf("Exit %d: %s\n%s", $process->getExitCode(), $process->getCommandLine(), implode("\n", $output)));
    }
  }

  /**
   * Format seconds as "42s" or "3m 05s".
   */
  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
  }

}
