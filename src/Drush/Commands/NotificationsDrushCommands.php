<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for sending notifications.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class NotificationsDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Send a Slack notification via incoming webhook.
   */
  #[CLI\Command(name: 'sws:slack-notify')]
  #[CLI\Argument(name: 'message', description: 'Message text to send.')]
  #[CLI\Option(name: 'slack-url', description: 'Webhook URL. Defaults to SLACK_NOTIFICATION_URL env var.')]
  public function slackNotify(string $message, array $options = ['slack-url' => NULL]): void {
    $url = $options['slack-url'] ?: getenv('SLACK_NOTIFICATION_URL');
    if (!$url) {
      $this->yell('No Slack webhook URL. Set SLACK_NOTIFICATION_URL or pass --slack-url.', 40, 'red');
      return;
    }
    $this->sendWebhookNotification($message, $url);
    $this->say('Slack notification sent.');
  }

}
