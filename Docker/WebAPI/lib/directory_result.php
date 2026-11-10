<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';

final class DirectoryLdapException extends RuntimeException
{
    /**
     * $bindReason is set only for a rejected user bind (N1a F5): a word from
     * VIRTUSPHERE_DIRECTORY_BIND_REASONS, never controller text.
     */
    public function __construct(
        public readonly string $outcome,
        public readonly bool $transportFailure = false,
        public readonly string $bindReason = ''
    ) {
        if (!in_array($outcome, VIRTUSPHERE_DIRECTORY_OUTCOMES, true)) {
            throw new InvalidArgumentException('unknown_directory_outcome');
        }
        if ($bindReason !== '' && !in_array($bindReason, VIRTUSPHERE_DIRECTORY_BIND_REASONS, true)) {
            throw new InvalidArgumentException('unknown_directory_bind_reason');
        }
        parent::__construct($outcome);
    }
}
