<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Consolidation\Config\Config;
use Consolidation\Config\Loader\ConfigProcessor;
use Drush\Boot\DrupalBootLevels;
use Drush\Config\Loader\YamlConfigLoader;
use Symfony\Component\Console\Input\InputOption;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class MultisiteDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Generates a new multisite.
   *
   * Replaces `blt multisite`.
   */
  #[CLI\Command(name: 'sws:multisite:new-site', aliases: ['multisite'])]
  #[CLI\Argument(name: 'site_name', description: 'Machine name of the multisite.')]
  #[CLI\Option(name: 'no-update-drush', description: 'Flag to disable update the drush/drush.yml with the new multisite name.')]
  #[CLI\Option(name: 'multisites', description: 'List of existing multisite.')]
  #[CLI\Usage(name: 'drush multisite foobar', description: 'New site and updates to drush config')]
  #[CLI\Usage(name: 'drush multisite foobar --no-update-drush', description: 'New site and no update to drush config')]
  public function newMultisite(string $site_name, array $options = [
    'no-update-drush' => InputOption::VALUE_NEGATABLE,
    'multisites' => ['default'],
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

      $options['multisites'][] = $site_name;
      asort($options['multisites']);
      $drush_config['command']['sws']['options']['multisites'] = $options['multisites'];

      file_put_contents($this->getDir() . '/drush/drush.yml', Yaml::dump($drush_config, 99, 2));
    }
  }

  /**
   * Install Drupal.
   *
   * Replaces `blt drupal:install`.
   */
  #[CLI\Command(name: 'sws:multisite:install', aliases: [
    'drupal:install',
    'di',
  ])]
  #[CLI\Option(name: 'site', description: 'Machine name of site.')]
  public function siteInstall(array $options = [
    'site' => 'default',
  ]
  ) {
    $defaultProfile = $this->getConfig()
      ->get('project.profile') ?: 'stanford_profile';
    $fileSystem = $this->localMachineHelper()->getFilesystem();
    $siteConfig = Path::join($this->getDir(), 'docroot', 'sites', $options['site'], 'sws.yml');
    $siteProfile = NULL;

    if ($fileSystem->exists($siteConfig)) {
      $config = new Config();
      $loader = new YamlConfigLoader();
      $processor = new ConfigProcessor();
      $processor->extend($loader->load($siteConfig));
      $config->replace($processor->export());
      $siteProfile = $config->get('site.profile');
    }

    $this->localMachineHelper()->execute([
      'drush',
      'site-install',
      $siteProfile ?: $defaultProfile,
      "--uri={$options['site']}",
      '-v',
      '-y',
    ], NULL, $this->getDir());
  }

  /**
   * Run database and config updates on all multisites.
   */
  #[CLI\Command(name: 'sws:multisite:update')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to update')]
  #[CLI\Option(name: 'partial', description: 'Import config with --partial flag.')]
  #[CLI\Option(name: 'show-output', description: 'Display database updates and config update process.')]
  public function updateSites(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
    'show-output' => FALSE,
  ]
  ) {
    $multiSites = $this->input()->getOption('multisites');

    foreach ($multiSites as $site) {
      $site_dir = $this->getDir() . '/docroot/sites/' . $site;
      $this->say("Beginning updates for $site.");

      if (!$options['partial']) {
        $result = $this->localMachineHelper()->execute([
          'drush',
          '@self',
          'deploy',
          "--uri=$site",
        ], NULL, $site_dir, $options['show-output']);
      }
      else {
        $result = $this->localMachineHelper()->execute([
          'drush',
          '@self',
          'updb',
          '-y',
          "--uri=$site",
        ], NULL, $site_dir);
        if ($result->isSuccessful()) {
          $result = $this->localMachineHelper()->execute([
            'drush',
            '@self',
            'config:import',
            '-y',
            "--uri=$site",
          ], NULL, $site_dir, $options['show-output']);
          if ($result->isSuccessful()) {
            $result = $this->localMachineHelper()->execute([
              'drush',
              '@self',
              'deploy:hook',
              '-y',
              "--uri=$site",
            ], NULL, $site_dir, $options['show-output']);
          }
        }
      }

      if ($result->isSuccessful()) {
        $this->say("Successfully updated $site");
        file_put_contents(sys_get_temp_dir() . '/success-report.txt', $site . PHP_EOL, FILE_APPEND);
        continue;
      }

      $this->say("An error occurred during update on $site:");
      $this->say($result->getErrorOutput());
      file_put_contents(sys_get_temp_dir() . '/failed-report.txt', $site . PHP_EOL, FILE_APPEND);
    }
  }

  /**
   * Run database and config updates on all multisites.
   */
  #[CLI\Command(name: 'sws:multisite:update:parallel')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to update')]
  #[CLI\Option(name: 'partial', description: 'Import config with --partial flag.')]
  #[CLI\Option(name: 'parallel-processes', description: 'How many parallel processes to run simultaneously.')]
  #[CLI\Option(name: 'show-output', description: 'Display database updates and config update process.')]
  public function updateSitesParallel(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
    'parallel-processes' => 5,
    'show-output' => FALSE,
  ]
  ) {
    file_put_contents(sys_get_temp_dir() . '/success-report.txt', '');
    file_put_contents(sys_get_temp_dir() . '/failed-report.txt', '');

    $parallel_executions = (int) getenv('UPDATE_PARALLEL_PROCESSES') ?: $options['parallel-processes'];
    $multiSites = $this->input()->getOption('multisites');
    $multiSites = array_filter($multiSites, [$this, 'isDrupalInstalled']);

    $site_chunks = [];
    $i = 0;
    while ($multiSites) {
      $site = array_splice($multiSites, 0, 1);
      $site_chunks[$i][] = reset($site);
      $i++;
      if ($i >= $parallel_executions) {
        $i = 0;
      }
    }

    $commands = [];
    foreach ($site_chunks as $sites) {
      foreach ($sites as &$site) {
        $site = '--multisites=' . $site;
      }

      $command = ['drush', 'sws:multisite:update', ...$sites];
      if ($options['partial']) {
        $command[] = '--partial';
      }
      if ($options['show-output']) {
        $command[] = '--show-output';
      }
      $commands[] = $command;
    }
    $this->localMachineHelper()->executeParallel($commands);

    $success_report = array_filter(explode("\n", file_get_contents(sys_get_temp_dir() . '/success-report.txt')));
    $failed_report = array_filter(explode("\n", file_get_contents(sys_get_temp_dir() . '/failed-report.txt')));

    $this->yell(sprintf('Updated %s sites successfully.', count($success_report)), 100);

    if ($failed_report) {
      $this->yell(sprintf("Update failed for the following sites:\n%s", implode("\n", $failed_report)), 100, 'red');
      throw new \Exception('Failed update');
    }
  }

  /**
   * Run drush status to check if a site is installed.
   *
   * @param string $site
   *   Site name.
   *
   * @return bool
   *   If a site is installed.
   */
  protected function isDrupalInstalled(string $site): bool {
    $site_dir = $this->getDir() . '/docroot/sites/' . $site;
    if (!file_exists($site_dir)) {
      $this->say(sprintf('No site directory found for %s.', $site));
      return FALSE;
    }
    $install_profile = $this->localMachineHelper()->execute([
      'drush',
      '@self',
      'status',
      "--uri=$site",
      '--fields=install-profile',
      '--format=string',
    ], NULL, $site_dir, FALSE)->getOutput();

    if (!preg_match('/([\w_])+/', $install_profile)) {
      $this->say(sprintf('No installed site detected for %s.', $site));
      return FALSE;
    }
    return TRUE;
  }

}
