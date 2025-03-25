<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Output\Checklist;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(DrupalBootLevels::NONE)]
final class SyncCommands extends DrushCommands {

  use SwsCommandsTrait;

  protected Checklist $checklist;

  /**
   * Sync a site from production to local and perform database updates.
   */
  #[CLI\Command(name: 'site:sync', aliases: ['sync'])]
  #[CLI\Argument(name: 'site_name', description: 'Site name to sync.')]
  #[CLI\Option(name: 'with-files', description: 'Sync files after the database.')]
  #[CLI\Option(name: 'partial', description: 'Run config imports with --partial flag.')]
  public function syncSite(string $site_name, array $options = [
    'with-files' => FALSE,
    'partial' => FALSE,
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
    $this->updateDatabase($outputCallback, $site_name, $options['partial']);
    $this->checklist->completePreviousItem();

    if ($options['with-files']) {
      $this->checklist->addItem('Syncing Files');
      $this->syncFiles($outputCallback, $site_name);
      $this->checklist->completePreviousItem();
    }
  }

  /**
   * @param \Closure $outputCallback
   *   Output callback.
   * @param string $site_name
   *   Multisite name.
   */
  protected function syncDatabase(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Clearing local database");
    $this->localMachineHelper()->execute([
      'drush',
      "@$site_name.local",
      'sql-drop',
    ], $outputCallback, $this->getDir());
    $outputCallback('out', "Syncing database to local");
    $result = $this->localMachineHelper()->execute([
      'drush',
      'sql-sync',
      "@$site_name.prod",
      "@$site_name.local",
      '-y',
    ], $outputCallback, $this->getDir());
    if (!$result->isSuccessful()) {
      throw new \Exception('Failed to update database: ' . $result->getErrorOutput());
    }
  }
  /**
   * @param \Closure $outputCallback
   *   Output callback.
   * @param string $site_name
   *   Multisite name.
   */
  protected function sanitizeDatabase(\Closure $outputCallback, string $site_name) {
    $outputCallback('out', "Sanitizing database");
    $result = $this->localMachineHelper()->execute([
      'drush',
      "@$site_name.local",
      'sql:sanitize',
      '-y',
    ], $outputCallback, $this->getDir());

    if (!$result->isSuccessful()) {
      throw new \Exception('Failed to update database: ' . $result->getErrorOutput());
    }
  }
  /**
   * @param \Closure $outputCallback
   *   Output callback.
   * @param string $site_name
   *   Multisite name.
   * @param bool $partial
   *   If config import should run with partial flag.
   */
  protected function updateDatabase(\Closure $outputCallback, string $site_name, bool $partial = FALSE) {
    $outputCallback('out', "Database updates");
    if (!$partial) {
      $result = $this->localMachineHelper()->execute([
        'drush',
        "@$site_name.local",
        'deploy',
      ], $outputCallback, $this->getDir());
    }
    else {
      $this->localMachineHelper()->execute([
        'drush',
        "@$site_name.local",
        'updatedb',
        '-y',
      ], $outputCallback, $this->getDir());
      $result = $this->localMachineHelper()->execute([
        'drush',
        "@$site_name.local",
        'config:import',
        '--partial',
        '-y',
      ], $outputCallback, $this->getDir());
      $this->localMachineHelper()->execute([
        'drush',
        "@$site_name.local",
        'deploy:hook',
        '-y'
      ], $outputCallback, $this->getDir());
    }
    if (!$result->isSuccessful()) {
      throw new \Exception('Failed to update database: ' . $result->getErrorOutput());
    }
  }
  /**
   * @param \Closure $outputCallback
   *   Output callback.
   * @param string $site_name
   *   Multisite name.
   */
  protected function syncFiles(\Closure $outputCallback, string $site_name) {
    $result = $this->localMachineHelper()->execute([
      'drush',
      'rsync',
      "@$site_name.prod:%files/",
      "@$site_name.local:%files",
      "--exclude-paths='styles:css:js'",
      '-v',
      '-y',
    ], $outputCallback, $this->getDir());
    if (!$result->isSuccessful()) {
      throw new \Exception('Failed to sync public files: ' . $result->getErrorOutput());
    }

    $result = $this->localMachineHelper()->execute([
      'drush',
      'rsync',
      "@$site_name.prod:%files-private/",
      "@$site_name.local:%files-private",
      "--exclude-paths='styles:css:js'",
      '-v',
      '-y',
    ], $outputCallback, $this->getDir());
    if (!$result->isSuccessful()) {
      throw new \Exception('Failed to sync private files: ' . $result->getErrorOutput());
    }
  }

  /**
   * Sync key secret files.
   */
  #[CLI\Command(name: 'sync-keys')]
  public function syncKeys() {}

  /**
   * Sync public and private files from prod site.
   */
  #[CLI\Command(name: 'site:sync-files')]
  #[CLI\Argument(name: 'site_name', description: 'Site name to sync.')]
  public function syncSiteFiles($site_name) {
    $outputCallback = $this->getOutputCallback($this->output(), $this->checklist);
    $this->syncFiles($outputCallback, $site_name);
  }

}
