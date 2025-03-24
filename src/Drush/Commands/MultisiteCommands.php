<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
final class MultisiteCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Generates a new multisite.
   */
  #[CLI\Command(name: 'multisite')]
  #[CLI\Argument(name: 'site_name', description: 'Machine name of the multisite.')]
  #[CLI\Option(name: 'update-drush-config', description: 'Flag to update the drush/drush.yml with the new multisite name.')]
  #[CLI\Usage(name: 'New site and update drush config:', description: 'drush multisite foobar --update-drush-config')]
  public function newMultisite(string $site_name, array $options = [
    'update-drush-config' => false,
  ]) {
    $this->say("This will generate a new site in the docroot/sites directory.");

    $new_site_dir = $this->getDir() . '/docroot/sites/' . $site_name;

    if (file_exists($new_site_dir)) {
      throw new \Exception("Cannot generate new multisite, $new_site_dir already exists!");
    }
    $default_site_dir = $this->getDir() . '/docroot/sites/default';

    $this->localMachineHelper()->execute([
      'rsync -r',
      $default_site_dir,
      $new_site_dir,
      '--exclude local.settings.php',
      '--exclude files',
    ], NULL, $this->getDir());
    $this->say("New site generated at <comment>$new_site_dir</comment>");

    if (file_exists($this->getDir() . '/drush/sites/default.site.yml')) {
      $new_alias = Yaml::parseFile($this->getDir() . '/drush/sites/default.site.yml');
      foreach ($new_alias as &$alias) {
        $alias['uri'] = $site_name;
      }
      file_put_contents($this->getDir() . "/drush/sites/$site_name.site.yml", Yaml::dump($new_alias));
      $this->say("Drush aliases generated: @$site_name");
    }

    if ($options['update-drush-config']) {
      $drush_config = Yaml::parseFile($this->getDir() . '/drush/drush.yml');
      $drush_config['command']['source']['build']['settings']['options']['multsites'][] = $site_name;
      file_put_contents($this->getDir() . '/drush/drush.yml', Yaml::dump($drush_config));
    }
  }

}
