<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Helpers\AcquiaApi;
use Drupal\SwsDrush\Helpers\LocalMachineHelper;
use Drush\Drush;
use GuzzleHttp\Client;

/**
 * Shared helpers for SWS drush commands.
 *
 * Project specific command files use this trait as well, so changes to the
 * public and protected methods here affect them too.
 */
trait SwsCommandsTrait {

  /**
   * Absolute path to the project (composer) root.
   */
  protected string $dir;

  /**
   * Helper for running local commands.
   */
  protected LocalMachineHelper $localMachineHelper;

  /**
   * Get the project (composer) root directory.
   */
  public function getDir(): string {
    return $this->dir ??= Drush::bootstrapManager()->getComposerRoot();
  }

  /**
   * Get a helper for running local commands.
   *
   * New code can use Symfony Process and Filesystem directly. This is kept
   * for the commands and project command files that still use it.
   */
  public function localMachineHelper(): LocalMachineHelper {
    if (!isset($this->localMachineHelper)) {
      $this->localMachineHelper = new LocalMachineHelper();
      $this->localMachineHelper->setLogger($this->logger());
      $this->localMachineHelper->setInput($this->input());
      $this->localMachineHelper->setOutput($this->output());
    }
    return $this->localMachineHelper;
  }

  /**
   * Make sure an option has a value, asking for it if possible.
   *
   * @param string $name
   *   Option name.
   * @param callable $asker
   *   Asks the user for the value when the option is empty and the session is
   *   interactive.
   * @param bool $required
   *   Throw an exception if the option still has no value.
   */
  protected function ensureOption(string $name, callable $asker, bool $required = FALSE): void {
    $value = $this->input()->getOption($name);

    if (($value === NULL || $value === []) && $this->input()->isInteractive()) {
      $value = $asker();
    }

    if ($required && !$value) {
      throw new \InvalidArgumentException(dt('The !optionName option is required.', [
        '!optionName' => $name,
      ]));
    }

    $this->input()->setOption($name, $value);
  }

  /**
   * Send a message to a Slack incoming webhook URL.
   *
   * Silently does nothing if no URL is given and SLACK_NOTIFICATION_URL is
   * not set. A failure to send is reported but never stops the command.
   */
  protected function sendWebhookNotification(string $message, ?string $url = NULL): void {
    $url = $url ?: getenv('SLACK_NOTIFICATION_URL');
    if (!$url) {
      return;
    }
    try {
      (new Client(['timeout' => 10]))->post($url, ['json' => ['text' => $message]]);
    }
    catch (\Throwable $e) {
      $this->logger()->warning('Failed to send Slack notification: ' . $e->getMessage());
    }
  }

  /**
   * Get an Acquia Cloud API connection.
   *
   * Uses the --app-id, --app-key and --app-secret options, asking for any
   * that are missing in an interactive session.
   */
  protected function getAcquiaApi(): AcquiaApi {
    $this->ensureOption('app-id', fn() => $this->io()->ask('Acquia Application ID'), TRUE);
    $this->ensureOption('app-key', fn() => $this->io()->ask('Acquia Application Key'), TRUE);
    $this->ensureOption('app-secret', fn() => $this->io()->password('Acquia Application Secret'), TRUE);

    return new AcquiaApi(
      $this->input()->getOption('app-key'),
      $this->input()->getOption('app-secret'),
    );
  }

}
