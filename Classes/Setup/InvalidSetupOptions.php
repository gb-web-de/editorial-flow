<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * Thrown before anything is written, with a message meant for the person at
 * the command line - so it names the option and what to type instead.
 */
final class InvalidSetupOptions extends \InvalidArgumentException
{
}
