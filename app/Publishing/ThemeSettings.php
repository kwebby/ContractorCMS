<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Contracts\RecordRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class ThemeSettings
{
    public function __construct(private RecordRepository $records) {}

    public function schema(array $schema): array
    {
        $this->ensure(($schema['type'] ?? 'object') === 'object' && is_array($schema['properties'] ?? []) && count($schema['properties'] ?? []) <= 30, 'Theme settings require a bounded object schema.');
        $this->ensure(! array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'title']), 'Unsupported theme schema keyword.');
        foreach ($schema['properties'] ?? [] as $key => $field) {
            $this->ensure(is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) && is_array($field), 'Invalid theme setting name.');
            $this->ensure(! array_diff(array_keys($field), ['type', 'title', 'description', 'default', 'enum', 'format', 'maxLength', 'minimum', 'maximum']), 'Unsupported theme field schema.');
            $this->ensure(in_array($field['type'] ?? null, ['string', 'boolean', 'integer'], true), 'Only string, boolean and integer theme settings are supported.');
            $this->ensure(! isset($field['format']) || $field['format'] === 'color', 'Only color format is supported.');
            foreach (['title', 'description'] as $text) {
                $this->ensure(! isset($field[$text]) || (is_string($field[$text]) && mb_strlen($field[$text]) <= 300), 'Invalid theme setting label.');
            }
            if (isset($field['enum'])) {
                $this->ensure(is_array($field['enum']) && count($field['enum']) <= 30 && ! array_filter($field['enum'], fn ($v) => ! is_string($v) || strlen($v) > 200), 'Enums must be bounded string choices.');
            }
        }
        $this->ensure(is_array($schema['required'] ?? []) && ! array_diff($schema['required'] ?? [], array_keys($schema['properties'] ?? [])), 'Required settings must exist in properties.');
        $normalized = ['type' => 'object', 'properties' => $schema['properties'] ?? [], 'required' => $schema['required'] ?? []];
        $defaults = [];
        foreach ($normalized['properties'] as $key => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            }
        }
        $this->values($normalized, $defaults);

        return $normalized;
    }

    public function get(string $id): array
    {
        $theme = $this->records->get('themes', $id);
        $schemaPath = storage_path('app/private/'.$theme['path'].'/settings.schema.json');
        $schema = $this->schema(is_file($schemaPath) ? json_decode(File::get($schemaPath), true, 32, JSON_THROW_ON_ERROR) : []);
        $row = $this->records->find('theme_settings', $id);
        $defaults = [];
        foreach ($schema['properties'] as $key => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            }
        }

        return ['schema' => $schema, 'values' => $this->values($schema, array_replace($defaults, $row['values'] ?? [])), 'version' => $row['version'] ?? 0];
    }

    public function update(string $id, array $values, int $expected): array
    {
        return $this->records->transaction(function () use ($id, $values, $expected) {
            $this->records->lock('publishing-slugs');
            $this->records->lock('theme-activation');
            $this->records->lock('theme-settings:'.$id);
            $current = $this->get($id);
            abort_unless($current['version'] === $expected, 409, 'Theme settings changed. Reload and try again.');
            $values = $this->values($current['schema'], $values);
            app(ThemeManager::class)->validateSettings($id, $values);
            $current['version'] ? $this->records->update('theme_settings', $id, ['values' => $values], $expected) : $this->records->create('theme_settings', ['values' => $values], $id);

            return $this->get($id);
        });
    }

    public function css(array $schema, array $values): string
    {
        $rules = [];
        foreach ($schema['properties'] as $key => $field) {
            if (($field['format'] ?? '') === 'color' && isset($values[$key])) {
                $rules[] = '--theme-'.str_replace('_', '-', $key).':'.$values[$key];
            }
        }

        return $rules ? ':root{'.implode(';', $rules).'}' : '';
    }

    private function values(array $schema, array $values): array
    {
        $this->ensure(! array_diff(array_keys($values), array_keys($schema['properties'])), 'Unknown theme setting.');
        foreach ($schema['required'] as $key) {
            $this->ensure(array_key_exists($key, $values), 'A required theme setting is missing.');
        }
        foreach ($values as $key => $value) {
            $field = $schema['properties'][$key];
            $valid = match ($field['type']) {
                'string' => is_string($value) && mb_strlen($value) <= min(1000, (int) ($field['maxLength'] ?? 500)),'boolean' => is_bool($value),'integer' => is_int($value) && $value >= max(-100000, (int) ($field['minimum'] ?? -100000)) && $value <= min(100000, (int) ($field['maximum'] ?? 100000))
            };
            $this->ensure($valid, 'Invalid value for '.$key.'.');
            if (($field['format'] ?? '') === 'color') {
                $this->ensure(is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value), 'Colors must be six-digit hex values.');
            }
            if (isset($field['enum'])) {
                $this->ensure(in_array($value, $field['enum'], true), 'Choose a supported value for '.$key.'.');
            }
        }

        return $values;
    }

    private function ensure(bool|int $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['settings' => $message]);
        }
    }
}
