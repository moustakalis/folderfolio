<?php
/**
 * Stress: tree(), a 1,000-sibling reorder, a large duplicate with files, and
 * the export with assignments.
 *   FOLDERFOLIO_STRESS=rig wp --user=admin eval-file tests/stress/ops.php
 * Needs 100,000 attachments — run import.php 2000 100000 measure first.
 */
use FolderFolio\Domain\{FolderService, FolderExport};

// It TRUNCATES the folder tables. Never on a site anybody uses.
if (getenv('FOLDERFOLIO_STRESS') !== 'rig') {
    WP_CLI::error('This empties every FolderFolio table. Run it on a throwaway site with FOLDERFOLIO_STRESS=rig.');
}

global $wpdb;
$p = $wpdb->prefix;
$ms = fn (float $t) => round((microtime(true) - $t) * 1000);
$mb = fn (int $b) => round($b / 1048576, 1);
$q = fn () => $wpdb->num_queries;
mt_srand(7);

foreach (['folderfolio_attachment_folders', 'folderfolio_folder_meta', 'folderfolio_folders'] as $t) {
    $wpdb->query("TRUNCATE {$p}$t");
}
$svc = new FolderService();

// Fixture: Big (1,000 children; the first 20 hold 50 each) + 3,000 others under 30 roots.
$t = microtime(true);
$big = $svc->create(['name' => 'Big'])->id;
$kids = [];
for ($i = 1; $i <= 1000; $i++) {
    $kids[] = $svc->create(['name' => "Kid $i", 'parent_id' => $big])->id;
}
$sub = [];
foreach (array_slice($kids, 0, 20) as $k => $kid) {
    for ($j = 1; $j <= 50; $j++) { $sub[] = $svc->create(['name' => "Grand $k-$j", 'parent_id' => $kid])->id; }
}
$others = [];
for ($r = 1; $r <= 30; $r++) {
    $root = $svc->create(['name' => "Root $r"])->id;
    for ($i = 1; $i <= 100; $i++) { $others[] = $svc->create(['name' => "Other $r-$i", 'parent_id' => $root])->id; }
}
$all = array_merge([$big], $kids, $sub, $others);
WP_CLI::log(count($all) . ' folders created in ' . round(microtime(true) - $t, 1) . 's');

// Files: 100,000 attachments, each in one folder; 30,000 in a second; 10,000 in a third.
// 20,000 of the first placements go into Big's subtree.
$ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' ORDER BY ID LIMIT 100000"));
$bigTree = array_merge([$big], $kids, $sub);
$rows = [];
$flush = function () use (&$rows, $wpdb, $p) {
    if ($rows) { $wpdb->query("INSERT IGNORE INTO {$p}folderfolio_attachment_folders (folder_id, attachment_id, sort_order, assigned_at) VALUES " . implode(',', $rows)); $rows = []; }
};
foreach ($ids as $n => $id) {
    $home = $n < 20000 ? $bigTree[$n % count($bigTree)] : $others[$n % count($others)];
    $rows[] = "($home, $id, 0, NOW())";
    if ($n < 30000) { $rows[] = '(' . $all[mt_rand(0, count($all) - 1)] . ", $id, 0, NOW())"; }
    if ($n < 10000) { $rows[] = '(' . $all[mt_rand(0, count($all) - 1)] . ", $id, 0, NOW())"; }
    if (count($rows) >= 3000) { $flush(); }
}
$flush();
WP_CLI::log('assignments: ' . $wpdb->get_var("SELECT COUNT(*) FROM {$p}folderfolio_attachment_folders") . ' rows for ' . count($ids) . ' files');

// 1. tree()
foreach (['inherited', 'direct', 'none'] as $mode) {
    wp_cache_flush(); $m0 = memory_get_peak_usage(); $q0 = $q(); $t = microtime(true);
    $tree = $svc->tree('attachment', $mode);
    WP_CLI::log("tree($mode): " . $ms($t) . 'ms, ' . ($q() - $q0) . ' queries, JSON ' . $mb(strlen(wp_json_encode($tree))) . 'MB');
}
$req = new WP_REST_Request('GET', '/folderfolio/v1/folders');
$t = microtime(true); $res = rest_do_request($req);
WP_CLI::log('GET /folders (site mode): ' . $ms($t) . 'ms, status ' . $res->get_status());

// 2. reorder 1,000 siblings, reversed
$t = microtime(true); $q0 = $q();
$r = $svc->reorder($big, array_reverse($kids));
WP_CLI::log('reorder 1,000 siblings: ' . $ms($t) . 'ms, ' . ($q() - $q0) . ' queries, ' . (is_wp_error($r) ? 'ERROR ' . $r->get_error_message() : "ok ($r)"));
$first = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}folderfolio_folders WHERE parent_id=%d ORDER BY sort_order, id LIMIT 1", $big));
WP_CLI::log('  first by sort_order is now ' . ($first === end($kids) ? 'the last kid — right' : "id $first — WRONG"));

// 3. duplicate Big with files
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}folderfolio_attachment_folders");
$t = microtime(true); $q0 = $q(); $m0 = memory_get_usage();
$copy = $svc->duplicate($big, null, true);
$d = $ms($t);
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}folderfolio_attachment_folders");
$inBig = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}folderfolio_attachment_folders WHERE folder_id IN (" . implode(',', $bigTree) . ')');
WP_CLI::log('duplicate Big (' . count($bigTree) . " folders) with files: {$d}ms, " . ($q() - $q0) . ' queries, +' . $mb(memory_get_usage() - $m0) . 'MB, ' . (is_wp_error($copy) ? 'ERROR ' . $copy->get_error_message() : "copy {$copy->name}"));
WP_CLI::log("  assignment rows +" . ($after - $before) . " (Big's subtree holds $inBig)");

// 4. export with assignments
$t = microtime(true);
$doc = (new FolderExport())->document(true);
$json = wp_json_encode($doc);
WP_CLI::log('export with assignments: ' . $ms($t) . 'ms, ' . count($doc['folders']) . ' folders, ' . $mb(strlen($json)) . 'MB JSON; peak ' . $mb(memory_get_peak_usage()) . 'MB');
