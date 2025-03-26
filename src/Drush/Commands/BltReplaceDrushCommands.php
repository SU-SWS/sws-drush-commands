<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Boot\DrupalBootLevels;
use Symfony\Component\Console\Input\InputOption;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(DrupalBootLevels::NONE)]
final class BltReplaceDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Creates/edits the drush.yml file with contents from the blt.yml.
   */
  #[CLI\Command(name: 'migrate-blt')]
  #[CLI\Option(name: 'app-key', description: 'Acquia API key')]
  #[CLI\Option(name: 'app-secret', description: 'Acquia API secret')]
  public function migrateBltConfig(array $options = [
    'app-key' => InputOption::VALUE_OPTIONAL,
    'app-secret' => InputOption::VALUE_OPTIONAL,
  ]
  ) {
    if ($options['app-key'] != InputOption::VALUE_OPTIONAL || $options['app-secret'] != InputOption::VALUE_OPTIONAL) {
      $this->checkLocalAcquiaApiKeys();
    }

    $this->ensureOption('app-key', fn() => $this->io()
      ->ask('Acquia Application API Key'));
    $this->ensureOption('app-secret', fn() => $this->io()
      ->ask('Acquia Application API Secret'));

    $this->localmachineHelper()->checkRequiredBinariesExist(['blt']);

    $file_system = $this->localMachineHelper()->getFilesystem();

    $gitUrl = $this->getBltConfig('git.remotes', []);
    $appId = $this->getBltConfig('cloud.appId');
    $deployGitIgnore = $this->getBltConfig('deploy.gitignore_file');
    $multisites = array_filter(explode("\n", $this->getBltConfig('multisites', '')));

    $rsyncSsh = $this->getBltConfig('keys_rsync.ssh');
    $rsyncFiles = explode("\n", $this->getBltConfig('keys_rsync.files', ''));

    $appKey = $this->input->getOption('app-key');
    $appSecret = $this->input->getOption('app-secret');

    $drush_config = $this->getYamlFileContents($this->getDir() . '/drush/drush.yml');
    $local_drush_config = $this->getYamlFileContents($this->getDir() . '/drush/local.drush.yml');

    $drush_config['command']['artifact']['deploy']['options']['git-url'] = $gitUrl;
    $drush_config['command']['artifact']['deploy']['options']['post-build-script'] = 'drush/deploy-cleanup.sh';
    $drush_config['command']['artifact']['deploy']['options']['artifact-dir'] = 'deploy';

    $drush_config['command']['acquia']['alias-build']['options']['alias-dir'] = 'drush/sites';
    $drush_config['command']['acquia']['options']['app-id'] = $appId;

    $local_drush_config['command']['acquia']['options']['app-key'] = $appKey;
    $local_drush_config['command']['acquia']['options']['app-secret'] = $appSecret;

    if ($rsyncSsh) {
      $drush_config['command']['sync-keys']['options']['sync-ssh'] = $rsyncSsh;
      $drush_config['command']['sync-keys']['options']['sync-files'] = $rsyncFiles;
    }

    if ($multisites) {
      $drush_config['command']['multisite']['options']['multisites'] = $multisites;
    }

    $drush_config['drush']['paths']['config'][] = 'drush/local.drush.yml';

    $this->localMachineHelper()
      ->writeFile($this->getDir() . '/drush/drush.yml', Yaml::dump($drush_config, 99, 2));
    $this->localMachineHelper()
      ->writeFile($this->getDir() . '/drush/local.drush.yml', Yaml::dump($local_drush_config, 99, 2));

    $file_system->copy($deployGitIgnore, $this->getDir() . '/drush/deploy.gitignore');
    $file_system->copy(__DIR__ . '/../../../settings/deploy.gitignore', $this->getDir() . '/drush/deploy.gitignore');
    $file_system->copy(__DIR__ . '/../../../settings/deploy-cleanup.sh', $this->getDir() . '/drush/deploy-cleanup.sh');

    $file_system->chmod($this->getDir() . '/drush/deploy-cleanup.sh', 0777);

    if ($file_system->exists($this->getDir() . '/.circleci/config.yml')) {
      $circleCiConfig = file_get_contents($this->getDir() . '/.circleci/config.yml');
      if (str_contains($circleCiConfig, 'blt ')) {
        $this->say('Be sure to update CircleCi Config.');
      }
    }

    $githubWorkflows = glob($this->getDir() . '/.github/workflows/*.yml');
    foreach ($githubWorkflows as $workflow) {
      $workflowConfig = file_get_contents($workflow);
      if (str_contains($workflowConfig, 'blt ')) {
        $this->say('Be sure to update Github Actions Config: ' . basename($workflow));
      }
    }

    $this->say("Be sure to update any Acquia hooks in {$this->getDir()}/hooks");
  }

  /**
   * Try to set input options from the cloud api conf.
   */
  public function checkLocalAcquiaApiKeys(): void {
    $file_system = $this->localMachineHelper()->getFilesystem();
    $cloud_api_conf = $_SERVER['HOME'] . '/.acquia/cloud_api.conf';
    if (!$file_system->exists($cloud_api_conf)) {
      return;
    }

    $cloud_conf = json_decode(file_get_contents($cloud_api_conf), TRUE, 512, JSON_THROW_ON_ERROR);
    if (isset($cloud_conf['key']) && isset($cloud_conf['secret'])) {
      $this->input()->setOption('app-key', $cloud_conf['key']);
      $this->input()->setOption('app-secret', $cloud_conf['secret']);
      return;
    }

    if (isset($cloud_conf['acli_key']) && isset($cloud_conf['keys'])) {
      $apiKey = $cloud_conf['acli_key'];
      $apiSecret = $cloud_conf['keys']->$apiKey->secret;

      $this->input()->setOption('app-key', $apiKey);
      $this->input()->setOption('app-secret', $apiSecret);
    }
  }

  /**
   * Run BLT config get to find the calculated value.
   *
   * @param string $config_name
   *   BLT config name path, deploy.git.
   * @param mixed $default
   *   Default value.
   *
   * @return mixed
   *   Value of the config, or default value.
   */
  protected function getBltConfig(string $config_name, mixed $default = NULL): mixed {
    $result = $this->localMachineHelper()->execute([
      'blt',
      'blt:config:get',
      $config_name,
    ], NULL, $this->getDir(), FALSE);
    return $result->isSuccessful() ? preg_replace('/\n$/', '', $result->getOutput()) : $default;
  }

  /**
   * Get the contents of a yaml file, or an empty array.
   *
   * @param string $path
   *   Path to file.
   *
   * @return array
   *   File contents.
   */
  protected function getYamlFileContents(string $path): array {
    $file_system = $this->localMachineHelper()->getFilesystem();
    if (!$file_system->exists($path)) {
      return [];
    }
    return Yaml::parseFile($path);
  }

}
