<?php
/**
 * Pure-PHP reader for the Эгоскоп export workbooks.
 *
 * The device produces legacy BIFF8 `.xls` files (OLE2 compound document). We
 * also accept `.xlsx` (ZIP + XML) and `.csv` so data can be pasted/uploaded in
 * several shapes. The reader returns a normalized grid per sheet:
 *
 *   ['sheets' => ['SheetName' => [[row0col0, row0col1, ...], ...], ...]]
 *
 * Cell values are strings or floats (numbers). Empty cells are ''.
 *
 * No Composer, no extensions beyond ext-zip (xlsx) and core. The BIFF reader
 * implements just enough of the format for these exports: SST (+CONTINUE),
 * LABELSST, LABEL, NUMBER, RK, MULRK, FORMULA (cached value) and BOUNDSHEET.
 */

final class Excel {
    /** Read any supported spreadsheet → ['sheets' => [name => grid]]. */
    public static function read(string $path): array {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') return ['sheets' => self::readXlsx($path)];
        if ($ext === 'csv')  return ['sheets' => ['csv' => self::readCsv($path)]];
        return ['sheets' => self::readXls($path)];
    }

    /* ─────────────────────────── CSV ─────────────────────────── */

    public static function readCsv(string $path): array {
        $rows = [];
        $fh = @fopen($path, 'r');
        if ($fh === false) throw new RuntimeException('CSV open failed');
        while (($r = fgetcsv($fh, 0, ',')) !== false) {
            $rows[] = array_map(static fn ($v) => is_numeric($v) ? (float) $v : (string) $v, $r);
        }
        fclose($fh);
        return $rows;
    }

    /* ─────────────────────────── XLSX ────────────────────────── */

    private static function readXlsx(string $path): array {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive not available');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('XLSX open failed');

        // Shared strings.
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $dom = self::loadXml($ss);
            foreach ($dom->getElementsByTagName('si') as $si) {
                $shared[] = $si->textContent;
            }
        }
        // Sheet name → file map (workbook.xml + rels).
        $names = [];
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relMap = [];
        if ($rels !== false) {
            $rd = self::loadXml($rels);
            foreach ($rd->getElementsByTagName('Relationship') as $r) {
                $relMap[$r->getAttribute('Id')] = $r->getAttribute('Target');
            }
        }
        if ($wb !== false) {
            $wd = self::loadXml($wb);
            foreach ($wd->getElementsByTagName('sheet') as $i => $sh) {
                $rid = $sh->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $target = $relMap[$rid] ?? ('worksheets/sheet' . ($i + 1) . '.xml');
                $names[$sh->getAttribute('name')] = 'xl/' . ltrim($target, '/');
            }
        }
        if (!$names) $names = ['Sheet1' => 'xl/worksheets/sheet1.xml'];

        $sheets = [];
        foreach ($names as $name => $file) {
            $xml = $zip->getFromName($file);
            if ($xml === false) { $sheets[$name] = []; continue; }
            $sheets[$name] = self::parseXlsxSheet($xml, $shared);
        }
        $zip->close();
        return $sheets;
    }

    private static function parseXlsxSheet(string $xml, array $shared): array {
        $dom = self::loadXml($xml);
        $grid = [];
        foreach ($dom->getElementsByTagName('row') as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->getElementsByTagName('c') as $c) {
                [$col] = self::a1ToRowCol($c->getAttribute('r') ?: 'A1');
                $type = $c->getAttribute('t');
                $vNode = $c->getElementsByTagName('v')->item(0);
                $val = '';
                if ($type === 's' && $vNode) {
                    $val = $shared[(int) $vNode->nodeValue] ?? '';
                } elseif ($type === 'inlineStr') {
                    $is = $c->getElementsByTagName('t')->item(0);
                    $val = $is ? $is->nodeValue : '';
                } elseif ($vNode) {
                    $val = is_numeric($vNode->nodeValue) ? (float) $vNode->nodeValue : (string) $vNode->nodeValue;
                }
                $cells[$col] = $val;
                if ($col > $maxCol) $maxCol = $col;
            }
            $out = [];
            for ($i = 0; $i <= $maxCol; $i++) $out[$i] = $cells[$i] ?? '';
            $grid[] = $out;
        }
        return $grid;
    }

    /** "B5" → [colIndex, rowIndex] (0-based). */
    private static function a1ToRowCol(string $a1): array {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $a1, $m)) return [0, 0];
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        return [$col - 1, (int) $m[2] - 1];
    }

    private static function loadXml(string $xml): DOMDocument {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    /* ───────────────────── XLS (OLE2 + BIFF8) ───────────────────── */

    private static function readXls(string $path): array {
        $data = @file_get_contents($path);
        if ($data === false) throw new RuntimeException('XLS open failed');
        $workbook = self::oleStream($data, ['Workbook', 'Book']);
        if ($workbook === null) throw new RuntimeException('XLS: Workbook stream not found (not a BIFF8 file?)');
        return self::parseBiff($workbook);
    }

    /* ── OLE2 compound document: extract a named stream ── */
    private static function oleStream(string $data, array $candidateNames): ?string {
        if (strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Not an OLE2 file');
        }
        $u16 = static fn ($o) => unpack('v', substr($data, $o, 2))[1];
        $u32 = static fn ($o) => unpack('V', substr($data, $o, 4))[1];

        $sectorSize  = 1 << $u16(0x1E);
        $miniSize    = 1 << $u16(0x20);
        $numFat      = $u32(0x2C);
        $dirStart    = $u32(0x30);
        $miniCutoff  = $u32(0x38);
        $miniFatStart = $u32(0x3C);
        $numDifat    = $u32(0x48);
        $difatStart  = $u32(0x44);

        $sector = static function (int $id) use ($data, $sectorSize): string {
            $off = ($id + 1) * $sectorSize;
            return substr($data, $off, $sectorSize);
        };

        // Build DIFAT (list of FAT sector ids): 109 in header, then chained sectors.
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $v = $u32(0x4C + $i * 4);
            if ($v !== 0xFFFFFFFF) $difat[] = $v;
        }
        $sid = $difatStart;
        for ($n = 0; $n < $numDifat && $sid !== 0xFFFFFFFF && $sid !== 0xFFFFFFFE; $n++) {
            $sec = $sector($sid);
            $cnt = intdiv($sectorSize, 4);
            for ($i = 0; $i < $cnt - 1; $i++) {
                $v = unpack('V', substr($sec, $i * 4, 4))[1];
                if ($v !== 0xFFFFFFFF) $difat[] = $v;
            }
            $sid = unpack('V', substr($sec, ($cnt - 1) * 4, 4))[1];
        }

        // Build the FAT (sector → next sector).
        $fat = [];
        foreach ($difat as $fs) {
            $sec = $sector($fs);
            $cnt = intdiv($sectorSize, 4);
            for ($i = 0; $i < $cnt; $i++) {
                $fat[] = unpack('V', substr($sec, $i * 4, 4))[1];
            }
        }

        $chain = static function (int $start) use ($fat, $sector): string {
            $out = '';
            $id = $start;
            $guard = 0;
            while ($id !== 0xFFFFFFFE && $id !== 0xFFFFFFFF && isset($fat[$id]) && $guard++ < 1000000) {
                $out .= $sector($id);
                $id = $fat[$id];
            }
            return $out;
        };

        // Directory entries.
        $dir = $chain($dirStart);
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $e = substr($dir, $o, 128);
            $nameLen = unpack('v', substr($e, 64, 2))[1];
            if ($nameLen <= 0) continue;
            $nameRaw = substr($e, 0, max(0, $nameLen - 2));
            $name = @mb_convert_encoding($nameRaw, 'UTF-8', 'UTF-16LE');
            $type = ord($e[66]);
            $startSector = unpack('V', substr($e, 116, 4))[1];
            $size = unpack('V', substr($e, 120, 4))[1];
            $entries[] = ['name' => $name, 'type' => $type, 'start' => $startSector, 'size' => $size];
        }

        // Root entry (type 5) holds the mini-stream.
        $root = null;
        foreach ($entries as $e) { if ($e['type'] === 5) { $root = $e; break; } }
        $miniStream = $root ? $chain($root['start']) : '';

        // Mini-FAT chain reader (for small streams).
        $miniFat = [];
        if ($miniFatStart !== 0xFFFFFFFE && $miniFatStart !== 0xFFFFFFFF) {
            $mf = $chain($miniFatStart);
            for ($i = 0; $i + 4 <= strlen($mf); $i += 4) {
                $miniFat[] = unpack('V', substr($mf, $i, 4))[1];
            }
        }
        $miniChain = static function (int $start, int $size) use ($miniFat, $miniStream, $miniSize): string {
            $out = '';
            $id = $start;
            $guard = 0;
            while ($id !== 0xFFFFFFFE && $id !== 0xFFFFFFFF && isset($miniFat[$id]) && $guard++ < 1000000) {
                $out .= substr($miniStream, $id * $miniSize, $miniSize);
                $id = $miniFat[$id];
            }
            return substr($out, 0, $size);
        };

        foreach ($candidateNames as $want) {
            foreach ($entries as $e) {
                if ($e['type'] === 2 && $e['name'] === $want) {
                    if ($e['size'] < $miniCutoff) return $miniChain($e['start'], $e['size']);
                    return substr($chain($e['start']), 0, $e['size']);
                }
            }
        }
        return null;
    }

    /* ── BIFF8 record stream → sheets grid ── */
    private static function parseBiff(string $wb): array {
        $len = strlen($wb);
        // First pass: collect global records (SST, BOUNDSHEET).
        $sst = [];
        $boundsheets = []; // [pos, name]
        $pos = 0;
        while ($pos + 4 <= $len) {
            $type = unpack('v', substr($wb, $pos, 2))[1];
            $rl = unpack('v', substr($wb, $pos + 2, 2))[1];
            $dataStart = $pos + 4;
            if ($type === 0x00FC) { // SST (may be followed by CONTINUE)
                $sst = self::parseSst($wb, $dataStart, $rl, $len);
            } elseif ($type === 0x0085) { // BOUNDSHEET
                $streamPos = unpack('V', substr($wb, $dataStart, 4))[1];
                $name = self::shortUnicode($wb, $dataStart + 6);
                $boundsheets[] = ['pos' => $streamPos, 'name' => $name];
            } elseif ($type === 0x000A && $pos > 0) { // EOF of globals
                // keep scanning; sheets follow, but we re-seek via boundsheets
            }
            $pos = $dataStart + $rl;
            // Stop global scan once we pass into the first worksheet substream.
            if ($type === 0x000A && !empty($boundsheets) && $pos >= ($boundsheets[0]['pos'] ?? PHP_INT_MAX)) break;
        }

        $sheets = [];
        $count = count($boundsheets);
        foreach ($boundsheets as $i => $bs) {
            $end = ($i + 1 < $count) ? $boundsheets[$i + 1]['pos'] : $len;
            $sheets[$bs['name']] = self::parseSheet($wb, $bs['pos'], $end, $sst);
        }
        if (!$sheets) {
            // No BOUNDSHEET found: parse the whole stream as one sheet.
            $sheets['Sheet1'] = self::parseSheet($wb, 0, $len, $sst);
        }
        return $sheets;
    }

    private static function parseSheet(string $wb, int $start, int $end, array $sst): array {
        $cells = []; // [row][col] => value
        $pendingStr = null; // [row,col] of a FORMULA awaiting its STRING result
        $pos = $start;
        $len = min($end, strlen($wb));
        // Skip to the worksheet's own BOF.
        while ($pos + 4 <= $len) {
            $type = unpack('v', substr($wb, $pos, 2))[1];
            $rl = unpack('v', substr($wb, $pos + 2, 2))[1];
            $d = $pos + 4;
            switch ($type) {
                case 0x000A: // EOF
                    $pos = $d + $rl;
                    break 2;
                case 0x00FD: // LABELSST
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $col = unpack('v', substr($wb, $d + 2, 2))[1];
                    $isst = unpack('V', substr($wb, $d + 6, 4))[1];
                    $cells[$row][$col] = $sst[$isst] ?? '';
                    break;
                case 0x0204: // LABEL
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $col = unpack('v', substr($wb, $d + 2, 2))[1];
                    $cells[$row][$col] = self::shortUnicode($wb, $d + 6);
                    break;
                case 0x0203: // NUMBER
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $col = unpack('v', substr($wb, $d + 2, 2))[1];
                    $cells[$row][$col] = unpack('d', substr($wb, $d + 6, 8))[1];
                    break;
                case 0x027E: // RK
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $col = unpack('v', substr($wb, $d + 2, 2))[1];
                    $cells[$row][$col] = self::rk(substr($wb, $d + 6, 4));
                    break;
                case 0x00BD: // MULRK
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $colFirst = unpack('v', substr($wb, $d + 2, 2))[1];
                    $n = intdiv($rl - 6, 6);
                    for ($k = 0; $k < $n; $k++) {
                        $rkBytes = substr($wb, $d + 4 + $k * 6 + 2, 4);
                        $cells[$row][$colFirst + $k] = self::rk($rkBytes);
                    }
                    break;
                case 0x0006: // FORMULA (use cached result)
                    $row = unpack('v', substr($wb, $d, 2))[1];
                    $col = unpack('v', substr($wb, $d + 2, 2))[1];
                    $res = substr($wb, $d + 6, 8);
                    if (ord($res[6]) === 0xFF && ord($res[7]) === 0xFF) {
                        // Non-numeric cached result (string/bool/error); a STRING
                        // record with the cached text follows next.
                        $cells[$row][$col] = '';
                        $pendingStr = [$row, $col];
                    } else {
                        $cells[$row][$col] = unpack('d', $res)[1];
                    }
                    break;
                case 0x0207: // STRING (cached string result of preceding FORMULA)
                    if ($pendingStr !== null) {
                        $cells[$pendingStr[0]][$pendingStr[1]] = self::longUnicode($wb, $d);
                        $pendingStr = null;
                    }
                    break;
            }
            $pos = $d + $rl;
        }
        // Normalize sparse [row][col] map into a dense grid.
        if (!$cells) return [];
        $maxRow = max(array_keys($cells));
        $grid = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $rowCells = $cells[$r] ?? [];
            $maxCol = $rowCells ? max(array_keys($rowCells)) : -1;
            $out = [];
            for ($c = 0; $c <= $maxCol; $c++) $out[$c] = $rowCells[$c] ?? '';
            $grid[$r] = $out;
        }
        return $grid;
    }

    /** Parse the SST record + its CONTINUE records into an indexed string array. */
    private static function parseSst(string $wb, int $dataStart, int $recLen, int $len): array {
        // Gather SST payload across CONTINUE records, tracking each segment boundary
        // so a string split across records can re-read its grbit byte.
        $segments = [];
        $segStart = $dataStart;
        $segLen = $recLen;
        $segments[] = [$segStart, $segLen];
        $next = $dataStart + $recLen;
        while ($next + 4 <= $len) {
            $t = unpack('v', substr($wb, $next, 2))[1];
            $l = unpack('v', substr($wb, $next + 2, 2))[1];
            if ($t !== 0x003C) break; // not CONTINUE → SST ends
            $segments[] = [$next + 4, $l];
            $next += 4 + $l;
        }

        // Flatten into a byte buffer with a parallel map of segment boundaries.
        $buf = '';
        $bounds = []; // absolute offset in $buf where each segment starts
        foreach ($segments as [$s, $l]) {
            $bounds[] = strlen($buf);
            $buf .= substr($wb, $s, $l);
        }
        $bufLen = strlen($buf);
        $isBoundary = array_flip($bounds);

        $u32 = static fn ($o) => unpack('V', substr($buf, $o, 4))[1];
        $cstTotal = $u32(0);
        $cstUnique = $u32(4);
        $p = 8;
        $strings = [];
        for ($i = 0; $i < $cstUnique && $p + 3 <= $bufLen; $i++) {
            $cch = unpack('v', substr($buf, $p, 2))[1];
            $grbit = ord($buf[$p + 2]);
            $p += 3;
            $highByte = (bool) ($grbit & 0x01);
            $hasRich = (bool) ($grbit & 0x08);
            $hasExt = (bool) ($grbit & 0x04);
            $cRun = 0; $cbExt = 0;
            if ($hasRich) { $cRun = unpack('v', substr($buf, $p, 2))[1]; $p += 2; }
            if ($hasExt)  { $cbExt = unpack('V', substr($buf, $p, 4))[1]; $p += 4; }

            // Read $cch characters, honoring CONTINUE re-flag at segment boundaries.
            $chars = '';
            $read = 0;
            while ($read < $cch && $p <= $bufLen) {
                if (isset($isBoundary[$p]) && $read > 0) {
                    // New segment: a fresh grbit byte precedes the remaining chars.
                    $grbit = ord($buf[$p]);
                    $highByte = (bool) ($grbit & 0x01);
                    $p += 1;
                }
                if ($highByte) {
                    $chars .= substr($buf, $p, 2);
                    $p += 2;
                } else {
                    // Compressed 1-byte char → widen to UTF-16LE.
                    $chars .= $buf[$p] . "\x00";
                    $p += 1;
                }
                $read++;
            }
            $strings[] = @mb_convert_encoding($chars, 'UTF-8', 'UTF-16LE');

            // Skip rich-text runs and ext (phonetic) data.
            $p += $cRun * 4;
            $p += $cbExt;
        }
        return $strings;
    }

    /** Decode a 4-byte RK number. */
    private static function rk(string $b): float {
        $rk = unpack('V', $b)[1];
        $cents = $rk & 0x01;
        $isInt = $rk & 0x02;
        if ($isInt) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000; // sign-extend 30-bit
            $num = (float) $v;
        } else {
            $packed = pack('V', $rk & 0xFFFFFFFC);
            $num = unpack('d', "\x00\x00\x00\x00" . $packed)[1];
        }
        return $cents ? $num / 100.0 : $num;
    }

    /** Long Unicode string (STRING record): 2-byte cch, 1-byte grbit, then chars. */
    private static function longUnicode(string $wb, int $o): string {
        $cch = unpack('v', substr($wb, $o, 2))[1];
        $grbit = ord($wb[$o + 2]);
        $p = $o + 3;
        if ($grbit & 0x01) {
            $raw = substr($wb, $p, $cch * 2);
        } else {
            $raw = '';
            for ($i = 0; $i < $cch; $i++) $raw .= $wb[$p + $i] . "\x00";
        }
        return @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }

    /** Short Unicode string: 1-byte cch, 1-byte grbit, then chars. */
    private static function shortUnicode(string $wb, int $o): string {
        $cch = ord($wb[$o]);
        $grbit = ord($wb[$o + 1]);
        $p = $o + 2;
        $high = (bool) ($grbit & 0x01);
        if ($high) {
            $raw = substr($wb, $p, $cch * 2);
        } else {
            $raw = '';
            for ($i = 0; $i < $cch; $i++) $raw .= $wb[$p + $i] . "\x00";
        }
        return @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }
}
