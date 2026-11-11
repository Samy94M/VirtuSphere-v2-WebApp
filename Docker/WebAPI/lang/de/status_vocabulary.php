<?php

declare(strict_types=1);

// Das eine Statusvokabular (lib/status_vocabulary.php): je Ton ein festes Wort,
// Farbe und Icon kommen aus derselben Tabelle. Fachlicher Zustand, Melderzustand
// und Laufzustand stehen getrennt; legend_* erklärt jeden Ton für die Legende.
return [
    'ok' => 'OK',
    'warning' => 'Warnung',
    'limited' => 'Eingeschränkt',
    'critical' => 'Kritisch',
    'unknown' => 'Unbekannt',
    'current' => 'Aktuell',
    'stale' => 'Veraltet',
    'no_data' => 'Keine Daten',
    'unreachable' => 'Nicht erreichbar',
    'disabled' => 'Deaktiviert',
    'running' => 'Läuft',
    'evidence_incomplete' => 'Keine Treffer, Nachweis unvollständig',
    'legend_heading' => 'Statuswörter',
    'legend_intro' => 'Jedes Statuswort hat auf allen Seiten dieselbe Farbe und dasselbe Symbol. Der fachliche Zustand sagt, ob ein Bereich in Ordnung ist; der Melderzustand sagt, ob seine Nachweise noch ankommen. Die Kopfzeile über dem Überblick nennt die Bereiche, die Aufmerksamkeit brauchen, den schwersten zuerst.',
    'axis_level' => 'Fachlicher Zustand',
    'axis_reporter' => 'Melderzustand',
    'axis_activity' => 'Laufzustand',
    'legend_ok' => 'Der letzte Nachweis ist aktuell und ohne Befund.',
    'legend_warning' => 'Etwas braucht bald Ihre Aufmerksamkeit, etwa ein überfälliger Lauf, offene Punkte oder ein Zertifikat kurz vor Ablauf.',
    'legend_limited' => 'Es arbeitet, aber nicht vollständig: ein Teil fehlt oder ist gestört.',
    'legend_critical' => 'Es ist gestört und braucht einen Eingriff, bevor es wieder arbeitet.',
    'legend_unknown' => 'Es gibt Daten, aber sie reichen für keine Aussage, etwa bei einem unvollständigen Nachweis.',
    'legend_current' => 'Der Dienst meldet sich im erwarteten Abstand.',
    'legend_stale' => 'Der letzte Nachweis ist zu alt, um noch etwas über heute zu sagen. Ein Fehler ist nicht bekannt.',
    'legend_no_data' => 'Es kam noch nie eine Meldung, ein Abruf oder ein Test an.',
    'legend_unreachable' => 'Der Dienst meldet sich nicht mehr.',
    'legend_disabled' => 'Die Funktion ist abgeschaltet und wird nicht geprüft.',
    'legend_running' => 'Ein Lauf oder Auftrag ist gerade unterwegs.',
    'legend_evidence_incomplete' => 'Der Vergleich fand nichts, deckte aber nicht alle Objektarten mit aktuellen Daten ab.',
];
