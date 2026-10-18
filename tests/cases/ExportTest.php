<?php
// FILE: /tests/cases/ExportTest.php
// CSV export formula-injection escaping (public API of ExportService).

$suite->add('Export', 'CSV cells starting with = + - @ are neutralised', function () {
    if (!class_file_loads('app/helpers/ExportService.php')) {
        skip_test('ExportService does not load');
    }
    require_once APP_PATH . '/helpers/ExportService.php';
    if (!method_exists('ExportService', 'sanitizeCell') || !method_exists('ExportService', 'csvLine')) {
        skip_test('ExportService::sanitizeCell/csvLine not available');
    }

    assert_equals("'=HYPERLINK(\"http://evil\")", ExportService::sanitizeCell('=HYPERLINK("http://evil")'));
    assert_equals("'+1+1", ExportService::sanitizeCell('+1+1'));
    assert_equals("'@SUM(A1)", ExportService::sanitizeCell('@SUM(A1)'));
    assert_equals("'-2+3", ExportService::sanitizeCell('-2+3'));
    assert_equals('-12.5', ExportService::sanitizeCell('-12.5'), 'negative numbers stay numbers');
    assert_equals('', ExportService::sanitizeCell(null));

    $line = ExportService::csvLine(array('=cmd|"/c calc"!A1', 'plain', 'a,b'));
    assert_equals("\"'=cmd|\"\"/c calc\"\"!A1\",plain,\"a,b\"\r\n", $line);
}, 'ExportService owned by another engineer');
