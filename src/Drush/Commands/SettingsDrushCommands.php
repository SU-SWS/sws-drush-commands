<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Commands for drush config, settings files and the hash salt.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class SettingsDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * The settings.php line that loads the shared SWS settings.
   */
  private const SWS_SETTINGS_REQUIRE = "require DRUPAL_ROOT . '/../drush/Commands/contrib/sws-drush-commands/settings/sws.settings.php';";

  /**
   * Files created by the current run, relative to the project root.
   *
   * @var string[]
   */
  private array $created = [];

  /**
   * Get config value of drush.
   *
   * In text format, a list prints one value per line, which is handy in
   * shell scripts. Missing config prints nothing, or "null" as JSON.
   */
  #[CLI\Command(name: 'sws:drush-config')]
  #[CLI\Argument(name: 'config', description: 'Config key in dot notation, e.g. command.sws.options.multisites.')]
  #[CLI\Option(name: 'format', description: 'Output format: text or json.')]
  #[CLI\Usage(name: 'drush sws:drush-config command.sws.options.multisites', description: 'Print each multisite on its own line.')]
  #[CLI\Usage(name: 'drush sws:drush-config project.frontend-repos --format=json', description: 'Print the value as JSON.')]
  public function getDrushConfig(string $config, array $options = [
    'format' => 'text',
  ]
  ): void {
    $value = $this->getConfig()->get($config);

    if ($options['format'] === 'json') {
      $this->writeRaw(json_encode($value));
      return;
    }
    if ($options['format'] !== 'text') {
      throw new CommandFailedException('Invalid format. Use text or json.');
    }

    foreach (is_array($value) ? $value : [$value] as $item) {
      if ($item === NULL) {
        continue;
      }
      // Nested values are printed as JSON so they stay on one line.
      $this->writeRaw(is_scalar($item) ? (is_bool($item) ? var_export($item, TRUE) : (string) $item) : json_encode($item));
    }
  }

  /**
   * Generates default settings files for Drupal and drush.
   *
   * Existing files are never overwritten, so it's safe to run again after
   * adding a site. Delete a site's local.settings.php or local.drush.yml to
   * regenerate it, e.g. with different database credentials.
   */
  #[CLI\Command(name: 'sws:multisite:settings', aliases: ['settings'])]
  #[CLI\Option(name: 'db-port', description: 'Database Port')]
  #[CLI\Option(name: 'db-host', description: 'Database Host')]
  #[CLI\Option(name: 'db-user', description: 'Database User')]
  #[CLI\Option(name: 'db-pass', description: 'Database Password')]
  #[CLI\Option(name: 'db-name', description: 'Database Name')]
  #[CLI\Option(name: 'multisites', description: 'List of multisites tracked in the code base. This should be stored in drush/drush.yml file.')]
  #[CLI\Usage(name: 'drush settings --db-port=3306 --db-host=mysql --db-user=root --db-pass=root --db-name=foobar', description: 'Build settings files using the provided database credentials.')]
  public function buildSettings(array $options = [
    'db-port' => 3306,
    'db-host' => 'localhost',
    'db-user' => 'user',
    'db-pass' => 'password',
    'db-name' => 'drupal',
    'multisites' => ['default'],
  ]
  ): void {
    $this->created = [];
    $sitesDir = Path::join($this->getDir(), 'docroot', 'sites');
    $templates = Path::join(dirname(__DIR__, 3), 'settings');
    $database = [
      '${drupal.db.port}' => (string) $options['db-port'],
      '${drupal.db.host}' => (string) $options['db-host'],
      '${drupal.db.username}' => (string) $options['db-user'],
      '${drupal.db.password}' => (string) $options['db-pass'],
      '${drupal.db.database}' => (string) $options['db-name'],
    ];

    $this->hashSalt();

    // Settings shared by every site.
    $this->copyFile("$templates/default.global.settings.php", "$sitesDir/settings/default.global.settings.php");
    $this->copyFile("$sitesDir/settings/default.local.settings.php", "$sitesDir/settings/local.settings.php");

    $sites = array_filter(array_map('trim', explode(',', implode(',', (array) $options['multisites']))));
    $missing = [];
    foreach (array_unique($sites) as $site) {
      $siteDir = "$sitesDir/$site";
      if (!is_dir($siteDir)) {
        $missing[] = $site;
        continue;
      }

      $this->copyFile("$templates/default.local.settings.php", "$siteDir/settings/default.local.settings.php");
      $this->copyFile("$siteDir/settings/default.local.settings.php", "$siteDir/settings/local.settings.php", $database);
      $this->copyFile("$templates/default.includes.settings.php", "$siteDir/settings/default.includes.settings.php");
      $this->copyFile("$templates/default.local.drush.yml", "$siteDir/default.local.drush.yml");
      // The site name is the local URI; sites.php maps it to the directory.
      $this->copyFile("$siteDir/default.local.drush.yml", "$siteDir/local.drush.yml", ['${uri}' => $site]);
      $this->copyFile("$sitesDir/default/default.settings.php", "$siteDir/default.settings.php");
      $this->copyFile("$siteDir/default.settings.php", "$siteDir/settings.php");
      $this->requireSwsSettings("$siteDir/settings.php");
    }

    if ($missing) {
      $this->logger()->warning('Skipped sites without a directory in docroot/sites: ' . implode(', ', $missing));
    }
    if (!$this->created) {
      $this->io()->success('All settings files already exist.');
      return;
    }
    $this->io()->success(sprintf('Created %d settings file(s).', count($this->created)));
    $this->io()->listing($this->created);
  }

  /**
   * Writes a hash salt to ${repo.root}/salt.txt if one does not exist.
   */
  #[CLI\Command(name: 'sws:drupal:hash-salt:init', aliases: [
    'dhsi',
    'setup:hash-salt',
  ])]
  public function hashSalt(): void {
    $file = Path::join($this->getDir(), 'salt.txt');
    if (file_exists($file)) {
      return;
    }
    // The same format Drupal uses for hash salts.
    $this->writeFile($file, rtrim(strtr(base64_encode(random_bytes(55)), '+/', '-_'), '='));
    $this->io()->writeln('Generated a hash salt in salt.txt.');
  }

  /**
   * Copy a file unless the destination already exists.
   *
   * @param string $from
   *   Source file. Nothing happens if it doesn't exist.
   * @param string $to
   *   Destination file.
   * @param array $replacements
   *   Placeholders to replace in the copy.
   */
  private function copyFile(string $from, string $to, array $replacements = []): void {
    if (!file_exists($from) || file_exists($to)) {
      return;
    }
    $this->writeFile($to, strtr((string) file_get_contents($from), $replacements));
  }

  /**
   * Make sure settings.php loads the shared SWS settings.
   */
  private function requireSwsSettings(string $settingsFile): void {
    if (!file_exists($settingsFile)) {
      $this->logger()->warning("$settingsFile does not exist, so it can't load sws.settings.php.");
      return;
    }
    $contents = (string) file_get_contents($settingsFile);
    if (preg_match('/^.*sws\.settings\.php/m', $contents)) {
      return;
    }
    file_put_contents($settingsFile, rtrim($contents) . "\n\n" . self::SWS_SETTINGS_REQUIRE . "\n");
    $this->io()->writeln('Added sws.settings.php to ' . Path::makeRelative($settingsFile, $this->getDir()));
  }

  /**
   * Write a file, creating its directory if needed, and record it.
   */
  private function writeFile(string $file, string $contents): void {
    (new Filesystem())->dumpFile($file, $contents);
    $this->created[] = Path::makeRelative($file, $this->getDir());
  }

  /**
   * Write a line to stdout exactly as given, without console formatting.
   */
  private function writeRaw(string $line): void {
    $this->output()->writeln($line, OutputInterface::OUTPUT_RAW);
  }

}
