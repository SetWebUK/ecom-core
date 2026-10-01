<?php

namespace Pine\Commerce\Tests\Concerns;

/**
 * Minimal PDF reading for tests (no binaries): inflates the content streams of a dompdf PDF and decodes the text
 * operands. dompdf writes embedded TrueType text as UTF-16BE strings (Identity-H), core fonts as single bytes.
 */
trait ReadsPdfs
{
    /** Every decompressed stream of the PDF. @return list<string> */
    protected function pdfStreams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches);
        $out = [];
        foreach ($matches[1] as $raw) {
            $data = @gzuncompress(rtrim($raw, "\r\n"));
            $out[] = $data === false ? $raw : $data;
        }

        return $out;
    }

    /** The visible text of the PDF (text-showing operands of every content stream, in order). */
    protected function pdfText(string $pdf): string
    {
        $text = '';
        foreach ($this->pdfStreams($pdf) as $stream) {
            if (! preg_match('/\bBT\b/', $stream)) {
                continue;
            }
            $len = strlen($stream);
            for ($i = 0; $i < $len; $i++) {
                if ($stream[$i] !== '(') {
                    continue;
                }
                $string = '';
                $depth = 1;
                for ($i++; $i < $len && $depth > 0; $i++) {
                    $c = $stream[$i];
                    if ($c === '\\') {
                        $n = $stream[++$i] ?? '';
                        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                        if (isset($map[$n])) {
                            $string .= $map[$n];
                        } elseif (ctype_digit($n)) {
                            $oct = $n;
                            while (strlen($oct) < 3 && isset($stream[$i + 1]) && ctype_digit($stream[$i + 1])) {
                                $oct .= $stream[++$i];
                            }
                            $string .= chr(octdec($oct) & 0xFF);
                        }
                        continue;
                    }
                    if ($c === '(') {
                        $depth++;
                    } elseif ($c === ')' && --$depth === 0) {
                        break;
                    }
                    $string .= $c;
                }
                // UTF-16BE text (embedded fonts) has a zero high byte for Latin characters
                $text .= (strlen($string) % 2 === 0 && str_contains($string, "\0"))
                    ? mb_convert_encoding($string, 'UTF-8', 'UTF-16BE')
                    : mb_convert_encoding($string, 'UTF-8', 'Windows-1252');
            }
            $text .= "\n";
        }

        return $text;
    }

    protected function assertIsPdf(string $bytes): void
    {
        $this->assertStringStartsWith('%PDF-', $bytes, 'Not a PDF');
        $this->assertStringContainsString('%%EOF', substr($bytes, -64), 'Truncated PDF');
    }
}
