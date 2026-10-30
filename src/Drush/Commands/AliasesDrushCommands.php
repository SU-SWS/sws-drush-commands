<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Build drush site aliases for every site on a Site Factory stack.
 *
 * Every stack has dev, test and live environments (01dev, 01test and 01live
 * on Acquia). The site list is read directly from each environment's
 * sites.json over SSH, so no API credentials are needed. The web servers come
 * from drush config (`sws.acsf.hosts`), falling back to the ones used by the
 * existing alias files, and each one is checked over SSH before it's used.
 *
 * Aliases are named @site.dev, @site.test and @site.live. Existing host
 * assignments are kept so alias files don't churn between builds, new sites
 * go to the least loaded web server, and aliases for sites that no longer
 * exist are removed. If an environment can't be read, its existing aliases
 * are left untouched.
 *
 * Example drush.yml config:
 * @code
 * sws:
 *   acsf:
 *     stack: cardinalsites
 *     hosts:
 *       dev: [staging-1.enterprise-g1.hosting.acquia.com]
 *       test: [staging-1.enterprise-g1.hosting.acquia.com]
 *       live: [web-1.enterprise-g1.hosting.acquia.com, web-2.enterprise-g1.hosting.acquia.com]
 * @endcode
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class AliasesDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Environments on every stack, prefixed with "01" on Acquia.
   */
  private const ENVIRONMENTS = ['dev', 'test', 'live'];

  /**
   * Never prompt for a password and give up quickly on a dead host.
   */
  private const SSH_OPTIONS = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15'];

  /**
   * Absolute path to the alias directory.
   */
  private string $aliasDir;

  /**
   * Build Drush Aliases.
   */
  #[CLI\Command(name: 'sws:acquia:alias-build', aliases: ['aliases', 'sab'])]
  #[CLI\Option(name: 'stack', description: 'Site Factory stack (Acquia site group), e.g. "cardinalsites". Defaults to sws.acsf.stack config or the existing aliases.')]
  #[CLI\Option(name: 'env', description: 'Comma separated environments to rebuild: dev, test, live. Others are left untouched.')]
  #[CLI\Option(name: 'alias-dir', description: 'Directory for alias files.')]
  #[CLI\Option(name: 'skip-host-check', description: 'Don\'t verify each web server accepts SSH connections.')]
  #[CLI\Option(name: 'keep-stale', description: 'Keep aliases for sites that no longer exist.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without writing any files.')]
  #[CLI\Usage(name: 'drush aliases', description: 'Build all site aliases into the drush/sites directory.')]
  #[CLI\Usage(name: 'drush aliases --env=live --dry-run', description: 'Show what would change in the live aliases.')]
  public function buildAliases(array $options = [
    'stack' => NULL,
    'env' => NULL,
    'alias-dir' => 'drush/sites',
    'skip-host-check' => FALSE,
    'keep-stale' => FALSE,
    'dry-run' => FALSE,
  ]
  ): void {
    $this->aliasDir = Path::makeAbsolute($options['alias-dir'] ?: 'drush/sites', $this->getDir());
    $existing = $this->readExistingAliases();

    $envs = self::ENVIRONMENTS;
    if ($options['env']) {
      $envs = array_intersect(self::ENVIRONMENTS, array_map(fn($env) => preg_replace('/^01/', '', trim($env)), explode(',', $options['env'])));
      if (!$envs) {
        throw new CommandFailedException('Invalid environment. Use dev, test, or live.');
      }
    }

    $stack = $this->resolveStack($existing, $options['stack']);
    $hosts = $this->resolveHosts($stack, $envs, $existing);
    if (!$options['skip-host-check']) {
      $this->checkHosts($stack, $hosts);
    }

    $fetched = $this->fetchSites($stack, $hosts);
    $generated = $this->generateAliases($stack, $hosts, $fetched, $existing);
    $this->writeAliases($stack, $generated, $existing, array_keys($fetched), $options);

    $failed = array_diff($envs, array_keys($fetched));
    if ($failed) {
      throw new CommandFailedException(sprintf('Unable to read sites for %s. Existing aliases for those environments were left unchanged.', implode(', ', $failed)));
    }
  }

  /**
   * Get the stack name from the option, config, or existing aliases.
   *
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   * @param string|null $stack
   *   Stack name from the command option.
   */
  private function resolveStack(array $existing, ?string $stack): string {
    $stack = $stack ?: $this->getConfig()->get('sws.acsf.stack');
    if ($stack) {
      return $stack;
    }

    // Use the most common ssh user prefix in the existing aliases.
    $stacks = [];
    foreach ($existing as $aliases) {
      foreach ($aliases as $alias) {
        if (preg_match('/^(.+)\.01(dev|test|live)$/', (string) ($alias['user'] ?? ''), $matches)) {
          $stacks[$matches[1]] = ($stacks[$matches[1]] ?? 0) + 1;
        }
      }
    }
    if (!$stacks) {
      throw new CommandFailedException('Unable to determine the stack. Set sws.acsf.stack in drush.yml or pass --stack.');
    }
    arsort($stacks);
    return (string) array_key_first($stacks);
  }

  /**
   * Get the web servers for each environment.
   *
   * @param string $stack
   *   Stack name.
   * @param string[] $envs
   *   Environments to build.
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   *
   * @return array
   *   Host names keyed by environment.
   */
  private function resolveHosts(string $stack, array $envs, array $existing): array {
    $config = $this->getConfig()->get('sws.acsf.hosts') ?: [];

    $aliasHosts = [];
    foreach ($existing as $aliases) {
      foreach ($aliases as $alias) {
        if (isset($alias['host']) && preg_match('/^' . preg_quote($stack, '/') . '\.01(dev|test|live)$/', (string) ($alias['user'] ?? ''), $matches)) {
          $aliasHosts[$matches[1]][$alias['host']] = $alias['host'];
        }
      }
    }

    $hosts = [];
    foreach ($envs as $env) {
      $hosts[$env] = array_values($config[$env] ?? $aliasHosts[$env] ?? []);
      sort($hosts[$env]);
      if (!$hosts[$env]) {
        $this->logger()->error("No web servers are known for $env. Add them to sws.acsf.hosts.$env in drush.yml.");
      }
    }
    return $hosts;
  }

  /**
   * Remove any web servers that don't accept SSH connections.
   *
   * @param string $stack
   *   Stack name.
   * @param array $hosts
   *   Host names keyed by environment. Unreachable hosts are removed.
   */
  private function checkHosts(string $stack, array &$hosts): void {
    $checks = [];
    foreach ($hosts as $env => $envHosts) {
      foreach ($envHosts as $host) {
        $checks["$env $host"] = $this->ssh("$stack.01$env", $host, 'true');
      }
    }
    $this->io()->writeln(sprintf('Checking %d web servers...', count($checks)));
    $this->waitForAll($checks);

    foreach ($checks as $key => $process) {
      if ($process->isSuccessful()) {
        continue;
      }
      [$env, $host] = explode(' ', $key);
      $hosts[$env] = array_values(array_diff($hosts[$env], [$host]));
      $this->logger()->warning(sprintf('Skipping %s for %s: %s', $host, $env, trim($process->getErrorOutput()) ?: 'exit ' . $process->getExitCode()));
    }
  }

  /**
   * Read the site list from each environment's sites.json.
   *
   * The file is streamed over SSH and only kept in memory, since it contains
   * each site's API key.
   *
   * @param string $stack
   *   Stack name.
   * @param array $hosts
   *   Host names keyed by environment.
   *
   * @return array
   *   Primary domains keyed by environment, then site name. Environments that
   *   couldn't be read are left out.
   */
  private function fetchSites(string $stack, array $hosts): array {
    $remaining = array_filter($hosts);
    foreach (array_diff_key($hosts, $remaining) as $env => $envHosts) {
      $this->logger()->error("No reachable web servers for $env.");
    }

    $fetched = [];
    // Each round tries the next host for every environment still unread.
    for ($attempt = 0; $remaining; $attempt++) {
      $processes = [];
      foreach ($remaining as $env => $envHosts) {
        if (!isset($envHosts[$attempt])) {
          unset($remaining[$env]);
          continue;
        }
        $user = "$stack.01$env";
        $processes[$env] = $this->ssh($user, $envHosts[$attempt], "cat /mnt/files/$user/files-private/sites.json");
      }
      $this->waitForAll($processes);

      foreach ($processes as $env => $process) {
        try {
          if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'exit ' . $process->getExitCode());
          }
          $fetched[$env] = $this->parseSitesJson($process->getOutput(), $stack, $env);
          unset($remaining[$env]);
          $this->io()->writeln(sprintf('Found %d sites on %s.', count($fetched[$env]), $env));
        }
        catch (\Throwable $e) {
          $this->logger()->warning(sprintf('Unable to read sites.json for %s from %s: %s', $env, $remaining[$env][$attempt], $e->getMessage()));
        }
      }
    }
    return $fetched;
  }

  /**
   * Get the primary Site Factory domain for each site in sites.json.
   *
   * @return array
   *   Domains keyed by site name.
   */
  private function parseSitesJson(string $json, string $stack, string $env): array {
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (isset($data['cloud']) && ($data['cloud']['site'] ?? NULL) !== $stack) {
      throw new \RuntimeException("sites.json belongs to {$data['cloud']['site']}.{$data['cloud']['env']}, not $stack.01$env.");
    }
    if (empty($data['sites'])) {
      throw new \RuntimeException('sites.json has no sites.');
    }

    $sites = [];
    foreach (array_keys($data['sites']) as $domain) {
      // Only the *.acsitefactory.com domain is guaranteed to exist for every
      // site, and its first part is the site name.
      if (str_contains($domain, ':*') || !str_contains($domain, '.acsitefactory.com')) {
        continue;
      }
      $sites[strstr($domain, '.', TRUE)] = $domain;
    }
    ksort($sites);
    return $sites;
  }

  /**
   * Build the alias for every site, keeping existing host assignments.
   *
   * @param string $stack
   *   Stack name.
   * @param array $hosts
   *   Host names keyed by environment.
   * @param array $fetched
   *   Domains keyed by environment, then site name.
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   *
   * @return array
   *   Aliases keyed by site, then environment.
   */
  private function generateAliases(string $stack, array $hosts, array $fetched, array $existing): array {
    $aliases = [];
    foreach ($fetched as $env => $sites) {
      $load = array_fill_keys($hosts[$env], 0);
      $assigned = [];

      // Keep sites on the web server they already use, if it's still valid.
      // Aliases from before the rename to @site.live are named @site.01live.
      foreach (array_keys($sites) as $site) {
        $host = $existing[$site][$env]['host'] ?? $existing[$site]["01$env"]['host'] ?? NULL;
        if ($host && isset($load[$host])) {
          $assigned[$site] = $host;
          $load[$host]++;
        }
      }
      // Spread everything else across the least loaded web servers.
      foreach (array_diff_key($sites, $assigned) as $site => $domain) {
        $host = array_keys($load, min($load))[0];
        $assigned[$site] = $host;
        $load[$host]++;
      }

      foreach ($sites as $site => $domain) {
        $aliases[$site][$env] = $this->buildAlias($domain, $assigned[$site], "$stack.01$env");
      }
    }
    return $aliases;
  }

  /**
   * Build a single drush site alias.
   */
  private function buildAlias(string $uri, string $host, string $user): array {
    return [
      'uri' => $uri,
      'host' => $host,
      'options' => [],
      'paths' => ['dump-dir' => '/mnt/tmp'],
      'root' => "/var/www/html/$user/docroot",
      'user' => $user,
      'ssh' => ['options' => '-p 22', 'tty' => 0],
      'env-vars' => ['WEBHEAD' => strstr($host, '.', TRUE) ?: $host],
    ];
  }

  /**
   * Merge the generated aliases into the alias files.
   *
   * Only environments that were read successfully are changed. Environments
   * that belong to other stacks or were added by hand are kept.
   *
   * @param string $stack
   *   Stack name.
   * @param array $generated
   *   Generated aliases keyed by site, then environment.
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   * @param string[] $envs
   *   Environments that were read successfully.
   * @param array $options
   *   Command options.
   */
  private function writeAliases(string $stack, array $generated, array $existing, array $envs, array $options): void {
    $counts = ['created' => [], 'updated' => [], 'removed' => [], 'unchanged' => []];

    foreach (array_unique([...array_keys($existing), ...array_keys($generated)]) as $site) {
      $aliases = $existing[$site] ?? [];
      foreach ($envs as $env) {
        // Replace aliases from before the rename to @site.live.
        if (($aliases["01$env"]['user'] ?? NULL) === "$stack.01$env") {
          unset($aliases["01$env"]);
        }

        if (isset($generated[$site][$env])) {
          $aliases[$env] = $generated[$site][$env];
        }
        elseif (isset($aliases[$env]) && !$options['keep-stale'] && ($aliases[$env]['user'] ?? NULL) === "$stack.01$env") {
          // The site no longer exists on this environment.
          unset($aliases[$env]);
        }
      }

      $file = "{$this->aliasDir}/$site.site.yml";
      if (($existing[$site] ?? []) === $aliases) {
        // Compare the data rather than the text so hand written files aren't
        // reformatted.
        $counts['unchanged'][] = $site;
        continue;
      }
      if (!$aliases) {
        $counts['removed'][] = $site;
        if (!$options['dry-run']) {
          unlink($file);
        }
        continue;
      }

      $counts[isset($existing[$site]) ? 'updated' : 'created'][] = $site;
      if (!$options['dry-run']) {
        $this->writeFile($file, Yaml::dump($aliases, 99, 2));
      }
    }

    $this->io()->definitionList(...array_map(fn($label, $sites) => [ucfirst($label) => count($sites)], array_keys($counts), $counts));
    if ($counts['created']) {
      $this->io()->writeln('Created: ' . implode(', ', $counts['created']));
    }
    if ($counts['removed']) {
      $this->io()->writeln('Removed: ' . implode(', ', $counts['removed']));
    }
    if ($options['dry-run']) {
      $this->io()->note('Dry run: no files were changed.');
    }
    elseif ($envs) {
      $this->io()->success(sprintf('Aliases for %s written to %s.', implode(', ', $envs), $this->aliasDir));
    }
  }

  /**
   * Read every existing alias file in the alias directory.
   *
   * @return array
   *   Aliases keyed by site, then environment.
   */
  private function readExistingAliases(): array {
    $aliases = [];
    foreach (glob("{$this->aliasDir}/*.site.yml") ?: [] as $file) {
      try {
        $aliases[basename($file, '.site.yml')] = Yaml::parseFile($file) ?: [];
      }
      catch (\Throwable $e) {
        $this->logger()->warning("Ignoring unreadable alias file $file: " . $e->getMessage());
      }
    }
    return $aliases;
  }

  /**
   * Write a file atomically so an interrupted build can't leave it truncated.
   */
  private function writeFile(string $file, string $contents): void {
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, TRUE)) {
      throw new CommandFailedException('Unable to create ' . dirname($file));
    }
    file_put_contents("$file.tmp", $contents);
    rename("$file.tmp", $file);
  }

  /**
   * Start an SSH command on a remote host.
   */
  private function ssh(string $user, string $host, string $command): Process {
    $process = new Process(['ssh', ...self::SSH_OPTIONS, "$user@$host", $command]);
    $process->setTimeout(120);
    $process->start();
    return $process;
  }

  /**
   * Wait for every process to finish.
   *
   * @param \Symfony\Component\Process\Process[] $processes
   *   Running processes.
   */
  private function waitForAll(array $processes): void {
    foreach ($processes as $process) {
      $process->wait();
    }
  }

}
