<?php

declare(strict_types=1);

/** Structured destination; presentation translates and checks its permission. */
interface PortalActionableError
{
    /** @return array{url:string,label_key:string,permission:string}|null */
    public function portalAction(): ?array;
}
