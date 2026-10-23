<?php

namespace Dcat\Admin\Tests;

use Dcat\Admin\Grid\Exporters\ExcelExporter;
use Dcat\EasyExcel\Excel;
use Illuminate\Support\Collection;
use ZipArchive;

class ExportCompatibilityTest extends TestCase
{
    public function test_unchanged_spout_writes_unicode_and_numeric_xlsx_cells(): void
    {
        $path = sys_get_temp_dir().'/dcat-export-'.bin2hex(random_bytes(8)).'.xlsx';
        try {
            Excel::export([['id' => 1, 'name' => '中文 & <text>', 'amount' => 12.5], ['id' => 2, 'name' => 'second', 'amount' => -4]])->headings(['id' => 'id', 'name' => 'name', 'amount' => 'amount'])->xlsx()->store($path);
            $archive = new ZipArchive();
            $this->assertTrue($archive->open($path));
            $this->assertNotFalse($archive->getFromName('xl/worksheets/sheet1.xml'));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('中文 &amp; &lt;text&gt;', $sheet);
            $this->assertStringContainsString('12.5', $sheet);
            $this->assertStringContainsString('-4', $sheet);
            $archive->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_csv_text_formulas_are_neutralized_and_numbers_preserved(): void
    {
        $exporter = new class extends ExcelExporter {
            public function prepareRows(array $data): array { return $this->normalize(new Collection($data)); }
        };
        $exporter->extension('csv');
        $row = ['formula' => '=HYPERLINK("https://example.test")', 'command' => '  @SUM(1+1)', 'negative' => -12.5, 'decimal' => '-13.1', 'normal' => '中文'];
        $expected = $row;
        $expected['formula'] = "'".$row['formula'];
        $expected['command'] = "'".$row['command'];
        $this->assertSame([$expected], $exporter->prepareRows([$row]));
        $exporter->extension('xlsx');
        $this->assertSame([$row], $exporter->prepareRows([$row]));
    }
}
