<?php

declare(strict_types=1);

/**
 * The one shell-quoting primitive both remote-command domains use.
 *
 * It is its own module rather than a copy in each, because a second quoting
 * rule is a command-injection difference nobody would notice while both files
 * still look correct: everything the worker interpolates into a remote shell -
 * directory names, playbook names, correlation ids, marker lines - goes
 * through exactly this function.
 */
function ansible_sh_quote(string $value): string
{
    return "'" . str_replace("'", "'\''", $value) . "'";
}
