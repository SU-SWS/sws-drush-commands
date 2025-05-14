<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class HooksDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Set profile argument for site install.
   */
  #[CLI\Hook(type: 'pre-command', target: 'site:install')]
  public function preSiteInstall() {
    if (!$this->input()->getArgument('profile')) {
      $this->input()->setArgument('profile', [
        $this->getConfig()
          ->get('project.profile') ?: 'stanford_profile',
      ]);
    }
  }

  /**
   * Import configs after site install.
   */
  #[CLI\Hook(type: 'post-command', target: 'site:install')]
  public function postSiteInstall() {
    $uri = $this->input()->getOption('uri');
    $this->localMachineHelper()->execute(['drush', 'cim', "--uri=$uri", '-y']);
  }

}
