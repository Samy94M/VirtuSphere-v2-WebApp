<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_actionable_error.php';
require_once __DIR__ . '/deploy_urls.php';

final class DeployMissionBusyException extends RuntimeException implements PortalActionableError
{
    public function __construct(string $message, private readonly int $jobId)
    {
        parent::__construct($message);
    }

    public function portalAction(): array
    {
        return [
            'url' => deploy_job_log_url($this->jobId),
            'label_key' => 'deploy.flash_open_job_log',
            'permission' => 'deploy.run',
        ];
    }
}
