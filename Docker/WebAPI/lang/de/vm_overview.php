<?php

declare(strict_types=1);

return [
    'title_all' => 'VMs in Missionen',
    'title_mecm_pending' => 'MECM ausstehend',
    'intro_all' => 'Alle VMs in echten Missionen. Vorlagen zählen hier nicht mit.',
    'intro_mecm_pending' => 'VMs, die auf eine Übernahme durch MECM warten: für MECM vorgemerkt oder mit ausstehendem MECM-Status. Vorlagen gehören zu dieser Zählung und sind gekennzeichnet.',
    'filter_label' => 'Ansicht wählen',
    'filter_all' => 'VMs in Missionen',
    'filter_mecm_pending' => 'MECM ausstehend',
    // Die vier Gruppen „VM-Prüfung fällig“ des Dashboards (SP4, F6); Gruppe,
    // Filter und Liste tragen denselben Namen.
    'filter_mecm_overdue' => 'MECM wartet länger als :hours h',
    'filter_os_install_overdue' => 'OS-Installation länger als :hours h',
    'filter_job_failed' => 'Auftrag fehlgeschlagen',
    'filter_create_uncertain' => 'Create ungeklärt',
    'intro_mecm_overdue' => 'VMs in echten Missionen, die länger als :hours h auf die Übernahme durch MECM warten. Vorlagen zählen nicht mit.',
    'intro_os_install_overdue' => 'VMs in echten Missionen, deren OS-Installation länger als :hours h ohne Abschluss beobachtet wird. Vorlagen zählen nicht mit.',
    'intro_job_failed' => 'VMs in echten Missionen, deren letzter Auftrag fehlgeschlagen ist oder deren Ausgang unbekannt ist. Der Befund jeder Zeile sagt, was zutrifft. Vorlagen zählen nicht mit.',
    'intro_create_uncertain' => 'VMs in echten Missionen mit einer Create-Einheit, deren Ergebnis auf dem Host unbekannt ist. Vorlagen zählen nicht mit.',
    'empty_mecm_overdue' => 'Zurzeit wartet keine VM überfällig auf MECM.',
    'empty_os_install_overdue' => 'Zurzeit ist keine OS-Installation überfällig.',
    'empty_job_failed' => 'Zurzeit hat keine VM einen fehlgeschlagenen Auftrag oder einen Auftrag mit unbekanntem Ausgang.',
    'empty_create_uncertain' => 'Zurzeit ist kein Create ungeklärt.',
    'template_badge' => 'Vorlage',
    'open_vm' => 'VM öffnen',
    'hits' => 'Treffer: :count',
    'count_changed' => 'Seit der Zählung um :counted hat sich die Anzahl von :expected auf :actual geändert; die Liste zeigt den aktuellen Stand.',
    'empty_all' => 'Keine VM in einer echten Mission gefunden.',
    'empty_mecm_pending' => 'Zurzeit wartet keine VM auf eine MECM-Übernahme.',
    'back_dashboard' => 'Zurück zum Dashboard',
    'back_to_list' => 'Zurück zu „:list“',
    'pagination_label' => 'Seiten der Liste',
    'previous' => 'Vorherige Seite',
    'next' => 'Nächste Seite',
    'page_info' => 'Seite :current von :last',
];
