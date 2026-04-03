<?php
/**
 * FileLogger – PSR-3-konformer Logger, der Einträge in eine Datei schreibt.
 *
 * Nutzt Psr\Log\AbstractLogger als Basis, sodass alle LogLevel-Methoden
 * (debug, info, notice, warning, error, critical, alert, emergency) automatisch
 * auf log() delegiert werden.
 */

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class FileLogger extends AbstractLogger
{
    /** @var string Absoluter Pfad zur Log-Datei */
    private string $logFile;

    /**
     * @param string $logFile Absoluter Pfad zur Log-Datei (Verzeichnis muss existieren)
     */
    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * Schreibt einen Log-Eintrag in die Datei.
     *
     * {@inheritdoc}
     *
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $interpolated = $this->interpolate((string) $message, $context);
        $line = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $interpolated
        );

        $result = file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            // Fallback: PHP's system error log wenn die Datei nicht schreibbar ist
            error_log(rtrim($line));
        }
    }

    /**
     * Ersetzt {placeholder}-Marker durch Werte aus dem Kontext-Array.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replace);
    }
}
