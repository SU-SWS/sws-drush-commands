<?php

declare(strict_types=1);

namespace Drupal\SwsDrush\Helpers;

use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs commands on the local machine.
 *
 * Kept for the commands and project command files that use it. New code can
 * use Symfony Process and Filesystem directly.
 */
class LocalMachineHelper {

  use LoggerAwareTrait;

  protected InputInterface $input;

  protected OutputInterface $output;

  /**
   * Whether commands are attached to the terminal. Worked out on first use.
   */
  private ?bool $isTty = NULL;

  /**
   * Results of earlier executable lookups, keyed by name.
   *
   * @var bool[]
   */
  private array $installedBinaries = [];

  public function setInput(InputInterface $input): void {
    $this->input = $input;
  }

  public function setOutput(OutputInterface $output): void {
    $this->output = $output;
  }

  /**
   * Check if an executable is in the $PATH.
   *
   * Shell aliases and built-ins aren't found.
   */
  public function commandExists(string $command): bool {
    return $this->installedBinaries[$command] ??= (new ExecutableFinder())->find($command) !== NULL;
  }

  /**
   * Throw an exception if any of the executables aren't in the $PATH.
   *
   * @param string[] $binaries
   *   Executable names.
   */
  public function checkRequiredBinariesExist(array $binaries = []): void {
    foreach ($binaries as $binary) {
      if (!$this->commandExists($binary)) {
        throw new \RuntimeException("The required binary `$binary` does not exist. Install it and ensure it exists in a location listed in your system \$PATH");
      }
    }
  }

  /**
   * Run a command and wait for it to finish.
   *
   * @param string[]|string $cmd
   *   Command and arguments.
   * @param callable|null $callback
   *   Receives the output as it's produced. When not given and $printOutput
   *   is TRUE, the output is printed.
   * @param string|null $cwd
   *   Working directory.
   * @param bool|null $printOutput
   *   Print the output, attached to the terminal when possible.
   * @param float|null $timeout
   *   Timeout in seconds, or NULL for none.
   * @param array|null $env
   *   Environment variables.
   * @param bool $stdin
   *   Pass this process's piped input on to the command.
   */
  public function execute(array|string $cmd, ?callable $callback = NULL, ?string $cwd = NULL, ?bool $printOutput = TRUE, ?float $timeout = NULL, ?array $env = NULL, bool $stdin = TRUE): Process {
    $process = new Process(is_array($cmd) ? $cmd : [$cmd]);
    return $this->run($process, $callback, $cwd, $printOutput, $timeout, $env, $stdin);
  }

  /**
   * Run a shell command line and wait for it to finish.
   *
   * Use execute() when possible. The command line is not escaped, so only use
   * this for pipes and redirects, and escape any values with escapeshellarg().
   */
  public function executeFromCmd(string $cmd, ?callable $callback = NULL, ?string $cwd = NULL, ?bool $printOutput = TRUE, ?float $timeout = NULL, ?array $env = NULL): Process {
    return $this->run(Process::fromShellCommandline($cmd), $callback, $cwd, $printOutput, $timeout, $env);
  }

  /**
   * Get a filesystem object.
   */
  public function getFilesystem(): Filesystem {
    return new Filesystem();
  }

  /**
   * Configure, run and log a process.
   */
  private function run(Process $process, ?callable $callback, ?string $cwd, ?bool $printOutput, ?float $timeout, ?array $env, bool $stdin = TRUE): Process {
    if ($stdin && function_exists('posix_isatty') && !@posix_isatty(STDIN)) {
      $process->setInput(STDIN);
    }
    if ($cwd) {
      $process->setWorkingDirectory($cwd);
    }
    if ($printOutput) {
      $process->setTty($this->useTty());
    }
    if ($env) {
      $process->setEnv($env);
    }
    $process->setTimeout($timeout);

    if ($callback === NULL && $printOutput !== FALSE) {
      $callback = fn($type, $buffer) => $this->output->write($buffer);
    }
    $process->run($callback);

    $this->logger?->notice('Command: {command} [Exit: {exit}]', [
      'command' => $process->getCommandLine(),
      'exit' => $process->getExitCode(),
    ]);
    return $process;
  }

  /**
   * Whether to attach commands to the terminal.
   *
   * Only in an interactive session with both input and output on a terminal.
   */
  private function useTty(): bool {
    if ($this->isTty === NULL) {
      $this->isTty = $this->input->isInteractive()
        && function_exists('posix_isatty')
        && posix_isatty(STDOUT)
        && @posix_isatty(STDIN);
    }
    return $this->isTty;
  }

}
