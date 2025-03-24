<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Output\Checklist;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * A Drush command file.
 */
final class SyncCommands extends DrushCommands {

  use SwsCommandsTrait;

  protected Checklist $checklist;

  #[CLI\Command(name: 'site:sync', aliases: ['sync'])]
  #[CLI\Argument(name: 'site_name', description: 'Site name to sync.')]
  #[CLI\Option(name: 'with-files', description: 'Sync files after the database.')]
  #[CLI\Option(name: 'partial-cim', description: 'Run config imports with --partial flag.')]
  public function syncSite(string $site_name, array $options = [
    'with-files' => FALSE,
    'partial-cim' => FALSE,
  ]
  ) {
    $this->checklist = new Checklist($this->output());
    $outputCallback = $this->getOutputCallback($this->output(), $this->checklist);

    $this->checklist->addItem('Syncing database');
    $this->syncDatabase($outputCallback, $site_name);
    $this->checklist->completePreviousItem();

    $this->checklist->addItem('Sanitize database');
    $this->sanitizeDatabase($outputCallback, $site_name);
    $this->checklist->completePreviousItem();

    $this->checklist->addItem('Update database');
    $this->updateDatabase($outputCallback, $site_name);
    $this->checklist->completePreviousItem();

    if ($options['with-files']) {
      $this->checklist->addItem('Syncing Files');
      $this->syncFiles($outputCallback, $site_name);
      $this->checklist->completePreviousItem();
    }
  }

  protected function syncDatabase(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Clearing local database");
  }

  protected function sanitizeDatabase(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Sanitize database");
  }

  protected function updateDatabase(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Database updates");
  }

  protected function syncFiles(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Clearing local database");
  }

  #[CLI\Command(name: 'sync-keys')]
  public function syncKeys() {

  }

  #[CLI\Command(name: 'site:sync-files')]
  #[CLI\Argument(name: 'site_name', description: 'Site name to sync.')]
  public function syncSiteFiles($site_name) {

  }

}
