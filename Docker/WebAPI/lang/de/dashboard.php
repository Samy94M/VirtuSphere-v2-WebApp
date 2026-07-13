<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'key_metrics' => 'Kennzahlen',
    'kpi_missions' => 'Missionen',
    'kpi_templates' => 'Vorlagen',
    'kpi_vms' => 'VMs',
    'kpi_mecm_pending' => 'MECM ausstehend',
    'kpi_integrations' => 'Integrations-Status',
    'kpi_hypervisor' => 'Hypervisor',
    'kpi_active_deploys' => 'Aktive Deploys',
    'recent_missions' => 'Letzte Missionen',
    'all_missions' => 'Alle Missionen',
    'create_mission' => 'Mission erstellen',
    'empty' => 'Keine Missionen gefunden.',
    'backup_banner_title' => 'Backup-Hinweis:',
    'backup_banner_failed' => 'Das letzte Backup ist fehlgeschlagen. Bitte Backup-Log und Cron-Job prüfen.',
    'backup_banner_stale' => 'Der erwartete Backup-Lauf ist ausgeblieben, auch die Kulanzzeit ist vorbei. Läuft der Cron-Job auf dem Host?',
    'backup_banner_disk_low' => 'Wenig freier Speicher auf dem Backup-Volume. Retention oder Speicherplatz prüfen.',
    'backup_banner_unknown' => 'Kein Backup-Status verfügbar. Das Skript lief noch nie oder der Status-Mount fehlt.',
    'backup_banner_link' => 'Zur Backup-Karte',
];
