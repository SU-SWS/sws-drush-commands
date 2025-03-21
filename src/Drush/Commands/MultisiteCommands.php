<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

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
  public function newMultisite(string $site_name) {
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

    // create drush alias.

    //    $this->say("New site generated at <comment>$new_site_dir</comment>");
    //    $this->say("Drush aliases generated:");
    //    $this->say("  * @$remote_alias");
  }

}
