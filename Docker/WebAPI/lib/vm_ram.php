<?php

declare(strict_types=1);

require_once __DIR__ . '/defaults.php';

/** @return array{ok:bool,mb?:int,error?:string,rounded?:bool} */
function vm_ram_parse_input(mixed $raw, mixed $unit): array
{
    if (!is_string($unit) || !isset(VIRTUSPHERE_RAM_INPUT_FACTORS_MB[$unit])) {
        return ['ok' => false, 'error' => 'unit'];
    }
    if (!is_string($raw) && !is_int($raw)) {
        return ['ok' => false, 'error' => 'number'];
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'required'];
    }
    $pattern = $unit === 'mb' ? '/^[0-9]+$/D' : '/^[0-9]+(?:[.,][0-9]{1,10})?$/D';
    if (strlen($raw) > 16 || preg_match($pattern, $raw) !== 1) {
        return ['ok' => false, 'error' => $unit === 'mb' ? 'integer' : 'number'];
    }
    $parts = explode('.', str_replace(',', '.', $raw));
    $whole = (int) $parts[0];
    $fraction = $parts[1] ?? '';
    $factor = VIRTUSPHERE_RAM_INPUT_FACTORS_MB[$unit];
    $max = VIRTUSPHERE_VM_LIMITS['ram_mb_max'];
    if ($whole > intdiv($max, $factor)) {
        return ['ok' => false, 'error' => 'range'];
    }
    $denominator = 10 ** strlen($fraction);
    $numerator = ($whole * $denominator + (int) $fraction) * $factor;
    if ($numerator < VIRTUSPHERE_VM_LIMITS['ram_mb_min'] * $denominator || $numerator > $max * $denominator) {
        return ['ok' => false, 'error' => 'range'];
    }
    return ['ok' => true, 'mb' => intdiv($numerator + intdiv($denominator, 2), $denominator), 'rounded' => $numerator % $denominator !== 0];
}

/** @return array{value:string,unit:string,valid:bool} */
function vm_ram_display_state(mixed $stored): array
{
    $parsed = vm_ram_parse_input($stored, 'mb');
    $raw = is_scalar($stored) ? (string) $stored : '';
    if (!$parsed['ok']) {
        return ['value' => $raw, 'unit' => 'mb', 'valid' => false];
    }
    $mb = $parsed['mb'];
    $factor = VIRTUSPHERE_RAM_INPUT_FACTORS_MB['gb'];
    if ($mb >= $factor && ($mb * 1000) % $factor === 0) {
        return ['value' => rtrim(rtrim(number_format($mb / $factor, 3, '.', ''), '0'), '.'), 'unit' => 'gb', 'valid' => true];
    }
    return ['value' => (string) $mb, 'unit' => 'mb', 'valid' => true];
}

function vm_ram_format(mixed $stored): string
{
    $state = vm_ram_display_state($stored);
    return $state['value'] . ($state['valid'] ? ' ' . strtoupper($state['unit']) : '');
}
