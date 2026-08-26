<?php
/**
 * Tests for the parts of the module that can run without a shop: the value
 * transforms and the CSV reader. These are where a scraped feed is most
 * likely to surprise the importer, so they are the parts worth pinning down.
 *
 * Run: php tests/run_tests.php
 */
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../classes/CsvSyncTransformer.php';
require_once __DIR__ . '/../classes/CsvSyncReader.php';

$failures = 0;
$checks = 0;

function check($label, $actual, $expected)
{
    global $failures, $checks;
    ++$checks;
    $same = is_float($expected) ? abs($actual - $expected) < 0.0001 : $actual === $expected;
    if ($same) {
        return;
    }
    ++$failures;
    printf(
        "FAIL %s\n  expected: %s\n  actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

// --- numbers, as the scraped shops write them ---------------------------
check('plain number', CsvSyncTransformer::toNumber('129.00'), 129.0);
check('decimal comma', CsvSyncTransformer::toNumber('129,00'), 129.0);
check('euro suffix', CsvSyncTransformer::toNumber('1.299,00 €'), 1299.0);
check('anglo thousands', CsvSyncTransformer::toNumber('1,299.00'), 1299.0);
check('lone thousands comma', CsvSyncTransformer::toNumber('1,299'), 1299.0);
check('decimal comma, two places', CsvSyncTransformer::toNumber('19,90'), 19.9);
check('currency prefix', CsvSyncTransformer::toNumber('EUR 45.50'), 45.5);
check('negative', CsvSyncTransformer::toNumber('-12,50'), -12.5);
check('empty', CsvSyncTransformer::toNumber(''), 0.0);
check('junk', CsvSyncTransformer::toNumber('n/a'), 0.0);

// --- booleans and availability ------------------------------------------
check('python True', CsvSyncTransformer::toBool('True'), true);
check('python False', CsvSyncTransformer::toBool('False'), false);
check('empty is false', CsvSyncTransformer::toBool(''), false);
check('slovene da', CsvSyncTransformer::toBool('da'), true);
check(
    'schema InStock',
    CsvSyncTransformer::availabilityToBool('http://schema.org/InStock'),
    true
);
check(
    'schema OutOfStock',
    CsvSyncTransformer::availabilityToBool('https://schema.org/OutOfStock'),
    false
);
check('bare InStock', CsvSyncTransformer::availabilityToBool('InStock'), true);

// --- Scrapy's Python list repr ------------------------------------------
check(
    'python list of urls',
    CsvSyncTransformer::parsePythonList("['https://a.jpg', 'https://b.jpg']"),
    ['https://a.jpg', 'https://b.jpg']
);
check('empty python list', CsvSyncTransformer::parsePythonList('[]'), []);
check(
    'python list with an apostrophe',
    CsvSyncTransformer::parsePythonList('["Kite\'s cover", "Bag"]'),
    ["Kite's cover", 'Bag']
);
check('bare value is a one-item list', CsvSyncTransformer::parsePythonList('https://a.jpg'), ['https://a.jpg']);
check(
    'json list',
    CsvSyncTransformer::parseJsonList('["Boards", "Kites"]'),
    ['Boards', 'Kites']
);
check(
    'json list of objects flattens to its values',
    CsvSyncTransformer::parseJsonList('[{"name": "Boards", "url": "/boards"}]'),
    ['Boards', '/boards']
);

// --- transforms as the mapping screen applies them -----------------------
check(
    'breadcrumbs to a category path',
    CsvSyncTransformer::apply('breadcrumb_to_path', "['Home', 'Boards', 'Kites']"),
    'Home > Boards > Kites'
);
check(
    'images to a pipe list',
    CsvSyncTransformer::apply('python_list', "['https://a.jpg', 'https://b.jpg']"),
    'https://a.jpg | https://b.jpg'
);
check(
    'first of a list',
    CsvSyncTransformer::apply('first_of_list', "['https://a.jpg', 'https://b.jpg']"),
    'https://a.jpg'
);
check('availability transform', CsvSyncTransformer::apply('schema_availability', 'http://schema.org/InStock'), 1);
check('number transform', CsvSyncTransformer::apply('number', '1.299,00 €'), 1299.0);
check('strip tags', CsvSyncTransformer::apply('strip_tags', '<p>Hello <b>there</b></p>'), 'Hello there');
check(
    'protocol-relative url made absolute',
    CsvSyncTransformer::apply('absolute_url', '//cdn.shop.si/a.jpg'),
    'https://cdn.shop.si/a.jpg'
);
check('unknown transform passes through', CsvSyncTransformer::apply('nope', ' x '), ' x ');

// --- the reader, over a file shaped like a real scrape -------------------
$csv = tempnam(sys_get_temp_dir(), 'csvsync_test');
file_put_contents($csv, "\xEF\xBB\xBF" . <<<CSV
name,product_id,price,currency,in_stock,images,breadcrumbs
"Duotone Neo 2024",DN2024,"1.299,00",EUR,True,"['https://a.jpg', 'https://b.jpg']","['Home', 'Kites']"
"Board, with a comma",BRD1,"499,00",EUR,False,"['https://c.jpg']","['Home', 'Boards']"

"Quoted ""quotes"" inside",Q1,"9,90",EUR,True,[],[]
CSV
);

$fake = new CsvSyncSource();
$fake->location = $csv;
$reader = new CsvSyncReader($fake);
$reader->open();

check('header parsed without a BOM', $reader->getHeader()[0], 'name');
check('all columns found', count($reader->getHeader()), 7);

$row = $reader->next();
check('first row name', $row['name'], 'Duotone Neo 2024');
check('first row id', $row['product_id'], 'DN2024');
check('first row price', $row['price'], '1.299,00');
check('first row images', $row['images'], "['https://a.jpg', 'https://b.jpg']");

$row = $reader->next();
check('comma inside a quoted field', $row['name'], 'Board, with a comma');

$row = $reader->next();
check('blank line skipped, escaped quotes kept', $row['name'], 'Quoted "quotes" inside');
check('end of file', $reader->next(), null);
$reader->close();
unlink($csv);

printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
