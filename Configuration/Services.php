<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

/**
 * The one service that cannot be registered unconditionally.
 *
 * GbWeb\EditorialFlow\Reaction\TaskReaction implements ReactionInterface, which
 * lives in typo3/cms-reactions - a package this extension suggests rather than
 * requires (see composer.json for why: the webhook half needs nothing but
 * typo3/cms-core, and declaring both as hard requirements made every functional
 * test instance in this package refuse to build). Left in the Classes/* resource
 * of Services.yaml, Symfony would reflect on the class while compiling the
 * container, autoload it, and fail on the missing interface for everyone who
 * chose not to install the package.
 *
 * So: asked for at compile time, registered only if the answer is yes. TYPO3
 * loads this file before Services.yaml (Core's ContainerBuilder), and the
 * matching `exclude` there keeps the two from registering the same class twice.
 *
 * The `reactions.reaction` tag and its `getType` index method are EXT:reactions'
 * own contract, copied from its Configuration/Services.yaml rather than guessed.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    if (!interface_exists(ReactionInterface::class)) {
        return;
    }

    $container->services()
        ->set(Reaction\TaskReaction::class)
        ->class(Reaction\TaskReaction::class)
        ->autowire()
        ->tag('reactions.reaction');
};
