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
#[CLI\Bootstrap(DrupalBootLevels::NONE)]
final class MultisiteDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Generates a new multisite.
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
  public function updateSites(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
  ]
  ) {
    $errors = [];
    $multisites = $this->input()->getOption('multisites');
    foreach ($multisites as $site) {
      if (!$options['partial']) {
        $result = $this->localMachineHelper()->execute([
          'drush',
          '@self',
          'deploy',
          "-l $site",
        ], NULL, $this->getDir());
      }
      else {
        $result = $this->localMachineHelper()->execute([
          'drush',
          '@self',
          'updb',
          '-y',
          "-l $site",
        ], NULL, $this->getDir());
        if ($result->isSuccessful()) {
          $result = $this->localMachineHelper()->execute([
            'drush',
            '@self',
            'config:import',
            '-y',
            "-l $site",
          ], NULL, $this->getDir());
          if ($result->isSuccessful()) {
            $result = $this->localMachineHelper()->execute([
              'drush',
              '@self',
              'deploy:hook',
              '-y',
              "-l $site",
            ], NULL, $this->getDir());
          }
        }
      }
      if (!$result->isSuccessful()) {
        $errors[] = $site;
      }
    }
    if ($errors) {
      throw new \Exception('Failed to update sites: ' . implode(', ', $errors));
    }
    $this->say(sprintf('Successfully updated %s sites', count($multisites)));
  }

  /**
   * Run database and config updates on all multisites.
   */
  #[CLI\Command(name: 'sws:multisite:update:parallel')]
  #[CLI\Option(name: 'multisites', description: 'List of sites to update')]
  #[CLI\Option(name: 'partial', description: 'Import config with --partial flag.')]
  public function updateSitesParallel(array $options = [
    'multisites' => ['default'],
    'partial' => FALSE,
  ]
  ) {
    $parallel_executions = (int) getenv('UPDATE_PARALLEL_PROCESSES') ?: 10;
    $multisites = $this->input()->getOption('multisites');

    $i = 0;
    while ($multisites) {
      $site = array_splice($multisites, 0, 1);
      $site_chunks[$i][] = reset($site);
      $i++;
      if ($i >= $parallel_executions) {
        $i = 0;
      }
    }

    $commands = [];
    foreach ($site_chunks as $sites) {
      $command = 'drush multisite:update --multisites=' . implode(' --multisites=', $sites);
      if ($options['partial']) {
        $command .= ' --partial';
      }
      $commands[] = $command;
    }
    return $this->localMachineHelper()
      ->executeFromCmd(implode(' & ', $commands));
  }

}
