<?php

function writeLog(string $eventType, string $description, ?string $referenceId = null): void
{
    $config = require __DIR__ . '/config.php';

    $logFile = $config['logging']['file'];
    $timestamp = date('Y-m-d H:i:s');

    $line = sprintf(
        "[%s] [%s] [%s] %s%s",
        $timestamp,
        $eventType,
        $referenceId ?? '-',
        $description,
        PHP_EOL
    );

    file_put_contents($logFile, $line, FILE_APPEND);
}
