<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Webhook;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;
use GbWeb\EditorialFlow\Event\TaskClosedEvent;
use GbWeb\EditorialFlow\Event\TaskCreatedEvent;
use GbWeb\EditorialFlow\Event\TaskStageChangedEvent;
use GbWeb\EditorialFlow\Webhook\TaskClosedMessage;
use GbWeb\EditorialFlow\Webhook\TaskCreatedMessage;
use GbWeb\EditorialFlow\Webhook\TaskStageChangedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Webhooks\Message\WebhookMessageFactory;
use TYPO3\CMS\Webhooks\WebhookTypesRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What leaves TYPO3 for Jira, Trello or whatever sits on the other end.
 *
 * Two separate things are covered here, and neither implies the other:
 *
 * - The registration. There is no listener of ours anywhere: EXT:webhooks'
 *   compiler pass reads the single parameter of createFromEvent() by reflection
 *   and wires core's own MessageListener to that event class. That is entirely
 *   invisible in our source, so a rename of the parameter type would silently
 *   disconnect the webhook with nothing to see in a diff. Hence the assertions
 *   on the registry rather than on the attribute.
 * - The payload. It is a published contract - integrators map their side onto
 *   these keys - so the shape is pinned here rather than left to whatever the
 *   snapshot happens to serialize today.
 */
final class TaskWebhooksTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
        'typo3/cms-webhooks',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/editorial-flow',
    ];

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function webhookTypes(): array
    {
        return [
            'created' => ['editorial-flow/task-created', TaskCreatedEvent::class],
            'stage changed' => ['editorial-flow/task-stage-changed', TaskStageChangedEvent::class],
            'closed' => ['editorial-flow/task-closed', TaskClosedEvent::class],
        ];
    }

    #[Test]
    #[DataProvider('webhookTypes')]
    public function eachTypeIsRegisteredAndBoundToItsEvent(string $identifier, string $eventClass): void
    {
        $registry = $this->get(WebhookTypesRegistry::class);

        self::assertTrue($registry->hasWebhookType($identifier), $identifier . ' is not offered in the webhooks module.');
        self::assertSame($eventClass, $registry->getWebhookByType($identifier)->getConnectedEvent());
    }

    #[Test]
    public function anEventIsTurnedIntoItsMessageByCoresOwnFactory(): void
    {
        // Through the factory core's MessageListener uses, not by calling
        // createFromEvent() directly - that is the part the compiler pass wired
        // and the part that can break without any of our code changing.
        $message = $this->get(WebhookMessageFactory::class)->createMessageFromEvent(
            new TaskCreatedEvent($this->snapshot(), false, ['uid' => 1, 'username' => 'admin']),
        );

        self::assertInstanceOf(TaskCreatedMessage::class, $message);
    }

    #[Test]
    public function theCreatedPayloadCarriesEverythingAReceiverNeeds(): void
    {
        $payload = TaskCreatedMessage::createFromEvent(
            new TaskCreatedEvent($this->snapshot(), true, ['uid' => 1, 'username' => 'admin']),
        )->jsonSerialize();

        self::assertSame('task-created', $payload['event']);
        self::assertTrue($payload['autoCreated'], 'the difference between a planned task and one that opened itself');
        self::assertSame(['uid' => 1, 'username' => 'admin'], $payload['actor']);

        // Titles, not only uids: a Jira automation cannot look up
        // sys_workspace_stage.
        self::assertSame('Review', $payload['stage']['title']);
        self::assertSame('Editorial', $payload['workspace']['title']);
        self::assertSame('About us', $payload['subject']['title']);
        self::assertSame('/typo3/module/web/editorialflow?id=2', $payload['boardUrl']);
    }

    #[Test]
    public function theStageChangePayloadSaysWhereTheTaskCameFrom(): void
    {
        $payload = TaskStageChangedMessage::createFromEvent(new TaskStageChangedEvent(
            $this->snapshot(),
            'in_progress',
            0,
            'Editing',
            null,
        ))->jsonSerialize();

        self::assertSame('task-stage-changed', $payload['event']);
        // The snapshot only holds where it landed, so a receiver that wants
        // "left Editing" cannot reconstruct this half.
        self::assertSame(
            ['state' => 'in_progress', 'stageUid' => 0, 'stageTitle' => 'Editing'],
            $payload['from'],
        );
        self::assertSame('review', $payload['task']['state']);
        self::assertNull($payload['actor'], 'a transition nobody triggered is a real case');
    }

    #[Test]
    public function theClosePayloadSaysWhyItClosed(): void
    {
        $payload = TaskClosedMessage::createFromEvent(
            new TaskClosedEvent($this->snapshot(closed: true), 'published', null),
        )->jsonSerialize();

        self::assertSame('task-closed', $payload['event']);
        self::assertSame('published', $payload['reason']);
        self::assertTrue($payload['task']['closed']);
    }

    #[Test]
    public function anExternalReferenceTravelsBackOut(): void
    {
        $payload = TaskCreatedMessage::createFromEvent(
            new TaskCreatedEvent($this->snapshot(external: true), false, null),
        )->jsonSerialize();

        // This is what stops a round trip creating a duplicate on the other side.
        self::assertSame(
            ['system' => 'jira', 'id' => 'EDIT-42', 'url' => 'https://example.atlassian.net/browse/EDIT-42'],
            $payload['externalReference'],
        );
    }

    #[Test]
    public function aTaskWithNoExternalOriginSaysSoExplicitly(): void
    {
        $payload = TaskCreatedMessage::createFromEvent(
            new TaskCreatedEvent($this->snapshot(), false, null),
        )->jsonSerialize();

        // null rather than an absent key: a receiver checking "is this ours
        // already" should not have to tell a missing key from an empty one.
        self::assertArrayHasKey('externalReference', $payload);
        self::assertNull($payload['externalReference']);
    }

    private function snapshot(bool $closed = false, bool $external = false): TaskSnapshot
    {
        return new TaskSnapshot(
            uid: 7,
            title: 'Rewrite the About us page',
            description: 'Tone of voice pass.',
            state: 'review',
            priority: 2,
            closed: $closed,
            stageUid: 1,
            stageTitle: 'Review',
            workspaceUid: 1,
            workspaceTitle: 'Editorial',
            subjectTable: 'pages',
            subjectUid: 2,
            subjectTitle: 'About us',
            subjectPid: 2,
            assignee: ['uid' => 3, 'username' => 'editor', 'email' => 'editor@example.org'],
            externalReference: $external
                ? ['system' => 'jira', 'id' => 'EDIT-42', 'url' => 'https://example.atlassian.net/browse/EDIT-42']
                : null,
            boardUrl: '/typo3/module/web/editorialflow?id=2',
        );
    }
}
