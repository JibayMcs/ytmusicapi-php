<?php

namespace Ytmusicapi;

class CaseInsensitiveDict
{
    private $data = [];

    public function __construct(array $input = [])
    {
        foreach ($input as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function set(string $key, $value): void
    {
        $lowerKey = $key;
        $this->data[$lowerKey] = $value;
    }

    public function get(string $key)
    {
        $lowerKey = strtolower($key);
        return $this->data[$lowerKey] ?? null;
    }

    public function has(string $key): bool
    {
        $lowerKey = strtolower($key);
        return array_key_exists($lowerKey, $this->data);
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function remove(string $key): void
    {
        $lowerKey = strtolower($key);
        unset($this->data[$lowerKey]);
    }
}
