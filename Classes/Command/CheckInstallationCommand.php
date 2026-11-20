<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Command;

use GbWeb\EditorialFlow\Setup\Finding;
use GbWeb\EditorialFlow\Setup\InstallationCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * "Why is the board empty?" as a command.
 *
 * Exits non-zero when it finds an error, so a deployment can run it after
 * `editorialflow:setup` and stop on a broken configuration rather than finding
 * out from an editor.
 */
#[AsCommand(
    name: 'editorialflow:check',
    description: 'Check that groups, workspaces and stages are set up so the Editorial Flow board works.',
)]
final class CheckInstallationCommand extends Command
{
    private const ICONS = [
        Finding::OK => '<info>✓</info>',
        Finding::INFO => '<comment>i</comment>',
        Finding::WARNING => '<comment>!</comment>',
        Finding::ERROR => '<error>✗</error>',
    ];

    public function __construct(
        private readonly InstallationCheck $check,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Repair what has exactly one right answer (the workspace stage counter).')
            ->addOption('fix-live-editing', null, InputOption::VALUE_NONE, 'Take "may edit Live" away from every non-admin user who can open the board, so all their changes go through a workspace.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        if ($input->getOption('fix')) {
            $repaired = $this->check->fixStageCounters();
            $io->writeln(sprintf('Repaired the stage counter of %d workspace(s).', $repaired));
        }
        if ($input->getOption('fix-live-editing')) {
            $changed = $this->check->disallowLiveEditingForBoardUsers();
            $io->writeln(sprintf('%d user(s) now edit in workspaces only%s', count($changed), $changed === [] ? '.' : ': ' . implode(', ', $changed) . '.'));
        }

        $findings = $this->check->run();
        foreach ($findings as $finding) {
            $io->writeln(sprintf('  %s %s', self::ICONS[$finding->severity] ?? ' ', $finding->message));
            if ($finding->hint !== '' && $finding->severity !== Finding::OK) {
                $io->writeln('      → ' . $finding->hint);
            }
        }
        $io->newLine();

        $errors = array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Finding::ERROR);
        $warnings = array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Finding::WARNING);
        if ($errors !== []) {
            $io->error(sprintf('%d error(s), %d warning(s).', count($errors), count($warnings)));

            return Command::FAILURE;
        }
        if ($warnings !== []) {
            $io->warning(sprintf('%d warning(s), no errors.', count($warnings)));

            return Command::SUCCESS;
        }
        $io->success('The board is ready to use.');

        return Command::SUCCESS;
    }
}
