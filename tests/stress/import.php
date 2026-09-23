<?php
/**
 * Stress: an export file read back in, at the size JsonSource allows.
 * FOLDERFOLIO_STRESS=rig wp --user=admin eval-file tests/stress/import.php <folders> <assignments> [mode]
 * mode: measure (validate, store, read back) | store (stop after storing) | run (and import it)
 * Adds real attachment rows by SQL up to <assignments>. Results of 23 Sep: claude/progress-2026-09-23f-the-stress-tests.md
 */
use FolderFolio\Modules\Import\{JsonSource, Runner, Catalog, SourceTree, Provenance};

// It TRUNCATES the folder tables. Never on a site anybody uses.
if (getenv('FOLDERFOLIO_STRESS') !== 'rig') {
    WP_CLI::error('This empties every FolderFolio table. Run it on a throwaway site with FOLDERFOLIO_STRESS=rig.');
}

global $wpdb;
$N = (int) ($args[0] ?? 2000);
$A = (int) ($args[1] ?? 10000);
$mode = $args[2] ?? 'measure';
mt_srand(42);
// Rig only: start from an empty library of folders every time.
foreach (['folderfolio_attachment_folders', 'folderfolio_folder_meta', 'folderfolio_folders'] as $table) {
    $wpdb->query("TRUNCATE {$wpdb->prefix}$table");
}
delete_option('folderfolio_import_run');
delete_option(JsonSource::OPTION);

$ms = fn (float $t) => round((microtime(true) - $t) * 1000, 1);
$mb = fn (int $b) => round($b / 1048576, 2);

// Real attachments, so a same-site file's assignments apply: SQL, not wp_insert_post.
$have = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'");
if ($have < $A) {
    $t = microtime(true);
    $rows = [];
    for ($i = $have; $i < $A; $i++) {
        $rows[] = "(1, NOW(), NOW(), '', 'stress-$i', '', 'inherit', 'stress-$i', 'attachment', 'image/png', '', '', '')";
        if (count($rows) === 2000 || $i === $A - 1) {
            $wpdb->query("INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_type, post_mime_type, to_ping, pinged, post_content_filtered) VALUES " . implode(',', $rows));
            $rows = [];
        }
    }
    WP_CLI::log("attachments inserted to $A in " . $ms($t) . 'ms');
}
$ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' ORDER BY ID LIMIT $A"));

// The document, in FolderExport's shape.
$folders = [];
$depth = [];
for ($i = 1; $i <= $N; $i++) {
    $parent = null;
    if ($i > 50 && mt_rand(1, 10) > 1) {
        $p = mt_rand(1, $i - 1);
        if ($depth[$p] < 5) { $parent = $p; }
    }
    $depth[$i] = $parent === null ? 0 : $depth[$parent] + 1;
    $folders[] = ['id' => $i, 'parent_id' => $parent, 'name' => "Stress $i", 'slug' => "stress-$i",
        'color' => $i % 7 === 0 ? 'red' : null, 'icon' => null, 'sort_order' => $i % 13,
        'sort_folders' => $i % 11 === 0 ? 'name-desc' : null, 'sort_files' => null];
}
$by = [];
foreach ($ids as $k => $id) { $by[($k % $N) + 1][] = $id; }
$pairs = [];
foreach ($by as $folder => $list) { $pairs[] = ['folder' => $folder, 'attachments' => $list]; }
$doc = ['folderfolio' => 1, 'object_type' => 'attachment', 'site' => home_url(), 'exported' => gmdate('c'),
    'folders' => $folders, 'assignments' => $pairs];

$json = wp_json_encode($doc);
WP_CLI::log("N=$N A=$A  json " . $mb(strlen($json)) . 'MB  serialized ' . $mb(strlen(serialize($doc))) . 'MB');

$t = microtime(true); $m0 = memory_get_usage();
$decoded = json_decode($json, true);
WP_CLI::log('json_decode ' . $ms($t) . 'ms');
$t = microtime(true);
$src = JsonSource::fromDocument($decoded);
WP_CLI::log('fromDocument ' . $ms($t) . 'ms  ' . (is_wp_error($src) ? 'ERROR ' . $src->get_error_message() : 'ok ' . $src->folderCount() . ' folders, ' . $src->assignmentCount() . ' assignments'));
WP_CLI::log('peak ' . $mb(memory_get_peak_usage()) . 'MB');

$t = microtime(true);
JsonSource::store($decoded);
WP_CLI::log('store ' . $ms($t) . 'ms  ' . ($wpdb->last_error ?: 'no db error'));
$len = (int) $wpdb->get_var($wpdb->prepare("SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name=%s", JsonSource::OPTION));
WP_CLI::log('stored option ' . $mb($len) . 'MB; max_allowed_packet ' . $mb((int) $wpdb->get_var('SELECT @@max_allowed_packet')) . 'MB');
wp_cache_flush();
$t = microtime(true);
$again = JsonSource::stored();
WP_CLI::log('stored() (get_option + unserialize + validate) ' . $ms($t) . 'ms  ' . ($again ? 'ok' : 'NULL'));
$t = microtime(true);
$tree = SourceTree::of($again->folders());
WP_CLI::log('SourceTree::of ' . $ms($t) . 'ms, ' . count($tree->entries) . ' entries');

if ($mode === 'store') { return; }
if ($mode !== 'run') { return; }

$runner = new Runner();
$run = $runner->start($again);
if (is_wp_error($run)) { WP_CLI::error($run->get_error_message()); }
$t = microtime(true); $steps = 0; $slow = []; $marks = [];
while (true) {
    wp_cache_flush();
    $s = microtime(true);
    $run = $runner->step();
    $d = (microtime(true) - $s) * 1000;
    $steps++;
    if (in_array($steps, [1, 10, 100, 200, 400, 600, 799], true)) {
        wp_cache_flush();
        $p = microtime(true); $st = JsonSource::stored(); $pr = $ms($p);
        $p = microtime(true); SourceTree::of($st->folders()); $pt = $ms($p);
        $p = microtime(true); $map = (new Provenance())->mapFor($st->key()); $pm = $ms($p);
        $marks[] = "#$steps " . round($d) . "ms (stored $pr, tree $pt, mapFor $pm/" . count($map) . ')';
    }
    if (is_wp_error($run)) { WP_CLI::error($run->get_error_message()); }
    if ($run->isFinished()) { break; }
}
WP_CLI::log("run: $steps steps in " . round(microtime(true) - $t, 1) . 's; ' . implode(', ', $marks));
WP_CLI::log("created {$run->foldersCreated}, files {$run->filesAdded}, skipped {$run->skippedTotal}, error " . ($run->error ?: 'none'));
WP_CLI::log('option after finish: ' . (get_option(JsonSource::OPTION, null) === null ? 'gone' : 'STILL THERE'));
WP_CLI::log('peak ' . $mb(memory_get_peak_usage()) . 'MB');
