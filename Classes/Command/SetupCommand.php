<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Command;

use GbWeb\EditorialFlow\Setup\CommaList;
use GbWeb\EditorialFlow\Setup\InvalidSetupOptions;
use GbWeb\EditorialFlow\Setup\SetupContext;
use GbWeb\EditorialFlow\Setup\SetupOptions;
use GbWeb\EditorialFlow\Setup\SetupPlanner;
use GbWeb\EditorialFlow\Setup\SetupScenario;
use GbWeb\EditorialFlow\Setup\SetupStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Sets up groups, workspaces and stages for the board - for real
 * installations, where `editorialflow:democontent` is for trying it out.
 *
 * Without a scenario and with a terminal it is a wizard that asks; with a
 * scenario it runs unattended, for deployments and CI. Either way it prints
 * the plan first, and `--dry-run` stops right there.
 *
 * Nothing it does takes anything away: existing groups keep their modules,
 * workspaces keep their members, pages owned by a group keep their owner. See
 * InstallationRepository for why that is the line.
 */
#[AsCommand(
    name: 'editorialflow:setup',
    description: 'Set up editor groups, workspaces and review stages for the Editorial Flow board.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly SetupPlanner $planner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'scenario',
                InputArgument::OPTIONAL,
                'One of: ' . implode(', ', SetupScenario::values()) . '. Leave it out to be asked.',
            )
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Editor group title(s), comma-separated or repeated. Created if missing.')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace title. Defaults to the group title.')
            ->addOption('stages', null, InputOption::VALUE_REQUIRED, 'Review stages in order, comma-separated, or "none".', implode(',', SetupOptions::DEFAULT_STAGES))
            ->addOption('owner', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Backend username(s) that own the workspace and may publish.')
            ->addOption('owner-group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Group(s) that own the workspace and may publish. Created if missing.')
            ->addOption('attach-to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'existing-groups: groups (title or uid) that get board access.')
            ->addOption('attach-to-editors', null, InputOption::VALUE_NONE, 'existing-groups: every group that has the Page module gets board access.')
            ->addOption('editor', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Editor username(s) to create or add, "name" for the first group or "name:Group".')
            ->addOption('editor-password', null, InputOption::VALUE_REQUIRED, 'Password for new editors. Or set EDITORIALFLOW_EDITOR_PASSWORD.')
            ->addOption('site-base', null, InputOption::VALUE_REQUIRED, 'fresh: base URL of the site created for an empty page tree.', '/')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen and change nothing.')
            ->setHelp(<<<'HELP'
                Examples:

                  <info>%command.full_name%</info>
                      Asks which situation you are in, then what to call things.

                  <info>%command.full_name% fresh --editor=jane</info>
                      Empty database: root page, site, group "Editors", workspace with Review > Approval,
                      and an editor "jane" (password from EDITORIALFLOW_EDITOR_PASSWORD).

                  <info>%command.full_name% single-group --group="Redaktion" --owner=chefin</info>
                      One group under a name of your choice, one workspace; chefin may publish.

                  <info>%command.full_name% group-per-workspace --group="News,Sport,Kultur"</info>
                      Three groups, three workspaces of the same names.

                  <info>%command.full_name% existing-groups --attach-to-editors --dry-run</info>
                      Large existing group tree: only adds board access, shows the plan first.

                Run it again at any time - it only adds what is missing.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        try {
            $options = $this->resolveOptions($input, $io);
            $steps = $this->planner->plan($options);
        } catch (InvalidSetupOptions $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->section(sprintf('Plan: %s', $options->scenario->label()));
        $this->printPlan($io, $steps);

        $changes = array_filter($steps, static fn (SetupStep $step): bool => $step->changesSomething);
        if ($options->dryRun) {
            $io->note('Dry run - nothing was changed. Run the same command without --dry-run to apply it.');

            return Command::SUCCESS;
        }
        if ($changes === []) {
            $io->success('Everything is already in place.');

            return Command::SUCCESS;
        }
        if ($input->isInteractive() && !$io->confirm('Apply this plan?', true)) {
            $io->writeln('Nothing was changed.');

            return Command::SUCCESS;
        }

        $context = new SetupContext();
        foreach ($steps as $step) {
            $step->run($context);
        }
        foreach ($context->messages() as $message) {
            $io->writeln('  ' . $message);
        }

        $io->success('Done.');
        $this->printNextSteps($io, $context);

        return Command::SUCCESS;
    }

    private function resolveOptions(InputInterface $input, SymfonyStyle $io): SetupOptions
    {
        $scenarioValue = $input->getArgument('scenario');
        if (!is_string($scenarioValue) || $scenarioValue === '') {
            if (!$input->isInteractive()) {
                throw new InvalidSetupOptions('Name a scenario: ' . implode(', ', SetupScenario::values()) . '.');
            }

            return $this->askWizard($input, $io);
        }

        $scenario = SetupScenario::tryFrom($scenarioValue);
        if ($scenario === null) {
            throw new InvalidSetupOptions(sprintf(
                'Unknown scenario "%s". Choose one of: %s.',
                $scenarioValue,
                implode(', ', SetupScenario::values()),
            ));
        }

        return SetupOptions::fromArray($scenario, $this->optionValues($input));
    }

    /**
     * The interactive path. Asks only what the chosen scenario needs, with the
     * same defaults the options have, and ends up in SetupOptions::fromArray()
     * exactly like a scripted call - so there is one set of rules, not two.
     */
    private function askWizard(InputInterface $input, SymfonyStyle $io): SetupOptions
    {
        $io->title('Editorial Flow setup');
        $choices = [];
        foreach (SetupScenario::cases() as $case) {
            $choices[$case->value] = $case->label();
        }
        $scenario = SetupScenario::from((string)$io->choice('Which describes this installation?', $choices, SetupScenario::SingleGroup->value));

        $values = $this->optionValues($input);
        $values['group'] = match ($scenario) {
            SetupScenario::GroupPerWorkspace => (string)$io->ask('Group names, comma-separated (each gets its own workspace)', 'News,Sport'),
            SetupScenario::ExistingGroups => (string)$io->ask('Name of the small access group to add', SetupOptions::DEFAULT_ACCESS_GROUP),
            default => (string)$io->ask('Name of the editor group', SetupOptions::DEFAULT_GROUP),
        };
        if ($scenario === SetupScenario::ExistingGroups) {
            $values['attach-to-editors'] = $io->confirm('Give board access to every group that has the Page module?', true);
            if (!$values['attach-to-editors']) {
                $values['attach-to'] = (string)$io->ask('Which groups then? Titles or uids, comma-separated');
            }
            $values['workspace'] = (string)$io->ask('Workspace to create or reuse for them (leave empty to keep workspaces as they are)', '');
        } elseif ($scenario !== SetupScenario::GroupPerWorkspace) {
            $values['workspace'] = (string)$io->ask(
                'Name of the workspace',
                $values['workspace'] ?? CommaList::parse($values['group'])[0] ?? SetupOptions::DEFAULT_GROUP,
            );
        }
        $values['stages'] = (string)$io->ask('Review stages in order, comma-separated, or "none"', implode(',', SetupOptions::DEFAULT_STAGES));
        $values['owner'] = (string)$io->ask('Usernames that may publish, comma-separated (empty: only administrators)', '');
        if ($scenario !== SetupScenario::ExistingGroups) {
            $values['editor'] = (string)$io->ask('Create editors now? Usernames, comma-separated (empty: later)', '');
            if ($values['editor'] !== '' && $values['editor-password'] === null) {
                $values['editor-password'] = (string)$io->askHidden('Password for the new editors');
            }
        }

        return SetupOptions::fromArray($scenario, $values);
    }

    /**
     * @return array{group: list<string>, workspace: ?string, stages: ?string, owner: list<string>, owner-group: list<string>, attach-to: list<string>, attach-to-editors: bool, site-base: string, dry-run: bool, editor: list<string>, editor-password: ?string}
     */
    private function optionValues(InputInterface $input): array
    {
        $workspace = $input->getOption('workspace');
        $stages = $input->getOption('stages');

        return [
            'group' => $this->listOption($input, 'group'),
            'workspace' => is_string($workspace) ? $workspace : null,
            'stages' => is_string($stages) ? $stages : null,
            'owner' => $this->listOption($input, 'owner'),
            'owner-group' => $this->listOption($input, 'owner-group'),
            'attach-to' => $this->listOption($input, 'attach-to'),
            'attach-to-editors' => (bool)$input->getOption('attach-to-editors'),
            'site-base' => (string)$input->getOption('site-base'),
            'dry-run' => (bool)$input->getOption('dry-run'),
            'editor' => $this->listOption($input, 'editor'),
            'editor-password' => $this->editorPassword($input),
        ];
    }

    /**
     * From the option or the environment - the environment is what a CI job or
     * a deployment has, and it keeps the password out of the shell history.
     */
    private function editorPassword(InputInterface $input): ?string
    {
        $password = $input->getOption('editor-password');
        if (is_string($password) && $password !== '') {
            return $password;
        }
        $fromEnvironment = getenv('EDITORIALFLOW_EDITOR_PASSWORD');

        return is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(InputInterface $input, string $name): array
    {
        return array_values(array_map(strval(...), (array)$input->getOption($name)));
    }

    /**
     * @param list<SetupStep> $steps
     */
    private function printPlan(SymfonyStyle $io, array $steps): void
    {
        foreach ($steps as $step) {
            $io->writeln(sprintf('  %s %s', $step->changesSomething ? '<info>+</info>' : '<comment>=</comment>', $step->description));
        }
        $io->newLine();
    }

    private function printNextSteps(SymfonyStyle $io, SetupContext $context): void
    {
        $lines = [];
        foreach ($context->groups() as $title => $uid) {
            $lines[] = sprintf('More editors for "%s":  typo3 editorialflow:setup ... --editor=jane  (or backend:user:create --groups=%d, then editorialflow:check --fix-live-editing)', $title, $uid);
        }
        $lines[] = 'Check the result:  typo3 editorialflow:check';
        $io->section('Next');
        $io->listing($lines);
    }
}
