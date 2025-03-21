<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Composer\Console\Input\InputOption;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * A Drush command file.
 */
final class TestsCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Test a PHPUnit test report file for expected coverage.
   */
  #[CLI\Command(name: 'tests:phpunit-coverage-check')]
  #[CLI\Argument(name: 'xmlDirectory', description: 'Path to xml coverage directory when using --coverage-xml=[coverage/directory].')]
  #[CLI\Option(name: 'min-coverage', description: 'Minimum coverage percent.')]
  #[CLI\Option(name: 'upload-coverage-report', description: 'Minimum coverage percent.')]
  public function testPhpUnitCoverage(string $xmlDirectory, array $options = [
    'min-coverage' => 90,
    'upload-coverage-report' => FALSE,
    'clover-file' => InputOption::VALUE_OPTIONAL,
  ]
  ) {
    $report = "$xmlDirectory/index.xml";
    if (!file_exists($report)) {
      throw new \Exception('Coverage report not found at ' . $report);
    }

    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHtml(file_get_contents($report));
    $xpath = new \DOMXPath($dom);

    $coverage_percent = $xpath->query("//directory[@name='/']/totals/lines/@percent");
    $percent = (float) $coverage_percent->item(0)->nodeValue;

    $min = $options['min-coverage'];
    if ($min > $percent) {
      throw new \Exception("Test coverage is only at $percent%. $min% is required.");
    }
    $this->yell(sprintf('Coverage at %s%%. %s%% required.', $percent, $min));

    if ($options['upload-coverage-report']) {
      if (!getenv('CC_TEST_REPORTER_ID')) {
        throw new \Exception('Coverage report upload requires CC_TEST_REPORTER_ID environment variable.');
      }

      $this->ensureOption('clover-file', fn() => $this->io()
        ->ask('Path to clover file.'), TRUE);
      $this->uploadCoverageCodeClimate($this->input()->getOption('clover-file'));
    }
  }

  /**
   * Use CodeClimate CLI to upload the phpunit coverage report.
   *
   * @link https://docs.codeclimate.com/docs/circle-ci-test-coverage-example
   */
  public function uploadCoverageCodeClimate(string $coverage_file) {
    if (!file_exists($coverage_file)) {
      throw new \Exception('No coverage to upload to code climate.');
    }

    $repo = $this->getConfigValue('repo.root');
    $this->localMachineHelper()->execute([
      'curl',
      '-L',
      'https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter'
    ],NULL, $this->dir, FALSE);

    $this->localMachineHelper()->execute('chmod +x ./cc-test-reporter',NULL, $this->dir, FALSE);

    copy($coverage_file, $this->dir. '/clover.xml');
    $result = $this->localMachineHelper()->execute('./cc-test-reporter after-build -t clover',NULL, $this->dir, FALSE);
    if(!$result->isSuccessful()){
      throw new \Exception('Failed to upload coverage report.');
    }
  }

}
