<?php

declare(strict_types=1);

class Logger
{
    public function __construct(private readonly string $logFile)
    {
        $directory = dirname($logFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => date(DATE_ATOM),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ];

        file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public function info(string $message, array $context = []): void { $this->log('INFO', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('ERROR', $message, $context); }
}
