<?php

namespace ProductImport\Repository;

class ImportRunRepository
{
    public const LOG_LIMIT = 65536;

    public function start(int $idSource): int
    {
        if (!\Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'pi_import_run` (`id_source`, `started_at`, `status`) VALUES (' . (int) $idSource . ", NOW(), '" . pSQL('running') . "')")) {
            throw new \RuntimeException('Unable to start import run.');
        }
        return (int) \Db::getInstance()->Insert_ID();
    }

    public function finish(int $idRun, string $status, array $counts, string $errorLog): void
    {
        $sets = ["`status` = '" . pSQL($status) . "'", '`finished_at` = NOW()', "`error_log` = '" . pSQL(self::capLog($errorLog), true) . "'"];
        foreach (['created', 'updated', 'skipped', 'failed'] as $key) {
            $sets[] = '`' . $key . '_count` = ' . (int) max(0, $counts[$key] ?? 0);
        }
        if (!\Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'pi_import_run` SET ' . implode(', ', $sets) . ' WHERE `id_run` = ' . (int) $idRun)) {
            throw new \RuntimeException('Unable to finish import run.');
        }
    }

    public static function capLog(string $log): string
    {
        // Byte cap, preserving valid UTF-8 boundaries and making truncation explicit.
        if (strlen($log) <= self::LOG_LIMIT) {
            return $log;
        }
        return mb_strcut($log, 0, self::LOG_LIMIT - 16, 'UTF-8') . "\n[log truncated]";
    }

    public function findRecent(int $idSource, int $limit = 20): array
    {
        return $this->read(' WHERE r.`id_source` = ' . (int) $idSource, $limit);
    }

    public function findAll(int $limit = 100): array
    {
        return $this->read('', $limit);
    }

    private function read(string $where, int $limit): array
    {
        $rows = \Db::getInstance()->executeS('SELECT r.*, s.`name` AS `source_name` FROM `' . _DB_PREFIX_ . 'pi_import_run` r LEFT JOIN `' . _DB_PREFIX_ . 'pi_source` s ON s.`id_source` = r.`id_source`' . $where . ' ORDER BY r.`id_run` DESC LIMIT ' . (int) max(1, min(1000, $limit)));
        if ($rows === false) {
            throw new \RuntimeException('Unable to read import runs.');
        }
        return $rows;
    }
}
