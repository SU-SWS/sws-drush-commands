<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use AcquiaCloudApi\Exception\ApiErrorException;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Input\InputOption;

/**
 * A Drush command file.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::NONE)]
final class AcquiaCleanupDrushCommands extends DrushCommands {

  use SwsCommandsTrait;

  /**
   * Delete on-demand database backups that are older than the minimum age.
   *
   * Scheduled (daily) backups are managed by Acquia and are never touched.
   * The backups to delete are listed first, and in an interactive session
   * nothing is deleted until confirmed.
   */
  #[CLI\Command(name: 'sws:acquia:clean-databases')]
  #[CLI\Option(name: 'app-id', description: 'Acquia application ID')]
  #[CLI\Option(name: 'app-key', description: 'Acquia API key')]
  #[CLI\Option(name: 'app-secret', description: 'Acquia API secret')]
  #[CLI\Option(name: 'minimum-age', description: 'Only delete backups older than this. Seconds, or a duration such as 7d, 12h or 30m.')]
  #[CLI\Option(name: 'environment', description: 'Acquia environment(s) to clean. Multiple environments use --environment=dev --environment=stage')]
  #[CLI\Option(name: 'dry-run', description: 'List the backups that would be deleted without deleting them.')]
  #[CLI\Usage(name: 'drush sws:acquia:clean-databases --dry-run', description: 'Show which on-demand backups older than a week would be deleted.')]
  #[CLI\Usage(name: 'drush sws:acquia:clean-databases --minimum-age=30d --environment=prod -y', description: 'Delete production on-demand backups older than 30 days without asking.')]
  public function cleanOldDatabases(array $options = [
    'app-id' => InputOption::VALUE_REQUIRED,
    'app-key' => InputOption::VALUE_REQUIRED,
    'app-secret' => InputOption::VALUE_REQUIRED,
    'minimum-age' => '7d',
    'environment' => ['dev', 'stage', 'prod'],
    'dry-run' => FALSE,
  ]
  ): void {
    $minimumAge = $this->parseDuration((string) $options['minimum-age']);
    $acquiaApi = $this->getAcquiaApi();
    $appId = $this->input()->getOption('app-id');

    $application = $acquiaApi->acquiaApplications->get($appId);
    if ($application->hosting->type == 'acsf') {
      throw new CommandFailedException('ACSF Applications are not supported. Only single applications (ACE, ACN) are supported.');
    }

    // Allow --environment=dev,prod as well as repeating the option.
    $wanted = array_filter(array_map('trim', explode(',', implode(',', (array) $options['environment']))));
    $environments = [];
    foreach ($acquiaApi->acquiaEnvironments->getAll($appId) as $environment) {
      if (in_array($environment->name, $wanted, TRUE)) {
        $environments[$environment->uuid] = $environment->name;
      }
    }
    if ($missing = array_diff($wanted, $environments)) {
      $this->logger()->warning(sprintf('%s does not have these environments: %s', $application->name, implode(', ', $missing)));
    }
    if (!$environments) {
      throw new CommandFailedException('None of the requested environments exist.');
    }

    $databases = array_map(fn($database) => $database->name, (array) $acquiaApi->acquiaDatabases->getNames($appId)->getArrayCopy());
    $this->io()->writeln(sprintf('Checking %d database(s) on %s for on-demand backups older than %s...', count($databases), implode(', ', $environments), $this->formatAge($minimumAge)));

    $candidates = [];
    $kept = 0;
    foreach ($environments as $uuid => $environmentName) {
      foreach ($databases as $database) {
        try {
          $backups = $acquiaApi->acquiaDatabaseBackups->getAll($uuid, $database);
        }
        catch (\Throwable $e) {
          // Not every database exists on every environment.
          $this->logger()->warning(sprintf('Unable to list backups of %s on %s: %s', $database, $environmentName, $e->getMessage()));
          continue;
        }

        foreach ($backups as $backup) {
          $age = time() - strtotime($backup->startedAt);
          // Skip scheduled backups and ones that are still being created.
          if ($backup->type !== 'ondemand' || empty($backup->completedAt) || $age <= $minimumAge) {
            $kept += (int) ($backup->type === 'ondemand');
            continue;
          }
          $candidates[] = [
            'uuid' => $uuid,
            'environment' => $environmentName,
            'database' => $database,
            'id' => $backup->id,
            'created' => date('Y-m-d H:i', strtotime($backup->startedAt)),
            'age' => $age,
          ];
        }
      }
    }

    if (!$candidates) {
      $this->io()->success(sprintf('No on-demand backups are older than %s. %d newer on-demand backup(s) kept.', $this->formatAge($minimumAge), $kept));
      return;
    }

    // Group by environment and database, oldest first.
    usort($candidates, fn($a, $b) => [$a['environment'], $a['database'], $b['age']] <=> [$b['environment'], $b['database'], $a['age']]);
    $this->io()->table(
      ['Environment', 'Database', 'Backup', 'Created', 'Age'],
      array_map(fn($backup) => [$backup['environment'], $backup['database'], $backup['id'], $backup['created'], $this->formatAge($backup['age'])], $candidates),
    );

    if ($options['dry-run']) {
      $this->io()->note(sprintf('Dry run: %d backup(s) would be deleted and %d newer on-demand backup(s) kept.', count($candidates), $kept));
      return;
    }
    if (!$this->io()->confirm(sprintf('Delete these %d backup(s)?', count($candidates)))) {
      throw new UserAbortException();
    }

    $failed = [];
    $progress = $this->io()->createProgressBar(count($candidates));
    $progress->start();
    foreach ($candidates as $backup) {
      $label = "{$backup['database']} #{$backup['id']} on {$backup['environment']}";
      try {
        $acquiaApi->acquiaDatabaseBackups->delete($backup['uuid'], $backup['database'], $backup['id']);
      }
      catch (ApiErrorException $e) {
        // Deleted by someone else since it was listed.
        if (($e->getResponseBody()->error ?? NULL) !== 'not_found') {
          $failed[$label] = trim($e->getMessage());
        }
      }
      catch (\Throwable $e) {
        $failed[$label] = $e->getMessage();
      }
      $progress->advance();
    }
    $progress->finish();
    $this->io()->newLine(2);

    $deleted = count($candidates) - count($failed);
    if (!$failed) {
      $this->io()->success(sprintf('Deleted %d backup(s). %d newer on-demand backup(s) kept.', $deleted, $kept));
      return;
    }
    $this->io()->listing(array_map(fn($label, $error) => "$label: $error", array_keys($failed), $failed));
    throw new CommandFailedException(sprintf('Deleted %d backup(s), but %d could not be deleted.', $deleted, count($failed)));
  }

  /**
   * Convert "604800", "7d", "12h", "30m" or "45s" to seconds.
   */
  private function parseDuration(string $duration): int {
    if (!preg_match('/^\s*(\d+)\s*([dhms]?)\s*$/i', $duration, $matches)) {
      throw new CommandFailedException("Invalid duration \"$duration\". Use seconds, or a number followed by d, h, m or s.");
    }
    $multipliers = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
    return (int) $matches[1] * $multipliers[strtolower($matches[2])];
  }

  /**
   * Format seconds as a short age such as "9d 4h", "5h 12m" or "40m".
   */
  private function formatAge(int $seconds): string {
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days) {
      return $hours ? "{$days}d {$hours}h" : "{$days}d";
    }
    if ($hours) {
      return $minutes ? "{$hours}h {$minutes}m" : "{$hours}h";
    }
    return max(1, $minutes) . 'm';
  }

  /**
   * Delete git branches and tags that are not currently deployed on Acquia.
   */
  #[CLI\Command(name: 'sws:acquia:clean-git')]
  #[CLI\Option(name: 'app-id', description: 'Acquia application ID')]
  #[CLI\Option(name: 'app-key', description: 'Acquia API key')]
  #[CLI\Option(name: 'app-secret', description: 'Acquia API secret')]
  public function cleanUnusedBranchesAndTags(array $options = [
    'app-id' => InputOption::VALUE_REQUIRED,
    'app-key' => InputOption::VALUE_REQUIRED,
    'app-secret' => InputOption::VALUE_REQUIRED,
  ]
  ): void {
    $acquiaApi = $this->getAcquiaApi();
    $appId = $this->input()->getOption('app-id');

    $active_branches = ['master', 'HEAD'];
    $active_tags = [];

    /** @var \AcquiaCloudApi\Response\EnvironmentResponse $environment */
    foreach ($acquiaApi->acquiaEnvironments->getAll($appId) as $environment) {
      $git_url = $environment->vcs->url;

      $vcs = $environment->vcs->path;

      if (str_contains($vcs, 'tags/')) {
        $active_tags[] = str_replace('tags/', '', $vcs);
      }
      else {
        $active_branches[] = $vcs;
      }
    }

    $active_tags = array_unique($active_tags);
    $active_branches = array_unique($active_branches);

    $root = $this->getDir();
    if (file_exists("$root/deploy")) {
      $this->localMachineHelper()->execute([
        'git',
        'fetch',
      ], NULL, "$root/deploy");
    }
    else {
      $this->localMachineHelper()->execute([
        'git',
        'clone',
        $git_url,
        "$root/deploy",
      ], NULL, $this->getDir());
    }

    $result = $this->localMachineHelper()->execute([
      'git',
      'branch',
      '--remotes',
    ], NULL, "$root/deploy", FALSE);
    if (!$result->isSuccessful()) {
      throw new CommandFailedException('Failed getting branch names.', $result->getExitCode());
    }
    $branches = explode("\n", $result->getOutput());

    $remove_branches = [];
    foreach ($branches as $branch) {
      $branch = preg_replace('/ .*/', '', trim(str_replace('origin/', '', $branch)));

      if (!empty($active_branches) && !in_array($branch, $active_branches)) {
        $remove_branches[] = $branch;
      }
    }

    $result = $this->localMachineHelper()->execute([
      'git',
      'tag',
      '-l',
    ], NULL, "$root/deploy", FALSE);
    if (!$result->isSuccessful()) {
      throw new CommandFailedException('Failed getting tag names.', $result->getExitCode());
    }

    $tags = explode("\n", $result->getOutput());
    $remove_tags = [];
    foreach ($tags as $tag) {
      $tag = trim($tag);
      if (!empty($active_tags) && !in_array($tag, $active_tags)) {
        $remove_tags[] = $tag;
      }
    }

    $perform_branch_delete = TRUE;
    $perform_tag_delete = TRUE;
    if ($this->input()->isInteractive()) {
      $perform_branch_delete = $this->confirm(sprintf('Are you sure you wish to delete the following branches? %s', implode(', ', $remove_branches)));
      $perform_tag_delete = $this->confirm(sprintf('Are you sure you wish to delete the following tags? %s', implode(', ', $remove_tags)));
    }
    if ($perform_branch_delete) {
      foreach (array_filter($remove_branches) as $branch) {
        $result = $this->localMachineHelper()->execute([
          'git',
          'push',
          '-d',
          'origin',
          $branch,
        ], NULL, "$root/deploy");
        if (!$result->isSuccessful()) {
          throw new CommandFailedException('Failed Deleting branch ' . $branch, $result->getExitCode());
        }
      }
    }
    if ($perform_tag_delete) {
      foreach (array_filter($remove_tags) as $tag) {
        $result = $this->localMachineHelper()->execute([
          'git',
          'push',
          'origin',
          ":refs/tags/$tag",
        ], NULL, "$root/deploy");
        if (!$result->isSuccessful()) {
          throw new CommandFailedException('Failed Deleting tag ' . $tag, $result->getExitCode());
        }
      }
    }
  }

}
