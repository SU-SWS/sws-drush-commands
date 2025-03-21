<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * A Drush command file.
 */
final class CodeceptionCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Run codeception tests.
   */
  #[CLI\Command(name: 'test:codeception')]
  #[CLI\Argument(name: 'suite', description: 'Test suite to run.')]
  public function runCodeceptionTests(string $suite = NULL) {

  }

}
