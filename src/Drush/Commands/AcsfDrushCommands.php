<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Output\Checklist;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use GuzzleHttp\Client;

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
  #[CLI\Option(name: 'slack-hook', description: 'Slack webhook url that is used to send notification updates.')]
  #[CLI\Option(name: 'delay-config-import', description: 'Delay the configuration import by the given number of seconds. Only when separate --separate-db-config is passed.')]
  public function updateEnvironmentSites(array $options = [
    'env' => 'dev',
    'separate-db-config' => FALSE,
    'slack-hook' => NULL,
    'delay-config-import' => 0,
  ]
  ) {
    $env = $this->commandData->options()['env'];
    if (!in_array($env, ['dev', 'test', 'live'])) {
      throw new CommandFailedException('Invalid environment option');
    }

    $siteAliases = $this->getSiteAliases($env);
    $updateHosts = [];
    foreach ($siteAliases as $aliasInfo) {
      $updateHosts[$aliasInfo['host']] = $aliasInfo['host'];
    }

    $db_commands = [];
    $config_commands = [];
    $options = [
      "--env=$env",
      '--slack-hook=' . $options['slack-hook'],
    ];

    $separateDbConfig = $this->commandData->options()['separate-db-config'];

    foreach ($updateHosts as $host) {
      if ($separateDbConfig) {
        $db_commands[] = [
          'drush',
          'sws:acsf:update-environment:database',
          '--host=' . $host,
          ...$options,
        ];
        $config_commands[] = [
          'drush',
          'sws:acsf:update-environment:config',
          '--host=' . $host,
          ...$options,
        ];
      }
      else {
        $db_commands[] = [
          'drush',
          'sws:acsf:update-environment:deploy',
          '--host=' . $host,
          ...$options,
        ];
      }
    }

    $stack = preg_replace('/\..*/', '', reset($siteAliases)['user']);
    $report_file = sys_get_temp_dir() . '/' . $stack . '.json';

    $fileSystem = $this->localMachineHelper()->getFilesystem();
    $fileSystem->dumpFile($report_file, json_encode([
      'complete' => [
        'deploy' => [],
        'updatedb' => [],
        'config:import' => [],
      ],
      'failed' => [],
      'messages' => [],
    ]));

    $this->sendSlackMessage("Beginning updates on `$stack.01$env`.");
    $this->localMachineHelper()->executeParallel($db_commands);
    if ($config_commands) {
      $delay = $this->commandData->options()['delay-config-import'];
      if ($delay) {
        $checklist = new Checklist($this->output());
        $outputCallback = $this->getOutputCallback($this->output(), $checklist);

        $checklist->addItem('Syncing database');
        $this->waitTilItsTime($outputCallback, time() + $delay);
        $checklist->completePreviousItem();
      }

      $this->sendSlackMessage("Beginning config import on `$stack.01$env`.");
      $this->localMachineHelper()->executeParallel($config_commands);
    }

    $report = json_decode($fileSystem->readFile($report_file), TRUE);
    $message = "Deployment complete on `$stack`.";
    if ($report['failed']) {
      $this->yell('Failed Sites: ' . implode(', ', array_unique($report['failed'])), 40, 'red');
      $message .= ' ' . count($report['failed']) . ' had errors. Please review.';
    }
    $this->sendSlackMessage($message);
  }

  /**
   * Run `drush deploy` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:deploy')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  #[CLI\Option(name: 'slack-hook', description: 'Slack webhook url that is used to send notification updates.')]
  public function updateHostDeploy(array $options = [
    'env' => 'dev',
    'host' => NULL,
    'slack-hook' => NULL,
  ]
  ) {
    $aliases = $this->getSiteAliases($options['env'], $options['host']);
    $this->performUpdate($aliases, ['deploy'], $options['host']);
  }

  /**
   * Run `drush updatedb` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:database')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  #[CLI\Option(name: 'slack-hook', description: 'Slack webhook url that is used to send notification updates.')]
  public function updateHostDatabase(array $options = [
    'env' => 'dev',
    'host' => NULL,
    'slack-hook' => NULL,
  ]
  ) {
    $aliases = $this->getSiteAliases($options['env'], $options['host']);
    $this->performUpdate($aliases, ['updatedb', '-y'], $options['host']);
  }

  /**
   * Run `drush config:import` on every site on the host.
   */
  #[CLI\Command(name: 'sws:acsf:update-environment:config')]
  #[ClI\Option(name: 'env', description: 'ACSF environment: dev, test, or live.')]
  #[ClI\Option(name: 'host', description: 'ACSF Host.')]
  #[CLI\Option(name: 'slack-hook', description: 'Slack webhook url that is used to send notification updates.')]
  public function updateHostConfig(array $options = [
    'env' => 'dev',
    'host' => NULL,
    'slack-hook' => NULL,
  ]
  ) {
    $aliases = $this->getSiteAliases($options['env'], $options['host']);
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
    $stack = preg_replace('/\..*/', '', reset($aliases)['user']);
    $report_file = sys_get_temp_dir() . '/' . $stack . '.json';

    foreach (array_keys($aliases) as $position => $alias) {
      $printOutput = round($position / count($aliases) * 100) <= 10;

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

      $writeSuccess = FALSE;
      while (!$writeSuccess) {
        $report = json_decode(file_get_contents($report_file), TRUE);
        $report['complete'][reset($command)][] = $alias;

        if (!$result->isSuccessful()) {
          $report['failed'][] = $alias;
        }
        $writeSuccess = file_put_contents($report_file, json_encode($report, JSON_PRETTY_PRINT), LOCK_EX);
      }

      $this->updateMessage($command, $stack);
    }
  }

  /**
   * Display a status message and send a message to slack with a percent update.
   *
   * @param string[] $command
   *   Drush command being run.
   * @param string $stack
   *   Application stack.
   */
  protected function updateMessage(array $command, string $stack): void {
    $command = reset($command);

    $environment = $this->commandData->options()['env'];
    $total_aliases = count($this->getSiteAliases($environment));

    $report_file = sys_get_temp_dir() . '/' . $stack . '.json';
    $report = json_decode(file_get_contents($report_file), TRUE);

    $percent = round(count($report['complete'][$command]) / $total_aliases * 100);
    $this->say(sprintf('%s%% complete', $percent));

    $percent = floor($percent / 10) * 10;
    $message = sprintf('%s%% completed command `%s` on `%s`.', $percent, $command, "$stack.01$environment");

    if (!in_array($message, $report['messages']) && $percent > 0 && $percent % 10 == 0) {
      $writeSuccess = FALSE;
      while (!$writeSuccess) {
        $report = json_decode(file_get_contents($report_file), TRUE);
        $report['messages'][] = $message;
        $writeSuccess = file_put_contents($report_file, json_encode($report, JSON_PRETTY_PRINT), LOCK_EX);
      }

      $this->yell($message);
      $this->sendSlackMessage($message);
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
    static $aliases = [];
    if (!$aliases) {
      $result = $this->localMachineHelper()->execute([
        'drush',
        'site:alias',
        '--format=json',
      ], NULL, $this->getDir(), FALSE);

      if (!$result->isSuccessful()) {
        throw new CommandFailedException($result->getErrorOutput(), $result->getExitCode());
      }
      $aliases = json_decode($result->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);
    }

    foreach ($aliases as $alias => $aliasInfo) {
      if (!str_ends_with($alias, '01' . $environment) || !str_contains($aliasInfo['host'], 'acquia')) {
        unset($aliases[$alias]);
      }

      if ($host && !str_contains($aliasInfo['host'], $host)) {
        unset($aliases[$alias]);
      }
    }
    return array_slice($aliases, 0, 1);
    return $aliases;
  }

  /**
   * Send a slack message to the slack-hook.
   *
   * @param string $message
   *   Message for slack.
   */
  protected function sendSlackMessage(string $message): void {
    static $hasFailed = FALSE;
    if ($hasFailed || !$this->commandData->options()['slack-hook']) {
      return;
    }

    $client = new Client();
    // TODO: use the full API to create threads so it's all in one place.
    $options = ['json' => ['text' => ':acquia: ' . $message]];
    try {
      $client->post($this->commandData->options()['slack-hook'], $options);
    }
    catch (\Throwable $e) {
      $hasFailed = TRUE;
      $this->say('Failed to send slack notification. ' . $e->getMessage());
    }
  }

  /**
   * Checklist delay countdown.
   *
   * @param \Closure $outputCallback
   * @param int $continue_time
   *
   * @return void
   */
  protected function waitTilItsTime(\Closure $outputCallback, int $continue_time) {
    date_default_timezone_set('America/Los_Angeles');
    while ($continue_time > time()) {
      $outputCallback('out', sprintf('Sleeping until %s. Current time %s', date('H:i:s', $continue_time), date('H:i:s')));
      sleep(5);
    }
  }

}
