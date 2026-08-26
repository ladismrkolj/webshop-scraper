<?php
/**
 * Cron entry point.
 *
 * From the shell (what Hostinger's cron manager runs):
 *   /usr/bin/php /home/uXXX/domains/shop.tld/public_html/modules/csvproductsync/cron.php --token=TOKEN
 *   ... --id_source=3        only that source
 *   ... --dry-run            report what would change, change nothing
 *
 * Or over HTTP, for hosts that only offer a URL-based cron:
 *   https://shop.tld/modules/csvproductsync/cron.php?token=TOKEN
 */

$is_cli = PHP_SAPI === 'cli';

// A feed of tens of thousands of rows will outlive any default limit.
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.inc.php';
require_once __DIR__ . '/classes/autoload.php';

$options = csvsync_read_options($is_cli);

if (!hash_equals((string) Configuration::get('CSVSYNC_CRON_TOKEN'), (string) $options['token'])) {
    csvsync_out('Invalid or missing token.', $is_cli);
    if (!$is_cli) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit(1);
}

// Product creation and image handling both reach for the context, which a CLI
// request does not set up on its own.
if (!Context::getContext()->employee) {
    Context::getContext()->employee = new Employee(1);
}
if (!Validate::isLoadedObject(Context::getContext()->language)) {
    Context::getContext()->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
}

$sources = [];
if ($options['id_source']) {
    $source = new CsvSyncSource((int) $options['id_source']);
    if (!Validate::isLoadedObject($source)) {
        csvsync_out(sprintf('No source with id %d.', (int) $options['id_source']), $is_cli);
        exit(1);
    }
    $sources[] = $source;
} else {
    $sources = CsvSyncSource::getSources(true);
}

if (!$sources) {
    csvsync_out('No active source to import.', $is_cli);
    exit(0);
}

$exit_code = 0;
foreach ($sources as $source) {
    // Two overlapping runs of the same feed would fight over the same
    // products, and a nightly scrape plus a slow import makes that likely.
    if (CsvSyncRun::hasRunningRun((int) $source->id)) {
        csvsync_out(sprintf('[%s] skipped: an import is already running.', $source->name), $is_cli);
        continue;
    }

    csvsync_out(sprintf('[%s] starting%s', $source->name, $options['dry_run'] ? ' (dry run)' : ''), $is_cli);
    $started = microtime(true);

    $importer = new CsvSyncImporter($source);
    $importer->setDryRun($options['dry_run']);
    if ($is_cli && $options['verbose']) {
        $importer->setLogger(function ($message) use ($source, $is_cli) {
            csvsync_out(sprintf('[%s] %s', $source->name, $message), $is_cli);
        });
    }
    $run = $importer->run('cron');

    csvsync_out(sprintf(
        '[%s] %s in %.1fs — %d rows: %d created, %d updated, %d unchanged, %d removed, %d skipped',
        $source->name,
        Tools::strtoupper($run->status),
        microtime(true) - $started,
        (int) $run->rows_read,
        (int) $run->products_created,
        (int) $run->products_updated,
        (int) $run->products_unchanged,
        (int) $run->products_removed,
        (int) $run->rows_skipped
    ), $is_cli);

    if ($run->message) {
        csvsync_out(sprintf("[%s] notes:\n%s", $source->name, $run->message), $is_cli);
    }
    if ($run->status === CsvSyncRun::STATUS_ERROR) {
        $exit_code = 1;
    }
}

exit($exit_code);

/**
 * @return array token, id_source, dry_run, verbose
 */
function csvsync_read_options($is_cli)
{
    $options = ['token' => '', 'id_source' => 0, 'dry_run' => false, 'verbose' => true];

    if (!$is_cli) {
        return [
            'token' => (string) Tools::getValue('token'),
            'id_source' => (int) Tools::getValue('id_source'),
            'dry_run' => (bool) Tools::getValue('dry_run'),
            'verbose' => false,
        ];
    }

    $parsed = getopt('', ['token:', 'id_source:', 'dry-run', 'quiet']);
    $options['token'] = isset($parsed['token']) ? (string) $parsed['token'] : '';
    $options['id_source'] = isset($parsed['id_source']) ? (int) $parsed['id_source'] : 0;
    $options['dry_run'] = array_key_exists('dry-run', $parsed);
    $options['verbose'] = !array_key_exists('quiet', $parsed);

    return $options;
}

function csvsync_out($message, $is_cli)
{
    $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
    if ($is_cli) {
        fwrite(STDOUT, $line . PHP_EOL);

        return;
    }
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '<br>' . PHP_EOL;
}
