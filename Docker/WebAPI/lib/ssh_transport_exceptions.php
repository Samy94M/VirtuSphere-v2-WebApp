<?php

declare(strict_types=1);

/** A VirtuSphere-owned SSH or SFTP time budget expired. */
final class SshTransportBudgetExceeded extends RuntimeException
{
}

/** The remote SFTP subsystem or an SFTP operation failed. */
final class SftpTransportFailed extends RuntimeException
{
}

/** A local prerequisite for the SSH/SFTP transport is missing or invalid. */
final class SshTransportConfigurationException extends RuntimeException
{
}
