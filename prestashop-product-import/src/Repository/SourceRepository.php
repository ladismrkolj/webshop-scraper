<?php

namespace ProductImport\Repository;

class SourceRepository
{
    private const STRING_COLUMNS = [
        'name', 'technical_key', 'json_url', 'json_file_path', 'identifier_field',
        'filter_expression', 'field_mapping',
    ];
    private const NULLABLE_COLUMNS = ['json_url', 'json_file_path', 'filter_expression'];

    public function findAll(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_source` ORDER BY `id_source`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list import sources.');
        }

        return $rows;
    }

    public function find(int $idSource): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'pi_source` WHERE `id_source` = ' . (int) $idSource);

        return $row ?: null;
    }

    public function findByTechnicalKey(string $key): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . "pi_source` WHERE `technical_key` = '" . pSQL($key) . "'");

        return $row ?: null;
    }

    public function create(array $data): int
    {
        foreach (['name', 'technical_key', 'identifier_field', 'field_mapping'] as $required) {
            if (!isset($data[$required])) {
                throw new \InvalidArgumentException('Missing source property: ' . $required);
            }
        }
        $data += ['active' => 1];
        $assignments = $this->assignments($data);
        $now = pSQL(date('Y-m-d H:i:s'));
        $assignments[] = "`date_add` = '" . $now . "'";
        $assignments[] = "`date_upd` = '" . $now . "'";
        if (!\Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'pi_source` SET ' . implode(', ', $assignments))) {
            throw new \RuntimeException('Unable to create import source.');
        }

        return (int) \Db::getInstance()->Insert_ID();
    }

    public function update(int $idSource, array $data): bool
    {
        $assignments = $this->assignments($data);
        $assignments[] = "`date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "'";

        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_source` SET ' . implode(', ', $assignments) . ' WHERE `id_source` = ' . (int) $idSource
        );
    }

    public function delete(int $idSource): bool
    {
        return (bool) \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_source` WHERE `id_source` = ' . (int) $idSource);
    }

    private function assignments(array $data): array
    {
        $assignments = [];
        foreach (self::STRING_COLUMNS as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            if ($data[$column] === null && in_array($column, self::NULLABLE_COLUMNS, true)) {
                $assignments[] = '`' . $column . '` = NULL';
                continue;
            }
            if (!is_string($data[$column])) {
                throw new \InvalidArgumentException('Source property must be a string: ' . $column);
            }
            // Preserve expression operators and JSON/HTML content while escaping SQL.
            $assignments[] = '`' . $column . "` = '" . pSQL($data[$column], true) . "'";
        }
        if (array_key_exists('active', $data)) {
            $assignments[] = '`active` = ' . (int) (bool) $data['active'];
        }

        return $assignments;
    }
}
