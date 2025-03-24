<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Drush\Commands;

use Drupal\SwsDrush\Helpers\AcquiaApi;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputOption;

/**
 * A Drush command file.
 */
final class AcquiaCleanupCommands extends DrushCommands {

  use SwsCommandsTrait;

  #[CLI\Command(name: 'acquia:clean-databases')]
  public function cleanOldDatabases() {}

  #[CLI\Command(name: 'acquia:clean-git')]
  #[CLI\Option(name: 'app-id', description: 'Acquia application ID')]
  #[CLI\Option(name: 'app-key', description: 'Acquia API key')]
  #[CLI\Option(name: 'app-secret', description: 'Acquia API secret')]
  public function cleanUnusedBranchesAndTags(array $options = [
    'app-id' => InputOption::VALUE_REQUIRED,
    'app-key' => InputOption::VALUE_REQUIRED,
    'app-secret' => InputOption::VALUE_REQUIRED,
  ]
  ) {
    $this->ensureOption('app-id', fn() => $this->io()
      ->ask('Acquia Application ID'), TRUE);
    $this->ensureOption('app-key', fn() => $this->io()
      ->ask('Acquia Application Key'), TRUE);
    $this->ensureOption('app-secret', fn() => $this->io()
      ->password('Acquia Application Secret'), TRUE);

    $appId = $this->input()->getOption('app-id');
    $appKey = $this->input()->getOption('app-key');
    $appSecret = $this->input()->getOption('app-secret');

    $acquiaApi = new AcquiaApi($appId, $appKey, $appSecret);

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
      throw new \Exception('Failed getting branch names.');
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
      throw new \Exception('Failed getting tag names.');
    }

    $tags = explode("\n", $result->getOutput());
    $remove_tags = [];
    foreach ($tags as $tag) {
      $tag = trim($tag);
      if (!empty($active_tags) && !in_array($tag, $active_tags)) {
        $remove_tags[] = $tag;
      }
    }

    $perform_branch_delete = $this->confirm(sprintf('Are you sure you wish to delete the following branches? %s', implode(', ', $remove_branches)));
    $perform_tag_delete = $this->confirm(sprintf('Are you sure you wish to delete the following tags? %s', implode(', ', $remove_tags)));
    if ($perform_branch_delete) {
      foreach ($remove_branches as $branch) {
        $this->localMachineHelper()->execute([
          'git',
          'push',
          '-d',
          'origin',
          $branch,
        ], NULL, "$root/deploy");
      }
    }
    if ($perform_tag_delete) {
      foreach ($remove_tags as $tag) {
        $this->localMachineHelper()->execute([
          'git',
          'push',
          'origin',
          ":res/tags/$tag",
        ], NULL, "$root/deploy");
      }
    }
  }

}
