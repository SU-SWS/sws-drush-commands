<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Symfony\Component\Console\Input\InputOption;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
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
    $this->ensureOption('app-key', fn() => $this->io()
      ->ask('Acquia Application API Key'));
    $this->ensureOption('app-secret', fn() => $this->io()
      ->ask('Acquia Application API Secret'));

    $this->localmachineHelper()->checkRequiredBinariesExist(['blt']);

    $file_system = $this->localMachineHelper()->getFilesystem();

    $gitUrl = $this->getBltConfig('git.remotes', []);
    $appId = $this->getBltConfig('cloud.appId');
    $deployGitIgnore = $this->getBltConfig('deploy.gitignore_file');
    $appKey = $this->input->getOption('app-key');
    $appSecret = $this->input->getOption('app-secret');

    $drush_config = $this->getYamlFileContents($this->getDir() . '/drush/drush.yml');
    $local_drush_config = $this->getYamlFileContents($this->getDir() . '/drush/local.drush.yml');

    $drush_config['command']['artifact']['deploy']['options']['git-url'] = $gitUrl;
    $drush_config['command']['artifact']['deploy']['options']['post-build-script'] = 'drush/deploy-cleanup.sh';
    $drush_config['command']['artifact']['deploy']['options']['artifact-dir'] = 'deploy';

    $drush_config['command']['site']['alias-build']['options']['app-id'] = $appId;
    $local_drush_config['command']['site']['alias-build']['options']['app-key'] = $appKey;
    $local_drush_config['command']['site']['alias-build']['options']['app-secret'] = $appSecret;
    $drush_config['command']['site']['alias-build']['options']['alias-dir'] = 'drush/sites';

    $drush_config['command']['acquia']['clean-git']['options']['app-id'] = $appId;
    $local_drush_config['command']['acquia']['clean-git']['options']['app-key'] = $appKey;
    $local_drush_config['command']['acquia']['clean-git']['options']['app-secret'] = $appSecret;

    $drush_config['command']['acquia']['clean-databases']['options']['app-id'] = $appId;
    $local_drush_config['command']['acquia']['clean-databases']['options']['app-key'] = $appKey;
    $local_drush_config['command']['acquia']['clean-databases']['options']['app-secret'] = $appSecret;

    $this->localMachineHelper()
      ->writeFile($this->getDir() . '/drush/drush.yml', Yaml::dump($drush_config));
    $this->localMachineHelper()
      ->writeFile($this->getDir() . '/drush/local.drush.yml', Yaml::dump($local_drush_config));

    $file_system->copy($deployGitIgnore, $this->getDir() . '/drush/deploy.gitignore');
    $file_system->copy(__DIR__ . '/../../../settings/deploy.gitignore', $this->getDir(). '/drush/deploy.gitignore');
    $file_system->copy(__DIR__ . '/../../../settings/deploy-cleanup.sh', $this->getDir(). '/drush/deploy-cleanup.sh');

    $file_system->chmod($this->getDir(). '/drush/deploy-cleanup.sh', 0777);
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
    ]);
    return $result->isSuccessful() ? $result->getOutput() : $default;
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
