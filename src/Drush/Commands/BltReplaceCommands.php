<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Composer\Console\Input\InputOption;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
final class BltReplaceCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Creates/edits the drush.yml file with contents from the blt.yml.
   */
  #[CLI\Command(name: 'migrate-blt')]
  public function migrateBltConfig(array $options = [
    'app-id' => InputOption::VALUE_OPTIONAL,
    'app-secret' => InputOption::VALUE_OPTIONAL,
  ]) {
    $file_system = $this->localMachineHelper()->getFilesystem();

    $blt_config = $this->getYamlFileContents($this->getDir() . '/blt/blt.yml');
    $local_blt_config = $this->getYamlFileContents($this->getDir() . '/blt/local.blt.yml');
    $drush_config = $this->getYamlFileContents($this->getDir() . '/drush/drush.yml');
    $local_drush_config = $this->getYamlFileContents($this->getDir() . '/drush/local.drush.yml');

    $gitUrl = $blt_config['git']['remotes'] ?? $local_blt_config['git']['remotes'] ?? [];
    $appId = $blt_config['cloud']['appId'] ?? $local_blt_config['cloud']['appId'] ?? NULL;
    $deployGitIgnore = $blt_config['deploy']['gitignore_file'] ?? $local_blt_config['deploy']['gitignore_file'] ?? NULL;
  }

  public function getYamlFileContents(string $path): array {
    $file_system = $this->localMachineHelper()->getFilesystem();
    if (!$file_system->exists($path)) {
      return [];
    }
    return Yaml::parseFile($path);
  }

}
