<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Config;

/** A config file that cannot be used (exit code 1). The message is the full error line. */
final class ConfigException extends \RuntimeException
{
}
