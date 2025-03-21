<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Utils\StringUtils;
use Robo\Contract\VerbosityThresholdInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * A Drush command file.
 */
final class SettingsCommands extends DrushCommands {

  /**
   * Generates default settings files for Drupal and drush.
   */
  #[CLI\Command(name: 'source:build:settings', aliases: ['settings'])]
  public function buildSettings() {

    // Generate hash file in salt.txt.
    $this->hashSalt();

    $default_multisite_dir = $this->getConfigValue('docroot') . "/sites/default";
    $default_project_default_settings_file = "$default_multisite_dir/default.settings.php";

    $multisites = $this->getConfigValue('multisites');
    $initial_site = $this->getConfigValue('site');

    $this->logger->debug("Multisites found: " . implode(',', $multisites));
    $this->logger->debug("Initial site: $initial_site");

    foreach ($multisites as $multisite) {
      // Generate settings.php.
      $multisite_dir = $this->getConfigValue('docroot') . "/sites/$multisite";
      $project_default_settings_file = "$multisite_dir/default.settings.php";
      $project_settings_file = "$multisite_dir/settings.php";

      // Generate local.settings.php.
      $blt_local_settings_file = $this->getConfigValue('blt.root') . '/settings/default.local.settings.php';
      $default_local_settings_file = "$multisite_dir/settings/default.local.settings.php";
      $project_local_settings_file = "$multisite_dir/settings/local.settings.php";

      // Generate default.includes.settings.php.
      $blt_includes_settings_file = $this->getConfigValue('blt.root') . '/settings/default.includes.settings.php';
      $default_includes_settings_file = "$multisite_dir/settings/default.includes.settings.php";

      // Generate sites/settings/default.global.settings.php.
      $blt_glob_settings_file = $this->getConfigValue('blt.root') . '/settings/default.global.settings.php';
      $default_glob_settings_file = $this->getConfigValue('docroot') . "/sites/settings/default.global.settings.php";
      $global_settings_file = $this->getConfigValue('docroot') . "/sites/settings/global.settings.php";

      // Generate local.drush.yml.
      $blt_local_drush_file = $this->getConfigValue('blt.root') . '/settings/default.local.drush.yml';
      $default_local_drush_file = "$multisite_dir/default.local.drush.yml";
      $project_local_drush_file = "$multisite_dir/local.drush.yml";

      $copy_map = [
        $blt_local_settings_file => $default_local_settings_file,
        $default_local_settings_file => $project_local_settings_file,
        $blt_includes_settings_file => $default_includes_settings_file,
        $blt_local_drush_file => $default_local_drush_file,
        $default_local_drush_file => $project_local_drush_file,
      ];
      // Define an array of files that require property expansion.
      $expand_map = [
        $default_local_settings_file => $project_local_settings_file,
        $default_local_drush_file => $project_local_drush_file,
      ];

      // Add default.global.settings.php if global.settings.php does not exist.
      if (!file_exists($global_settings_file)) {
        $copy_map[$blt_glob_settings_file] = $default_glob_settings_file;
      }

      // Only add the settings file if the default exists.
      if (file_exists($default_project_default_settings_file)) {
        $copy_map[$default_project_default_settings_file] = $project_default_settings_file;
        $copy_map[$project_default_settings_file] = $project_settings_file;
      }
      elseif (!file_exists($project_settings_file)) {
        $this->logger->warning("No $default_project_default_settings_file file found.");
      }

      $task = $this->taskFilesystemStack()
        ->stopOnFail()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->chmod($multisite_dir, 0777);

      if (file_exists($project_settings_file)) {
        $task->chmod($project_settings_file, 0777);
      }

      // Copy files without overwriting.
      foreach ($copy_map as $from => $to) {
        if (!file_exists($to)) {
          $task->copy($from, $to);
        }
      }

      $result = $task->run();

      foreach ($expand_map as $from => $to) {
        $this->getConfig()->expandFileProperties($to);
      }

      if (!$result->wasSuccessful()) {
        throw new \Exception("Unable to copy files settings files from BLT into your repository.");
      }

      $result = $this->taskWriteToFile($project_settings_file)
        ->appendUnlessMatches('#vendor/acquia/blt/settings/blt.settings.php#', 'require DRUPAL_ROOT . "/../vendor/acquia/blt/settings/blt.settings.php";' . "\n")
        ->appendUnlessMatches('#Do not include additional settings here#', $this->settingsWarning . "\n")
        ->append(TRUE)
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->run();

      if (!$result->wasSuccessful()) {
        throw new \Exception("Unable to modify $project_settings_file.");
      }

      $result = $this->taskFilesystemStack()
        ->chmod($project_settings_file, 0644)
        ->stopOnFail()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->run();

      if (!$result->wasSuccessful()) {
        $this->getInspector()
          ->getFs()
          ->makePathRelative($project_settings_file, $this->getConfigValue('repo.root'));
        throw new \Exception("Unable to set permissions on $project_settings_file.");
      }
    }
  }

  /**
   * Writes a hash salt to ${repo.root}/salt.txt if one does not exist.
   *
   * @command drupal:hash-salt:init
   * @aliases dhsi setup:hash-salt
   *
   * @return int
   *   A CLI exit code.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  public function hashSalt() {
    $hash_salt_file = $this->getConfigValue('repo.root') . '/salt.txt';
    if (!file_exists($hash_salt_file)) {
      $this->say("Generating hash salt...");
      file_put_contents($hash_salt_file, StringUtils::generatePassword(55));
    }
  }

}
