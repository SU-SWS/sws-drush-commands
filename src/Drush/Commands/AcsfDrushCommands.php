<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Helpers\AcquiaApi;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputOption;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class AcsfDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Perform database updates and config imports across all sites.
   *
   * Replaces `blt sws:update-environment`.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'separate-db-config', description: 'Run all database updates first across all sites before starting config imports.')]
  public function updateEnvironmentSites(array $options = [
    'env' => 'dev',
    'separate-db-config' => FALSE,
  ]
  ) {
    $siteAliases = $this->getSiteAliases($options['env']);
    $updateHosts = [];
    foreach ($siteAliases as $aliasInfo) {
      $updateHosts[$aliasInfo['host']] = $aliasInfo['host'];
    }

    $db_commands = [];
    $config_commands = [];
    foreach ($updateHosts as $host) {
      if ($options['separate-db-config']) {
        $db_commands[] = "drush sws:acsf:update-environment:database --env={$options['env']} --host=$host";
        $config_commands[] = "drush sws:acsf:update-environment:config --env={$options['env']} --host=$host";
      }
      else {
        $db_commands[] = "drush sws:acsf:update-environment:deploy --env={$options['env']} --host=$host";
      }
    }

    $failed_report = sys_get_temp_dir() . '/failed-report.txt';
    $fileSystem = $this->localMachineHelper()->getFilesystem();
    if ($fileSystem->exists($failed_report)) {
      $fileSystem->remove($failed_report);
    }
    $fileSystem->touch($failed_report);

    $this->localMachineHelper()->executeFromCmd(implode(' & ', $db_commands));
    if ($config_commands) {
      $this->localMachineHelper()
        ->executeFromCmd(implode(' & ', $config_commands));
    }

    $failed = array_unique(array_filter(explode("\n", file_get_contents($failed_report))));
    if ($failed) {
      $this->yell('Failed Sites: ' . implode(', ', $failed), 40, 'red');
    }
  }

  /**
   * Run `drush deploy` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:deploy')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  public function updateHostDeploy(array $options = [
    'env' => 'dev',
    'host' => NULL,
  ]
  ) {
    $aliases = array_keys($this->getSiteAliases($options['env'], $options['host']));
    $this->performUpdate($aliases, ['deploy'], $options['host']);
  }

  /**
   * Run `drush updatedb` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:database')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  public function updateHostDatabase(array $options = [
    'env' => 'dev',
    'host' => NULL,
  ]
  ) {
    $aliases = array_keys($this->getSiteAliases($options['env'], $options['host']));
    $this->performUpdate($aliases, ['updatedb', '-y'], $options['host']);
  }

  /**
   * Run `drush config:import` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:config')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  public function updateHostConfig(array $options = [
    'env' => 'dev',
    'host' => NULL,
  ]
  ) {
    $aliases = array_keys($this->getSiteAliases($options['env'], $options['host']));
    $this->performUpdate($aliases, ['config:import', '-y'], $options['host']);
  }

  /**
   * Perform the drush updates for the aliases given the desired command.
   *
   * @param string[] $aliases
   *   Drush site aliases.
   * @param string[] $command
   *   Drush command arguments.
   * @param string $host
   *   Acquia host url.
   */
  protected function performUpdate(array $aliases, array $command, string $host) {
    $total_aliases = count($aliases);
    $printOutput = TRUE;

    foreach ($aliases as $position => $alias) {
      $percent = round($position / $total_aliases * 100);
      if ($percent % 5 == 0) {
        $this->yell($percent . "% on $host");
      }
      else {
        $this->say($percent . "% on $host");
      }
      if ($percent > 10) {
        $printOutput = FALSE;
      }

      $tries = 0;
      $this->say($alias);
      while ($tries < 3) {
        $result = $this->localMachineHelper()
          ->execute(array_merge([
            'drush',
            $alias,
          ], $command), NULL, $this->getDir(), $printOutput);
        $tries = $result->isSuccessful() ? 5 : $tries + 1;
      }

      if (!$result->isSuccessful()) {
        $failed_report = sys_get_temp_dir() . '/failed-report.txt';
        $this->localMachineHelper()
          ->getFilesystem()
          ->appendToFile($failed_report, $alias . PHP_EOL);
      }
    }
  }

  /**
   * Get drush site aliases that match the args.
   *
   * @param string $environment
   *   Dev, test, or live environment.
   * @param string|null $host
   *   Drush alias configured host value.
   *
   * @return array
   *   Site aliases.
   */
  protected function getSiteAliases(string $environment, ?string $host = NULL): array {
    $result = $this->localMachineHelper()->execute([
      'drush',
      'site:alias',
      '--format=json',
    ], NULL, $this->getDir(), FALSE);
    if (!$result->isSuccessful()) {
      throw new \Exception($result->getErrorOutput());
    }
    $aliases = json_decode($result->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);

    foreach ($aliases as $alias => $aliasInfo) {
      if (!str_ends_with($alias, '01' . $environment) || !str_contains($aliasInfo['host'], 'acquia')) {
        unset($aliases[$alias]);
      }

      if ($host && !str_contains($aliasInfo['host'], $host)) {
        unset($aliases[$alias]);
      }
    }
    return $aliases;
  }

}
