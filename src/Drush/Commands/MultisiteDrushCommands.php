<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Symfony\Component\Console\Input\InputOption;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
final class MultisiteDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Generates a new multisite.
   */
  #[CLI\Command(name: 'multisite')]
  #[CLI\Argument(name: 'site_name', description: 'Machine name of the multisite.')]
  #[CLI\Option(name: 'no-update-drush', description: 'Flag to disable update the drush/drush.yml with the new multisite name.')]
  #[CLI\Usage(name: 'drush multisite foobar', description: 'New site and updates to drush config')]
  #[CLI\Usage(name: 'drush multisite foobar --no-update-drush', description: 'New site and no update to drush config')]
  public function newMultisite(string $site_name, array $options = [
    'no-update-drush' => InputOption::VALUE_NEGATABLE,
  ]
  ) {
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
      file_put_contents($this->getDir() . "/drush/sites/$site_name.site.yml", Yaml::dump($new_alias, 99, 2));
      $this->say("Drush aliases generated: @$site_name");
    }

    if ($options['no-update-drush'] !== TRUE) {
      $drush_config = Yaml::parseFile($this->getDir() . '/drush/drush.yml');
      $drush_config['command']['source']['build']['settings']['options']['multsites'][] = $site_name;
      file_put_contents($this->getDir() . '/drush/drush.yml', Yaml::dump($drush_config, 99, 2));
    }
  }

}
