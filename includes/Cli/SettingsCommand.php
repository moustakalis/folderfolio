<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_CLI\Utils;

/**
 * Read and change the site settings — the settings screen, for a fleet.
 *
 * Every value goes through the screen's own sanitiser. What it would quietly
 * replace — a typo'd sort, an undo window of 90 — is refused with the values
 * that are allowed, rather than saved as something else.
 *
 * The keys: count_mode, default_sort, startup_folder, undo_window,
 * post_types, and roles — each role's abilities.
 */
final class SettingsCommand
{
    use CommandHelpers;

    /**
     * Show the settings, or one of them.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : One setting, e.g. undo_window, or one role's abilities as roles.editor.
     *
     * [--format=<format>]
     * : Output format. json prints what settings set --values takes on another site.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio settings get
     *     wp folderfolio settings get roles.editor
     *     wp folderfolio settings get --format=json > folderfolio-settings.json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function get(array $args, array $assoc): void
    {
        $settings = \FolderFolio::getSettings();
        $format = $assoc['format'] ?? 'table';

        if (isset($args[0])) {
            $value = $this->at($settings, (string) $args[0]);

            if ($format === 'table') {
                WP_CLI::line($this->text($value));

                return;
            }

            WP_CLI::print_value($value, ['format' => $format]);

            return;
        }

        if ($format !== 'table') {
            WP_CLI::print_value($settings, ['format' => $format]);

            return;
        }

        $rows = [];

        foreach ($settings as $key => $value) {
            if ($key === 'roles' && is_array($value)) {
                foreach ($value as $role => $abilities) {
                    $rows[] = ['setting' => 'roles.' . $role, 'value' => $this->text($abilities)];
                }

                continue;
            }

            $rows[] = ['setting' => $key, 'value' => $this->text($value)];
        }

        Utils\format_items('table', $rows, ['setting', 'value']);
    }

    /**
     * Change one setting, or several from JSON. The rest are kept.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : The setting. roles.<role> sets that role's abilities.
     *
     * [<value>]
     * : Its value. A list is comma-separated, and an empty one is ''. For startup_folder, none, 0 (Unassigned) or a folder id.
     *
     * [--values=<json>]
     * : Several settings as a JSON object, e.g. from settings get --format=json on another site. (Not --json, which WP-CLI reads as --format=json.)
     *
     * ## EXAMPLES
     *
     *     wp folderfolio settings set undo_window 10
     *     wp folderfolio settings set default_sort custom
     *     wp folderfolio settings set post_types post,page,product
     *     wp folderfolio settings set roles.author create,assign,download
     *     wp folderfolio settings set --values="$(wp @source folderfolio settings get --format=json)"
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function set(array $args, array $assoc): void
    {
        if (isset($assoc['values'])) {
            $changes = json_decode((string) $assoc['values'], true);

            if (!is_array($changes) || array_is_list($changes)) {
                WP_CLI::error('--values is a JSON object of settings, e.g. {"undo_window": 10}.');
            }
        } elseif (isset($args[0], $args[1])) {
            $changes = $this->change((string) $args[0], (string) $args[1]);
        } else {
            WP_CLI::error('Give a setting and its value — settings set undo_window 10 — or --values.');
        }

        /** @var array<string, mixed> $changes */
        $saved = \FolderFolio::updateSettings($changes);

        $this->bailOnError($saved);

        WP_CLI::success(sprintf('Saved %s.', implode(', ', array_map(
            fn (string $key): string => $key === 'roles' && isset($args[0]) ? (string) $args[0] : $key,
            array_keys($changes)
        ))));
    }

    /**
     * `key value` as the array `updateSettings()` takes.
     *
     * @return array<string, mixed>
     */
    private function change(string $key, string $value): array
    {
        $list = static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => $s !== ''));

        if (str_starts_with($key, 'roles.')) {
            return ['roles' => [substr($key, 6) => $list($value)]];
        }

        return match ($key) {
            'post_types' => [$key => $list($value)],
            'startup_folder' => [$key => $this->orNone($value)],
            default => [$key => $value],
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function at(array $settings, string $key): mixed
    {
        if (str_starts_with($key, 'roles.')) {
            $role = substr($key, 6);

            if (!isset($settings['roles'][$role])) {
                WP_CLI::error(sprintf('No role called %s in the settings. The roles are %s.', $role, implode(', ', array_keys($settings['roles']))));
            }

            return $settings['roles'][$role];
        }

        if (!array_key_exists($key, $settings)) {
            WP_CLI::error(sprintf('There is no setting called %s. The settings are %s.', $key, implode(', ', array_keys($settings))));
        }

        return $settings[$key];
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return 'none';
        }

        if (is_array($value)) {
            return $value === [] ? '—' : (array_is_list($value) ? implode(', ', array_map('strval', $value)) : (string) wp_json_encode($value));
        }

        return (string) $value;
    }
}
