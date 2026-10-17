<?php

namespace App\Support;

/**
 * Penulis file Excel multi-sheet TANPA library (format "XML Spreadsheet 2003").
 * Dibuka langsung oleh Microsoft Excel dan LibreOffice. Karena isinya XML
 * sementara ekstensinya .xls, Excel bisa menampilkan peringatan format saat
 * dibuka — pilih "Yes/Ya". Bila nanti boleh menambah paket (mis.
 * phpoffice/phpspreadsheet), kelas ini bisa diganti tanpa mengubah pemanggilnya.
 *
 * Contoh:
 *   $x = new ExcelXml();
 *   $x->sheet('Ringkasan', [['Judul', 'Nilai'], ['Total', 120]], headerRows: 1);
 *   return response($x->toString(), 200, $x->headers('laporan.xls'));
 */
class ExcelXml
{
    /** @var array<int, array{nama: string, baris: array, header: int, lebar: array}> */
    private array $sheets = [];

    /**
     * @param  array<int, array<int, scalar|null>>  $baris
     * @param  int[]  $lebar  lebar kolom (point), opsional
     */
    public function sheet(string $nama, array $baris, int $headerRows = 1, array $lebar = []): self
    {
        // Nama sheet Excel: maks 31 karakter, tanpa : \ / ? * [ ]
        $nama = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', '-', $nama), 0, 31);
        $this->sheets[] = ['nama' => $nama, 'baris' => $baris, 'header' => $headerRows, 'lebar' => $lebar];

        return $this;
    }

    public function toString(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<?mso-application progid="Excel.Sheet"?>'."\n";
        $x .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $x .= '<Styles>'
            .'<Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="11"/></Style>'
            .'<Style ss:ID="h"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#EA580C" ss:Pattern="Solid"/></Style>'
            .'<Style ss:ID="t"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1"/></Style>'
            .'</Styles>';

        foreach ($this->sheets as $s) {
            $x .= '<Worksheet ss:Name="'.$this->esc($s['nama']).'"><Table>';
            foreach ($s['lebar'] as $w) {
                $x .= '<Column ss:Width="'.(int) $w.'"/>';
            }
            foreach ($s['baris'] as $i => $baris) {
                $gaya = $i < $s['header'] ? ' ss:StyleID="h"' : '';
                $x .= '<Row>';
                foreach ($baris as $sel) {
                    if ($sel === null || $sel === '') {
                        $x .= '<Cell'.$gaya.'/>';
                    } elseif (is_int($sel) || is_float($sel)) {
                        $x .= '<Cell'.$gaya.'><Data ss:Type="Number">'.$sel.'</Data></Cell>';
                    } else {
                        $x .= '<Cell'.$gaya.'><Data ss:Type="String">'.$this->esc((string) $sel).'</Data></Cell>';
                    }
                }
                $x .= '</Row>';
            }
            $x .= '</Table></Worksheet>';
        }

        return $x.'</Workbook>';
    }

    public function headers(string $namaFile): array
    {
        return [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$namaFile.'"',
        ];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
