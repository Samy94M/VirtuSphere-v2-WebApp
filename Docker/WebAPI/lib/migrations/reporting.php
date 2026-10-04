<?php

declare(strict_types=1);

function migrator_out(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $message . PHP_EOL);
        return;
    }
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "<br>\n";
}
function migrator_statement_count(mysqli_stmt $stmt, string $context): int
{
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if (!is_array($row) || !array_key_exists('c', $row)) {
        throw new RuntimeException('Migration check returned no count: ' . $context);
    }
    return (int) $row['c'];
}
function migrator_query_row(mysqli $db, string $sql, string $context): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Migration query did not return a result set: ' . $context);
    }

    $row = $result->fetch_assoc();
    $result->free();
    if (!is_array($row)) {
        throw new RuntimeException('Migration query returned no rows: ' . $context);
    }

    return $row;
}

