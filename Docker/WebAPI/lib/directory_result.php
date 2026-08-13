<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';

final class DirectoryLdapException extends RuntimeException
{
    public function __construct(
        public readonly string $outcome,
        public readonly bool $transportFailure = false
    ) {
        if (!in_array($outcome, VIRTUSPHERE_DIRECTORY_OUTCOMES, true)) {
            throw new InvalidArgumentException('unknown_directory_outcome');
        }
        parent::__construct($outcome);
    }
}
