<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Commands to set up and run PHPUnit and Codeception tests.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class TestsDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Where test results and coverage reports are written.
   */
  private const PHPUNIT_ARTIFACTS = 'artifacts/phpunit';

  /**
   * Test a PHPUnit test report file for expected coverage.
   *
   * Reads the line coverage from the XML coverage report's index.xml. If that
   * doesn't exist, the clover file is used instead.
   */
  #[CLI\Command(name: 'sws:tests:phpunit-coverage-check')]
  #[CLI\Argument(name: 'xml_directory', description: 'Path to xml coverage directory when using --coverage-xml=[coverage/directory].')]
  #[CLI\Option(name: 'min-coverage', description: 'Minimum coverage percent.')]
  #[CLI\Option(name: 'clover-file', description: 'Path to clover.xml file using --coverage-clover=[clover/file.xml]')]
  #[CLI\Usage(name: 'drush sws:tests:phpunit-coverage-check artifacts/phpunit/coverage/xml', description: 'Fail unless line coverage is at least 90%.')]
  public function testPhpUnitCoverage(string $xml_directory = '', array $options = [
    'min-coverage' => 90,
    'clover-file' => NULL,
  ]
  ): void {
    $index = $xml_directory ? Path::makeAbsolute(Path::join($xml_directory, 'index.xml'), $this->getDir()) : NULL;
    $clover = $options['clover-file'] ? Path::makeAbsolute($options['clover-file'], $this->getDir()) : NULL;

    if ($index && file_exists($index)) {
      // <directory name="/"><totals><lines percent="91.5"/> in the coverage
      // namespace.
      $percent = $this->queryXml($index, "//*[local-name()='directory'][@name='/']/*[local-name()='totals']/*[local-name()='lines']/@percent");
      $source = $index;
    }
    elseif ($clover && file_exists($clover)) {
      $statements = $this->queryXml($clover, '/coverage/project/metrics/@statements');
      $covered = $this->queryXml($clover, '/coverage/project/metrics/@coveredstatements');
      $percent = $statements ? round($covered / $statements * 100, 2) : 0.0;
      $source = $clover;
    }
    else {
      throw new CommandFailedException('Coverage report not found at ' . implode(' or ', array_filter([$index, $clover])));
    }

    $min = (float) $options['min-coverage'];
    $message = sprintf('Line coverage is %s%%. %s%% is required. (%s)', $percent, $min, Path::makeRelative($source, $this->getDir()));
    if ($percent < $min) {
      throw new CommandFailedException($message);
    }
    $this->io()->success($message);
  }

  /**
   * Scaffold and run phpunit tests.
   *
   * Runs the Unit and Kernel tests in the "stanford" test suite.
   */
  #[CLI\Command(name: 'sws:source:tests:phpunit', aliases: ['phpunit'])]
  #[CLI\Option(name: 'db-user', description: 'Database user name')]
  #[CLI\Option(name: 'db-pass', description: 'Database password')]
  #[CLI\Option(name: 'db-host', description: 'Database host')]
  #[CLI\Option(name: 'db-name', description: 'Database name')]
  #[CLI\Option(name: 'with-coverage', description: 'Run test with coverage report.')]
  #[CLI\Usage(name: 'drush phpunit --db-host=127.0.0.1 --db-pass="" --with-coverage', description: 'Run the tests and write coverage reports to artifacts/phpunit.')]
  public function phpUnit(array $options = [
    'db-user' => 'root',
    'db-pass' => 'password',
    'db-host' => 'localhost',
    'db-name' => 'drupal',
    'with-coverage' => FALSE,
  ]
  ): void {
    $this->phpUnitConfig($options);

    $artifacts = Path::join($this->getDir(), self::PHPUNIT_ARTIFACTS);
    $command = [
      Path::join($this->getDir(), 'vendor', 'bin', 'phpunit'),
      '--configuration=core',
      '--filter=/(Unit|Kernel)/',
      '--testsuite=stanford',
    ];
    if ($options['with-coverage']) {
      $command[] = "--log-junit=$artifacts/results.xml";
      $command[] = "--coverage-html=$artifacts/coverage/html";
      $command[] = "--coverage-xml=$artifacts/coverage/xml";
      $command[] = "--coverage-clover=$artifacts/coverage/clover.xml";
    }

    $exitCode = $this->passthru($command, Path::join($this->getDir(), 'docroot'));
    if ($exitCode !== 0) {
      throw new CommandFailedException('PHPUnit tests failed', $exitCode);
    }
  }

  /**
   * Scaffold phpunit tests.
   *
   * Writes docroot/core/phpunit.xml from tests/phpunit/example.phpunit.xml,
   * or the template in this package, filling in the database connection.
   */
  #[CLI\Command(name: 'sws:source:tests:phpunit:config', aliases: ['phpunit:config'])]
  #[CLI\Option(name: 'db-user', description: 'Database user name')]
  #[CLI\Option(name: 'db-pass', description: 'Database password')]
  #[CLI\Option(name: 'db-host', description: 'Database host')]
  #[CLI\Option(name: 'db-name', description: 'Database name')]
  public function phpUnitConfig(array $options = [
    'db-user' => 'root',
    'db-pass' => 'password',
    'db-host' => 'localhost',
    'db-name' => 'drupal',
  ]
  ): void {
    $projectTemplate = Path::join($this->getDir(), 'tests', 'phpunit', 'example.phpunit.xml');
    $template = file_exists($projectTemplate) ? $projectTemplate : Path::join(dirname(__DIR__, 3), 'settings', 'example.phpunit.xml');
    $docroot = Path::join($this->getDir(), 'docroot');

    // The credentials are part of the SIMPLETEST_DB URL and the file is XML,
    // so encode them for both.
    $replacements = array_map(fn($value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES), [
      '${drupal.db.username}' => rawurlencode((string) $options['db-user']),
      '${drupal.db.password}' => rawurlencode((string) $options['db-pass']),
      '${drupal.db.host}' => (string) $options['db-host'],
      '${drupal.db.database}' => (string) $options['db-name'],
      '${docroot}' => $docroot,
    ]);

    $file = Path::join($docroot, 'core', 'phpunit.xml');
    (new Filesystem())->dumpFile($file, strtr((string) file_get_contents($template), $replacements));
    $this->logger()->notice(sprintf('Wrote %s from %s.', Path::makeRelative($file, $this->getDir()), Path::makeRelative($template, $this->getDir())));
  }

  /**
   * Prep and run codeception tests.
   *
   * In CI, failed tests are run once more before the command fails, to rule
   * out flaky tests.
   */
  #[CLI\Command(name: 'sws:codeception', aliases: ['codeception'])]
  #[CLI\Option(name: 'site-domain', description: 'Local site domain for testing.')]
  #[CLI\Option(name: 'protocol', description: 'Domain protocol: http or https.')]
  #[CLI\Option(name: 'test-dir', description: 'A path to codeception tests if not in the `tests` directory.')]
  #[CLI\Option(name: 'suite', description: 'Codeception suite to run.')]
  #[CLI\Option(name: 'group', description: 'Codeception group to run.')]
  #[CLI\Option(name: 'shard', description: 'Execute subset of tests to run tests on different machine. To split tests on 3 machines to run with shards: 1/3, 2/3, 3/3.')]
  #[CLI\Option(name: 'filter', description: 'Filter tests by name.')]
  #[CLI\Option(name: 'skip-group', description: 'Skip selected groups.')]
  #[CLI\Option(name: 'fail-fast', description: 'Stop after nth failure.')]
  #[CLI\Usage(name: 'drush codeception --suite=acceptance --test-dir=docroot/profiles/custom/stanford_profile/tests/codeception --shard=1/5', description: 'Run the first fifth of the profile\'s acceptance tests.')]
  public function codeception(array $options = [
    'site-domain' => 'localhost',
    'protocol' => 'http',
    'test-dir' => NULL,
    'suite' => 'acceptance',
    'group' => NULL,
    'shard' => NULL,
    'filter' => NULL,
    'skip-group' => NULL,
    'fail-fast' => NULL,
  ]
  ): void {
    $root = $this->getDir();
    $fileSystem = new Filesystem();
    $suite = $options['suite'] ?: 'acceptance';
    $isCi = (bool) getenv('CI');

    $this->writeCodeceptionConfig((string) $options['site-domain'], (string) $options['protocol']);

    // Tests that live elsewhere, such as in an install profile, are linked
    // into the suite directory for the duration of the run.
    $link = NULL;
    if ($options['test-dir']) {
      $testDir = Path::join($root, $options['test-dir'], $suite);
      if (!is_dir($testDir)) {
        throw new CommandFailedException('Test directory does not exist: ' . $testDir);
      }
      $link = Path::join($root, 'tests', 'codeception', $suite, 'temp');
      $fileSystem->symlink($testDir, $link);
    }

    // Remove the previous report so a stale one can't be mistaken for this
    // run's.
    $report = Path::join($root, 'artifacts', 'report.html');
    $fileSystem->remove($report);

    $command = [Path::join($root, 'vendor', 'bin', 'codecept'), 'run', $suite, '--steps', '--config=tests', '--html'];
    foreach (['group', 'shard', 'filter', 'skip-group', 'fail-fast'] as $option) {
      if ($options[$option]) {
        $command[] = "--$option={$options[$option]}";
      }
    }
    if ($isCi) {
      $command[] = '--env=ci';
    }

    try {
      $exitCode = $this->passthru($command, $root);
      if ($exitCode !== 0 && $isCi && file_exists($report)) {
        $this->io()->warning('Some tests failed. Running the failed tests again.');
        $exitCode = $this->passthru([Path::join($root, 'vendor', 'bin', 'codecept'), 'run', $suite, '--steps', '--config=tests', '--html', '--group=failed', '--env=ci'], $root);
      }
    }
    finally {
      if ($link) {
        $fileSystem->remove($link);
      }
    }

    if (!file_exists($report)) {
      throw new CommandFailedException('Codeception did not produce a report at ' . Path::makeRelative($report, $root), $exitCode ?: 1);
    }
    if ($exitCode !== 0) {
      throw new CommandFailedException('Codeception tests failed. See ' . Path::makeRelative($report, $root), $exitCode);
    }
  }

  /**
   * Write tests/codeception.yml from codeception.dist.yml.
   */
  private function writeCodeceptionConfig(string $domain, string $protocol): void {
    $root = $this->getDir();
    $dist = Path::join($root, 'tests', 'codeception.dist.yml');
    $config = Path::join($root, 'tests', 'codeception.yml');

    if (file_exists($dist)) {
      (new Filesystem())->copy($dist, $config, TRUE);
    }
    if (!file_exists($config)) {
      throw new CommandFailedException('Neither tests/codeception.dist.yml nor tests/codeception.yml exists.');
    }

    file_put_contents($config, strtr((string) file_get_contents($config), [
      '${docroot}' => Path::join($root, 'docroot'),
      '${project.local.hostname}' => $domain,
      '${repo.root}' => $root,
      '${project.local.protocol}' => $protocol,
    ]));
  }

  /**
   * Run a command, showing its output as it runs.
   *
   * @return int
   *   The exit code.
   */
  private function passthru(array $command, string $cwd): int {
    $process = new Process($command, $cwd);
    $process->setTimeout(NULL);
    $this->io()->writeln('<fg=gray>$ ' . $process->getCommandLine() . '</>');

    if (Process::isTtySupported() && $this->input()->isInteractive()) {
      // Keep the test runner's colors and progress output.
      $process->setTty(TRUE);
      return $process->run();
    }
    return $process->run(fn($type, $buffer) => $this->output()->write($buffer));
  }

  /**
   * Get a numeric value from an XML file with an XPath query.
   */
  private function queryXml(string $file, string $query): float {
    $dom = new \DOMDocument();
    if (!@$dom->load($file)) {
      throw new CommandFailedException("$file is not valid XML.");
    }
    $nodes = (new \DOMXPath($dom))->query($query);
    if (!$nodes || !$nodes->length) {
      throw new CommandFailedException("Unable to find the coverage totals in $file.");
    }
    return (float) $nodes->item(0)->nodeValue;
  }

}
