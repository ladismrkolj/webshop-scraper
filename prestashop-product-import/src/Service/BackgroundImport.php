<?php

namespace ProductImport\Service;

use ProductImport\Repository\ImportRunRepository;

class BackgroundImport
{
    public static function selectionArgument($selection): string
    {
        if ($selection === 'all') {
            return 'all';
        }
        if (!is_int($selection) || $selection <= 0) {
            throw new \InvalidArgumentException('Selection must be all or a positive source ID.');
        }
        return (string) $selection;
    }

    public static function buildCommand(string $php, string $script, $selection, string $logFile, bool $nice = false): string
    {
        $argument = self::selectionArgument($selection);
        return 'nohup ' . ($nice ? 'nice -n 10 ' : '') . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($argument) . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
    }

    public function start($selection): array
    {
        self::selectionArgument($selection);
        $php = PHP_BINDIR . '/php';
        $script = dirname(__DIR__, 2) . '/bin/import.php';
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (PHP_OS_FAMILY === 'Windows' || !is_executable($php) || !function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new BackgroundImportUnsupportedException('Background process spawning is unavailable.');
        }
        if (ImportLauncher::isRunning()) {
            throw new ImportLockBusyException('Import already running or lock unavailable');
        }
        $baseline = (new ImportRunRepository())->maxId();
        $log = _PS_CACHE_DIR_ . 'productimport-run-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.log';
        $nice = is_executable('/usr/bin/nice') || is_executable('/bin/nice');
        $command = self::buildCommand($php, $script, $selection, $log, $nice);
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new BackgroundImportUnsupportedException('Unable to start background import.');
        }
        return ['started' => true, 'baseline_run_id' => $baseline];
    }
}
