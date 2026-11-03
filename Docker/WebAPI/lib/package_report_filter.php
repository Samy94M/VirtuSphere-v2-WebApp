<?php

declare(strict_types=1);

require_once __DIR__ . '/log_filter.php';

/** @return array{scope:string,project:string,version:string,name:string,state:string,error_category:string,child_exit:?int,wrapper_exit:?int,exclude_vm:?int,from:string,to:string,from_utc:?string,to_utc:?string,before_at:?string,before_id:?int,errors:array<string,string>} */
function package_report_filter(array $input): array
{
    $errors = [];
    $read = static function (string $key) use ($input, &$errors): string {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            $errors[$key] = 'package_report.invalid_filter';
            return '';
        }
        return trim($value);
    };
    $project = $read('project');
    $version = $read('version');
    $scope = $read('scope') ?: 'all';
    $name = $read('name');
    $state = $read('state') ?: 'all';
    $errorCategory = $read('error_category');
    $childExitText = $read('child_exit');
    $wrapperExitText = $read('wrapper_exit');
    $excludeVmText = $read('exclude_vm');
    $from = $read('from');
    $to = $read('to');
    $beforeAt = $read('before_at');
    $beforeId = $read('before_id');

    foreach (['project' => $project, 'version' => $version, 'name' => $name,
        'error_category' => $errorCategory] as $key => $value) {
        $limit = $key === 'name' ? 100 : 255;
        if (mb_strlen($value) > $limit || strlen($value) > $limit * 4) {
            $errors[$key] = 'package_report.filter_too_long';
        }
    }
    $parseCode = static function (string $value, string $key) use (&$errors): ?int {
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > 11 || filter_var($value, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]) === false) {
            $errors[$key] = 'package_report.invalid_filter';
            return null;
        }
        return (int) $value;
    };
    $childExit = $parseCode($childExitText, 'child_exit');
    $wrapperExit = $parseCode($wrapperExitText, 'wrapper_exit');
    $excludeVm = null;
    if ($excludeVmText !== '') {
        if (!ctype_digit($excludeVmText) || strlen($excludeVmText) > 19
            || filter_var($excludeVmText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['exclude_vm'] = 'package_report.invalid_filter';
        } else {
            $excludeVm = (int) $excludeVmText;
        }
    }
    if (!in_array($state, ['all', 'error', 'no_completion'], true)) {
        $errors['state'] = 'package_report.invalid_filter';
    }
    if (!in_array($scope, ['all', 'package'], true)) {
        $errors['scope'] = 'package_report.invalid_filter';
    }
    if (($project === '') !== ($version === '')) {
        $errors['project'] = 'package_report.package_pair';
    }
    if ($scope === 'package' && $project === '') {
        $errors['project'] = 'package_report.package_pair';
    }
    if ($errorCategory === '' && $childExit !== null) {
        $errors['child_exit'] = 'package_report.category_required';
    }
    $dateErrors = [];
    $dates = log_filter_local_range($from, $to, $dateErrors);
    $errors += $dateErrors;

    $cursorAt = null;
    $cursorId = null;
    if ($beforeAt !== '' || $beforeId !== '') {
        $parsedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $beforeAt, new DateTimeZone('UTC'));
        if ($parsedAt === false || $parsedAt->format('Y-m-d H:i:s.u') !== $beforeAt
            || !ctype_digit($beforeId) || (int) $beforeId < 1) {
            $errors['before_at'] = 'package_report.invalid_filter';
        } else {
            $cursorAt = $beforeAt;
            $cursorId = (int) $beforeId;
        }
    }

    return [
        'scope' => $scope, 'project' => $project, 'version' => $version, 'name' => $name,
        'state' => $state, 'error_category' => $errorCategory,
        'child_exit' => $childExit, 'wrapper_exit' => $wrapperExit, 'exclude_vm' => $excludeVm,
        'from' => $from, 'to' => $to,
        'from_utc' => $dates['from_utc'], 'to_utc' => $dates['to_utc'],
        'before_at' => $cursorAt, 'before_id' => $cursorId, 'errors' => $errors,
    ];
}

/** @param array<string,mixed> $filter */
function package_report_filter_query(array $filter, array $overrides = []): string
{
    $parts = [];
    foreach (['scope', 'project', 'version', 'name', 'state', 'error_category', 'child_exit',
        'wrapper_exit', 'exclude_vm', 'from', 'to', 'before_at', 'before_id'] as $key) {
        $value = array_key_exists($key, $overrides) ? $overrides[$key] : ($filter[$key] ?? '');
        if ($value !== '' && $value !== null && !in_array($key . ':' . $value, ['state:all', 'scope:all'], true)) {
            $parts[$key] = $value;
        }
    }
    return http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
}
