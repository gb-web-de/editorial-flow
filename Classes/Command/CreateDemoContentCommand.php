<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Command;

use GbWeb\EditorialFlow\Service\DemoEnvironmentSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Makes sure there is something to run the board against.
 *
 * Pages and content are NOT created here: theme_camino ships a full demo site in
 * Initialisation/data.xml (Camino, FAQs, Packing List, Route Comparison, plus
 * images), which TYPO3 imports during `typo3 setup` when TYPO3_SETUP_DISTRIBUTION
 * is set. This command only verifies that import happened.
 *
 * What Camino cannot provide is workspaces with custom review stages, the
 * uneven permission spread between real editorial roles, and the acceptance
 * criteria a stage asks for. Without those, most of what this extension does is
 * invisible: one workspace shows a single stage chain, and a board that merges
 * several workspaces into one set of columns has nothing to merge.
 *
 * Three workspaces, because each one makes a different thing visible:
 *
 *   Editorial - the full chain, Review then Approval, with criteria on Review.
 *   Marketing - its own chain whose first stage is ALSO called "Review", which
 *               is what BoardColumnRegistry merges into one column (it groups by
 *               resolved title, not by uid), plus a "Legal" stage nothing else
 *               has. One user is a member of both, so cross-workspace conflicts
 *               have someone who can actually see both sides.
 *   Quickfix  - no custom stages at all: Editing straight to "Ready to publish",
 *               the path an installation without a review process takes.
 *
 * The permission spread is core's, not ours. Core's gate is
 * TYPO3\CMS\Workspaces\Hook\DataHandlerHook::version_setStage(), which calls
 * BackendUserAuthentication::workspaceCheckStageForCurrent($currentStage) - the
 * stage a record is LEAVING, not the one it is entering. Being responsible for a
 * stage therefore means "may decide what happens to whatever currently sits
 * there", including sending it straight past the next stage. Every member may
 * always move a record out of the default Editing stage; only a workspace OWNER
 * may act on "Ready to publish"/"Publish" or publish at all. These users exist to
 * make that model visible on the board, not to route around it. See
 * WORKSPACE-STAGES.md.
 *
 * Re-running is safe: existing data is kept unless the user explicitly says
 * otherwise. Recreating deletes a workspace and therefore any versions inside it,
 * so it is never done implicitly - `--force`, or an interactive confirmation.
 */
#[AsCommand(
    name: 'editorialflow:democontent',
    description: 'Ensure demo content, workspaces with review stages, and demo backend users exist for the board.',
)]
final class CreateDemoContentCommand extends Command
{
    private const GROUP_TITLE = 'Editorial Flow Editors';
    private const GROUP_MODULES = 'web_editorialflow,web_layout,file_list';
    private const DEMO_PASSWORD = 'Password.1';

    /**
     * The demo workspaces, in board order.
     *
     * `responsible` maps a stage title to the users core will let act on records
     * sitting in it. `criteria` seeds that stage's acceptance criteria, which the
     * "Send to stage" dialog then asks about.
     *
     * @var array<string, array{description: string, stages: list<string>, owners: list<string>, members: list<string>, responsible: array<string, list<string>>, criteria: array<string, list<string>>}>
     */
    private const WORKSPACES = [
        'Editorial' => [
            'description' => 'The main editorial pipeline: draft, review, approval.',
            'stages' => ['Review', 'Approval'],
            'owners' => ['approver'],
            'members' => ['editor', 'reviewer', 'both', 'stagelead'],
            'responsible' => [
                // `both` is responsible for a Review stage HERE and in Marketing:
                // reviewing across workspaces is the normal case for a small
                // editorial team, and it is the one the merged "Review" column is
                // for - the same person acts on cards from either workspace
                // without leaving the column they are looking at.
                'Review' => ['editor', 'stagelead', 'both'],
                'Approval' => ['reviewer', 'stagelead'],
            ],
            'criteria' => [
                'Review' => [
                    'All links checked',
                    'Images have alt text',
                    'Spelling and grammar checked',
                ],
                'Approval' => [
                    'Facts confirmed with the department',
                    'Publication date agreed',
                ],
            ],
        ],
        'Marketing' => [
            'description' => 'Campaign pages. Shares the "Review" step with Editorial and adds a legal check.',
            // "Review" on purpose: BoardColumnRegistry merges stages by resolved
            // title, so this one shares a single board column with Editorial's.
            'stages' => ['Review', 'Legal'],
            'owners' => ['marketing'],
            'members' => ['editor2', 'both', 'legal'],
            'responsible' => [
                'Review' => ['editor2', 'both'],
                'Legal' => ['legal'],
            ],
            'criteria' => [
                'Legal' => [
                    'Claims are substantiated',
                    'Imprint and privacy links present',
                ],
            ],
        ],
        'Quickfix' => [
            'description' => 'Typos and small corrections. No review stages - Editing straight to publish.',
            'stages' => [],
            'owners' => ['approver'],
            'members' => ['editor'],
            'responsible' => [],
            'criteria' => [],
        ],
    ];

    /**
     * username => [real name, what this user is here to make visible].
     *
     * Nine, because the interesting cases are the ones a single-workspace demo
     * cannot produce: someone who reviews in two workspaces at once, someone
     * responsible for two stages of the same one, an owner who is responsible for
     * no stage, and someone with the module but no workspace at all.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DEMO_USERS = [
        'editor' => ['Erin Editor', 'Editorial + Quickfix member, responsible for Editorial\'s Review'],
        'reviewer' => ['Rae Reviewer', 'Editorial member, responsible for Approval - cannot publish'],
        'approver' => ['Ana Approver', 'Owner of Editorial and Quickfix: the only role that may publish'],
        'editor2' => ['Eli Editor', 'Marketing member, responsible for Marketing\'s Review'],
        'both' => ['Bo Both', 'Reviews in BOTH Editorial and Marketing - and sees both sides of a conflict'],
        'legal' => ['Lex Legal', 'Marketing member, responsible for Legal only'],
        'marketing' => ['Mo Marketing', 'Owner of Marketing, responsible for no stage of it'],
        'stagelead' => ['Sam Stagelead', 'Responsible for BOTH Editorial stages at once'],
        'observer' => ['Obi Observer', 'Has the module and page access, but no workspace at all'],
    ];

    public function __construct(
        private readonly DemoEnvironmentSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Recreate the demo workspaces even if they exist. Deletes them and any versions inside them.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        $this->reportPageContent($io);

        $recreate = $this->shouldRecreate($input, $io);

        $workspaceUids = [];
        $stageUids = [];
        foreach (self::WORKSPACES as $title => $definition) {
            $workspaceUid = $this->ensureWorkspace($io, $title, $definition, $recreate);
            if ($workspaceUid === 0) {
                $io->error(sprintf('Could not create the demo workspace "%s".', $title));
                return Command::FAILURE;
            }

            $workspaceUids[$title] = $workspaceUid;
            $stageUids[$title] = $this->seeder->ensureStages($workspaceUid, $definition['stages']);
            $this->seedCriteria($io, $title, $workspaceUid, $stageUids[$title], $definition['criteria']);
        }

        $userUids = $this->ensureDemoUsers($workspaceUids);
        $this->wireMemberships($workspaceUids, $stageUids, $userUids);
        $this->reportUsers($io, $userUids);

        $io->writeln('Switch into a workspace, edit a demo page, and a task appears on the board.');

        return Command::SUCCESS;
    }

    /**
     * Decide whether to replace existing demo data.
     *
     * When there is no TTY - which is the case for DDEV post-start hooks -
     * Symfony Console falls back to the default, so the answer is "keep".
     * Destroying an editor's workspace must never be the outcome of a
     * non-interactive run.
     */
    private function shouldRecreate(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($input->getOption('force')) {
            return true;
        }

        $existing = array_filter(
            array_keys(self::WORKSPACES),
            fn (string $title): bool => $this->seeder->findWorkspace($title) > 0,
        );
        if ($existing === []) {
            return false;
        }

        if (!$input->isInteractive()) {
            $io->writeln(sprintf('Workspaces already present - keeping them: %s.', implode(', ', $existing)));
            $io->writeln('  To recreate: <info>ddev editorialflow-demo</info> (asks) or add <info>--force</info>.');

            return false;
        }

        $io->warning(sprintf(
            'These demo workspaces already exist: %s. Recreating deletes them and every version inside them.',
            implode(', ', $existing),
        ));

        return $io->confirm('Recreate the demo workspaces?', false);
    }

    /**
     * @param array{description: string, stages: list<string>, owners: list<string>, members: list<string>, responsible: array<string, list<string>>, criteria: array<string, list<string>>} $definition
     */
    private function ensureWorkspace(SymfonyStyle $io, string $title, array $definition, bool $recreate): int
    {
        $existing = $this->seeder->findWorkspace($title);
        if ($existing > 0 && !$recreate) {
            $io->writeln(sprintf('Kept workspace "%s" (uid %d).', $title, $existing));

            return $existing;
        }
        if ($existing > 0) {
            $this->seeder->deleteWorkspace($existing);
            $io->note(sprintf('Deleted workspace "%s" (uid %d).', $title, $existing));
        }

        $workspaceUid = $this->seeder->createWorkspace($title, $definition['description']);
        if ($workspaceUid > 0) {
            $io->success(sprintf(
                'Created workspace "%s" (uid %d)%s.',
                $title,
                $workspaceUid,
                $definition['stages'] === []
                    ? ' without custom stages'
                    : ' with stages: ' . implode(', ', $definition['stages']),
            ));
        }

        return $workspaceUid;
    }

    /**
     * @param array<string, int> $stageUids
     * @param array<string, list<string>> $criteria
     */
    private function seedCriteria(SymfonyStyle $io, string $workspaceTitle, int $workspaceUid, array $stageUids, array $criteria): void
    {
        foreach ($criteria as $stageTitle => $titles) {
            $stageUid = $stageUids[$stageTitle] ?? 0;
            if ($stageUid === 0) {
                continue;
            }

            $added = $this->seeder->ensureCriteria($workspaceUid, $stageUid, $titles);
            if ($added > 0) {
                $io->writeln(sprintf(
                    '  %s / %s: %d acceptance criteria.',
                    $workspaceTitle,
                    $stageTitle,
                    $added,
                ));
            }
        }
    }

    /**
     * @param array<string, int> $workspaceUids
     * @return array<string, int> username => uid
     */
    private function ensureDemoUsers(array $workspaceUids): array
    {
        $groupUid = $this->seeder->ensureGroup(self::GROUP_TITLE, self::GROUP_MODULES);
        $this->seeder->grantPageAccessToGroup($groupUid);

        $userUids = [];
        foreach (self::DEMO_USERS as $username => [$realName]) {
            $userUids[$username] = $this->seeder->ensureUser(
                $username,
                $realName,
                $groupUid,
                $this->primaryWorkspaceOf($username, $workspaceUids),
                self::DEMO_PASSWORD,
            );
        }

        return $userUids;
    }

    /**
     * The workspace a user lands in on login: the first one they belong to at
     * all. `observer` belongs to none and stays in Live, which is the case worth
     * having in the demo - the board has to hold up for someone who cannot enter
     * a workspace.
     *
     * @param array<string, int> $workspaceUids
     */
    private function primaryWorkspaceOf(string $username, array $workspaceUids): int
    {
        foreach (self::WORKSPACES as $title => $definition) {
            if (in_array($username, $definition['owners'], true) || in_array($username, $definition['members'], true)) {
                return $workspaceUids[$title] ?? 0;
            }
        }

        return 0;
    }

    /**
     * @param array<string, int> $workspaceUids
     * @param array<string, array<string, int>> $stageUids
     * @param array<string, int> $userUids
     */
    private function wireMemberships(array $workspaceUids, array $stageUids, array $userUids): void
    {
        foreach (self::WORKSPACES as $title => $definition) {
            $workspaceUid = $workspaceUids[$title] ?? 0;
            if ($workspaceUid === 0) {
                continue;
            }

            $this->seeder->setWorkspaceMembers(
                $workspaceUid,
                $this->uidsOf($definition['members'], $userUids),
                $this->uidsOf($definition['owners'], $userUids),
            );

            foreach ($definition['responsible'] as $stageTitle => $usernames) {
                $stageUid = $stageUids[$title][$stageTitle] ?? 0;
                if ($stageUid > 0) {
                    $this->seeder->setStageResponsible($stageUid, $this->uidsOf($usernames, $userUids));
                }
            }
        }
    }

    /**
     * @param list<string> $usernames
     * @param array<string, int> $userUids
     * @return list<int>
     */
    private function uidsOf(array $usernames, array $userUids): array
    {
        return array_values(array_filter(array_map(
            static fn (string $username): int => $userUids[$username] ?? 0,
            $usernames,
        )));
    }

    /**
     * The pages come from the Camino distribution, not from here - so this only
     * checks and reports, it never creates pages.
     */
    private function reportPageContent(SymfonyStyle $io): void
    {
        $pageCount = $this->seeder->countPages();
        if ($pageCount === 0) {
            $io->warning(
                'No pages found. The Camino demo site is imported by "typo3 setup" via '
                . 'TYPO3_SETUP_DISTRIBUTION=theme_camino and requires typo3/cms-impexp.',
            );

            return;
        }

        $io->writeln(sprintf('Found %d page(s) - demo content is present.', $pageCount));
    }

    /**
     * @param array<string, int> $userUids
     */
    private function reportUsers(SymfonyStyle $io, array $userUids): void
    {
        $io->section('Demo backend users');
        $io->writeln('  Password for all of them: ' . self::DEMO_PASSWORD);
        foreach (self::DEMO_USERS as $username => [$realName, $purpose]) {
            $io->writeln(sprintf('  - %-10s uid %-4d %s - %s', $username, $userUids[$username] ?? 0, $realName, $purpose));
        }
    }
}
