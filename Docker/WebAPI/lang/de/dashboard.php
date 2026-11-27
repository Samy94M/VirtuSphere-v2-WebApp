<?php

declare(strict_types=1);

return [
    // Nur wenn etwas zu tun ist (Etappe 13R). Alle drei Achsen stehen im Satz,
    // weil 'arbeitet' und 'pausiert nach dem aktuellen Auftrag' gleichzeitig
    // gelten koennen und der Hinweis sonst die falsche Haelfte zeigt.
    'service_attention' => 'Bereitstellungsdienst: :parts.',
    'service_attention_link' => 'Bereitstellungsdienst im Systemstatus öffnen',
    'title' => 'Dashboard',
    'key_metrics' => 'Kennzahlen',
    'kpi_missions' => 'Missionen',
    'kpi_templates' => 'Vorlagen',
    'kpi_vms' => 'VMs in Missionen',
    'kpi_mecm_pending' => 'MECM ausstehend',
    'kpi_vm_attention' => 'VM-Prüfung fällig',
    'kpi_system_status' => 'MECM-Systemstatus',
    'kpi_integration' => 'Integration',
    'kpi_mecm_site' => 'MECM-{mecm:site}',
    'kpi_hypervisor' => 'Hypervisor',
    'kpi_active_deploys' => 'Aktive Bereitstellungen',
    'recent_missions' => 'Letzte Missionen',
    'all_missions' => 'Alle Missionen',
    'create_mission' => 'Mission anlegen',
    'empty' => 'Keine Missionen gefunden.',
    'backup_banner_title' => 'Backup-Hinweis:',
    'backup_banner_failed' => 'Das letzte Backup ist fehlgeschlagen. Prüfen Sie das Backup-Log und den Zeitplan (Cron oder systemd-Timer).',
    'backup_banner_stale' => 'Der erwartete Backup-Lauf ist ausgeblieben, auch die Kulanzzeit ist vorbei. Läuft der Zeitplan (Cron oder systemd-Timer) auf dem Host?',
    'backup_banner_disk_low' => 'Wenig freier Speicher auf dem Backup-Volume. Prüfen Sie die Aufbewahrung der Backups oder den Speicherplatz.',
    'backup_banner_unknown' => 'Kein Backup-Status verfügbar. Das Skript lief noch nie oder der Status-Mount fehlt.',
    'backup_banner_link' => 'Backup-Einstellungen öffnen',
];
