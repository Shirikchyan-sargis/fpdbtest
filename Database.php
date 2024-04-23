<?php

namespace FpDbTest;

use mysqli;

class Database implements DatabaseInterface
{
    private readonly mysqli $mysqli;
    private const SKIP = '___SKIP___';

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $index = 0;
        $query = preg_replace_callback('/\?\??[df#a]?|\(\?a\)/', function ($matches) use (&$args, &$index) {
            $token = $matches[0];
            $value = $args[$index++] ?? null;

            if ($value === self::SKIP) {
                return $value;
            }

            return match ($token) {
                '?d' => is_null($value) ? 'NULL' : intval($value),
                '?f' => is_null($value) ? 'NULL' : floatval($value),
                '(?a)' => $this->formatArray($value),
                '?a' => $this->formatAssociativeArray($value),
                '?#' => $this->formatIdentifiers($value),
                default => $this->escapeValue($value)
            };
        }, $query);

        // Обработка условных блоков
        $query = preg_replace_callback('/\{[^{}]*\}/', function ($matches) {
            if (str_contains($matches[0], self::SKIP)) {
                return '';
            }
            return substr($matches[0], 1, -1);
        }, $query);

        return $query;
    }

    private function formatAssociativeArray(array $values): string
    {
        $result = [];
        foreach ($values as $key => $value) {
            $escapedKey = "`" . $this->mysqli->real_escape_string($key) . "`";
            $escapedValue = $this->escapeValue($value);
            $result[] = "$escapedKey = $escapedValue";
        }
        return implode(', ', $result);
    }

    private function formatArray(array $values): string
    {
        return '(' . implode(', ', array_map([$this, 'escapeValue'], $values)) . ')';
    }

    private function formatIdentifiers($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => "`" . $this->mysqli->real_escape_string($item) . "`", $value));
        }
        return "`" . $this->mysqli->real_escape_string($value) . "`";
    }

    private function escapeValue($value): string
    {
        return match (true) {
            is_null($value) => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string)$value,
            default => "'" . $this->mysqli->real_escape_string((string)$value) . "'"
        };
    }

    public function skip(): string
    {
        return self::SKIP;
    }
}
