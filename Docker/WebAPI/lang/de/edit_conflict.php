<?php

declare(strict_types=1);

/**
 * Vergleich nach einem abgelehnten Speichern im Missions- und VM-Editor
 * (lib/edit_conflict_view.php, QL-01/QL-03 B).
 */
return [
    'heading' => 'Was sich seit Ihrem Öffnen geändert hat',
    'heading_continued' => 'Ihre Eingaben gegenüber dem gespeicherten Stand',
    'intro' => 'Jemand hat gespeichert, während Sie bearbeitet haben. Diese Felder unterscheiden sich zwischen Ihren Eingaben und dem jetzt gespeicherten Stand. Ihre Eingaben stehen weiter im Formular.',
    'intro_continued' => 'Ihre Eingaben beziehen sich jetzt auf den gespeicherten Stand. Diese Felder weichen davon ab; Speichern übernimmt die Werte aus der Spalte „Ihre Eingabe“. Speichert inzwischen erneut jemand, schlägt Ihr Speichern fehl und zeigt einen neuen Vergleich.',
    'no_field_difference' => 'Jemand hat gespeichert, während Sie bearbeitet haben. Die Werte dieses Formulars stimmen aber mit Ihren Eingaben überein.',
    'no_field_difference_continued' => 'Ihre Eingaben beziehen sich jetzt auf den gespeicherten Stand und stimmen mit ihm überein.',
    'th_field' => 'Feld',
    'th_mine' => 'Ihre Eingabe',
    'th_current' => 'Jetzt gespeichert',
    'empty' => 'leer',
    'row_count' => 'Anzahl: :count',
    'rows_hint' => 'Bei Schnittstellen, Datenträgern und Paketen nennt der Vergleich nur die Anzahl. Prüfen Sie die einzelnen Zeilen im Formular.',
    'continue' => 'Mit meinen Eingaben weiterarbeiten',
    'continue_hint' => '„Mit meinen Eingaben weiterarbeiten“ speichert nichts. Danach können Sie Ihre Eingaben anpassen; erst das nächste Speichern überschreibt den gezeigten Stand.',
];
