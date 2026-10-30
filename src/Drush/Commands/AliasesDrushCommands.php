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
 * sites.json over SSH, so no API credentials are needed. The hosts for each
 * environment are set in drush config (`sws.acsf.hosts`) as a single host or
 * a list. With a list, sites are spread across the hosts, and each site keeps
 * the host it already has so alias files don't churn between builds.
 *
 * Aliases are named @site.dev, @site.test and @site.live. Aliases for sites
 * that no longer exist are removed. If an environment can't be read, its
 * existing aliases are left untouched.
 *
 * Example drush.yml config:
 *
 * @code
 * sws:
 *   acsf:
 *     stack: cardinalsites
 *     hosts:
 *       dev: staging-1.enterprise-g1.hosting.acquia.com
 *       test: staging-1.enterprise-g1.hosting.acquia.com
 *       live:
 *         - web-1.enterprise-g1.hosting.acquia.com
 *         - web-2.enterprise-g1.hosting.acquia.com
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
  private const SSH_OPTIONS = [
    '-o',
    'BatchMode=yes',
    '-o',
    'ConnectTimeout=15',
  ];

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
  #[CLI\Option(name: 'keep-stale', description: 'Keep aliases for sites that no longer exist.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without writing any files.')]
  #[CLI\Usage(name: 'drush aliases', description: 'Build all site aliases into the drush/sites directory.')]
  #[CLI\Usage(name: 'drush aliases --env=live --dry-run', description: 'Show what would change in the live aliases.')]
  public function buildAliases(array $options = [
    'stack' => NULL,
    'env' => NULL,
    'alias-dir' => 'drush/sites',
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
    $fetched = $this->fetchSites($stack, $hosts);

    $generated = [];
    foreach ($fetched as $env => $sites) {
      $assigned = $this->assignHosts($env, $sites, $hosts[$env], $existing);
      foreach ($sites as $site => $domain) {
        $generated[$site][$env] = $this->buildAlias($domain, $assigned[$site], "$stack.01$env");
      }
    }
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
   * Get the hosts for each environment from config or the existing aliases.
   *
   * @param string $stack
   *   Stack name.
   * @param string[] $envs
   *   Environments to build.
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   *
   * @return array
   *   Lists of host names keyed by environment. Environments without a host
   *   are left out.
   */
  private function resolveHosts(string $stack, array $envs, array $existing): array {
    // Each environment can be configured with a single host or a list.
    $hosts = array_map(fn($envHosts) => array_values(array_filter((array) $envHosts)), array_intersect_key($this->getConfig()
      ->get('sws.acsf.hosts') ?: [], array_flip($envs)));
    $hosts = array_filter($hosts);

    // Otherwise use the hosts the existing aliases already point to.
    $aliasHosts = [];
    foreach ($existing as $aliases) {
      foreach ($aliases as $alias) {
        if (isset($alias['host']) && preg_match('/^' . preg_quote($stack, '/') . '\.01(dev|test|live)$/', (string) ($alias['user'] ?? ''), $matches) && in_array($matches[1], $envs, TRUE)) {
          $aliasHosts[$matches[1]][$alias['host']] = $alias['host'];
        }
      }
    }
    foreach ($aliasHosts as $env => $envHosts) {
      sort($envHosts);
      $hosts[$env] ??= $envHosts;
    }

    foreach (array_diff($envs, array_keys($hosts)) as $env) {
      $this->logger()
        ->error("No host is known for $env. Add it to sws.acsf.hosts.$env in drush.yml.");
    }
    return $hosts;
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
   *   Lists of host names keyed by environment. Hosts that can't be connected
   *   to are removed, as long as the environment has another host.
   *
   * @return array
   *   Primary domains keyed by environment, then site name. Environments that
   *   couldn't be read from any of their hosts are left out.
   */
  private function fetchSites(string $stack, array &$hosts): array {
    $fetched = [];
    $unreachable = [];
    // Read every environment at once, moving on to the next host for any
    // environment whose host failed.
    for ($attempt = 0; $remaining = array_diff_key(array_filter($hosts, fn($envHosts) => isset($envHosts[$attempt])), $fetched); $attempt++) {
      $processes = [];
      foreach ($remaining as $env => $envHosts) {
        $user = "$stack.01$env";
        $processes[$env] = new Process([
          'ssh',
          ...self::SSH_OPTIONS,
          "$user@{$envHosts[$attempt]}",
          "cat /mnt/files/$user/files-private/sites.json",
        ]);
        $processes[$env]->setTimeout(120);
        $processes[$env]->start();
      }

      foreach ($processes as $env => $process) {
        $process->wait();
        try {
          if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'exit ' . $process->getExitCode());
          }
          $fetched[$env] = $this->parseSitesJson($process->getOutput(), $stack, $env);
          $this->io()
            ->writeln(sprintf('Found %d sites on %s.', count($fetched[$env]), $env));
        }
        catch (\Throwable $e) {
          $this->logger()
            ->warning(sprintf('Unable to read sites.json for %s from %s: %s', $env, $hosts[$env][$attempt], $e->getMessage()));
          // SSH exits with 255 when it can't connect, so don't send any sites
          // to that host.
          if ($process->getExitCode() === 255 && count($hosts[$env]) > 1) {
            $unreachable[$env][] = $hosts[$env][$attempt];
          }
        }
      }
    }
    foreach ($unreachable as $env => $envHosts) {
      $hosts[$env] = array_values(array_diff($hosts[$env], $envHosts));
      $this->logger()
        ->warning(sprintf('No %s aliases will use %s.', $env, implode(', ', $envHosts)));
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
   * Pick a host for each site in an environment.
   *
   * Sites keep the host they already have if it's still one of the
   * environment's hosts, and everything else goes to the least loaded host.
   *
   * @param string $env
   *   Environment.
   * @param array $sites
   *   Domains keyed by site name.
   * @param string[] $hosts
   *   The environment's hosts.
   * @param array $existing
   *   Existing aliases keyed by site, then environment.
   *
   * @return array
   *   Host names keyed by site name.
   */
  private function assignHosts(string $env, array $sites, array $hosts, array $existing): array {
    $load = array_fill_keys($hosts, 0);
    $assigned = [];

    // Aliases from before the rename to @site.live are named @site.01live.
    foreach (array_keys($sites) as $site) {
      $host = $existing[$site][$env]['host'] ?? $existing[$site]["01$env"]['host'] ?? NULL;
      if ($host && isset($load[$host])) {
        $assigned[$site] = $host;
        $load[$host]++;
      }
    }
    foreach (array_diff_key($sites, $assigned) as $site => $domain) {
      $host = array_keys($load, min($load))[0];
      $assigned[$site] = $host;
      $load[$host]++;
    }
    return $assigned;
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
    $counts = [
      'created' => [],
      'updated' => [],
      'removed' => [],
      'unchanged' => [],
    ];

    foreach (array_unique([
      ...array_keys($existing),
      ...array_keys($generated),
    ]) as $site) {
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

    $this->io()
      ->definitionList(...array_map(fn($label, $sites) => [ucfirst($label) => count($sites)], array_keys($counts), $counts));
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
      $this->io()
        ->success(sprintf('Aliases for %s written to %s.', implode(', ', $envs), $this->aliasDir));
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
        $this->logger()
          ->warning("Ignoring unreadable alias file $file: " . $e->getMessage());
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

}
