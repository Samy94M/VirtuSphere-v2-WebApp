<?php

declare(strict_types=1);

const VIRTUSPHERE_PERMISSIONS = [
    'missions.write',
    'vms.write',
    'deploy.run',
    'catalog.write',
    'credentials.manage',
    'system.config',
    'users.manage',
];

const VIRTUSPHERE_ROLE_ADMIN = 'admin';
const VIRTUSPHERE_ROLE_USER = 'user';

// Ordered for UI display (default role first), so this order intentionally
// differs from the role ENUM. The enum-sync check (ADR-0016) compares the value
// SET, not this array's order, against the ENUM('admin','user') in struktur.sql
// and lib/migrate.php; keep the two roles in sync, not their ordering.
const VIRTUSPHERE_ROLES = [
    VIRTUSPHERE_ROLE_USER,
    VIRTUSPHERE_ROLE_ADMIN,
];

const VIRTUSPHERE_ROLE_PERMISSIONS = [
    VIRTUSPHERE_ROLE_ADMIN => ['*'],
    VIRTUSPHERE_ROLE_USER => [
        'missions.write',
        'vms.write',
        'deploy.run',
    ],
];

function role_normalize(string $role): string
{
    return in_array($role, VIRTUSPHERE_ROLES, true) ? $role : VIRTUSPHERE_ROLE_USER;
}

function role_options(): array
{
    return VIRTUSPHERE_ROLES;
}

function permissions_for_role(string $role): array
{
    return VIRTUSPHERE_ROLE_PERMISSIONS[$role] ?? [];
}

function role_has_permission(string $role, string $permission): bool
{
    $permissions = permissions_for_role($role);

    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}
