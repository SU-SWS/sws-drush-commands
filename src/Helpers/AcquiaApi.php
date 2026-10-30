<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Helpers;

use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\DatabaseBackups;
use AcquiaCloudApi\Endpoints\Databases;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Notifications;

/**
 * Acquia Cloud API endpoints used by the SWS commands.
 *
 * The SDK connector caches the access token and renews it when it expires,
 * so a single instance can be used for long running commands.
 *
 * @link https://github.com/typhonius/acquia-php-sdk-v2
 */
final class AcquiaApi {

  public readonly Applications $acquiaApplications;

  public readonly Environments $acquiaEnvironments;

  public readonly Databases $acquiaDatabases;

  public readonly DatabaseBackups $acquiaDatabaseBackups;

  public readonly Notifications $acquiaNotifications;

  public function __construct(string $appKey, string $appSecret) {
    $client = Client::factory(new Connector([
      'key' => $appKey,
      'secret' => $appSecret,
    ]));

    $this->acquiaApplications = new Applications($client);
    $this->acquiaEnvironments = new Environments($client);
    $this->acquiaDatabases = new Databases($client);
    $this->acquiaDatabaseBackups = new DatabaseBackups($client);
    $this->acquiaNotifications = new Notifications($client);
  }

}
