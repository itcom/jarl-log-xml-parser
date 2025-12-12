<?php

namespace Itcom\JarlLog;

use Illuminate\Support\Facades\Log;

class JarlLogXmlParser
{
    public static function parse(string $rawText): array
    {
        // Detect encoding for later conversion
        $encoding = mb_detect_encoding($rawText, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'ISO-2022-JP', 'ASCII'], true);

        $result = ['summary' => [], 'logs' => []];

        // Parse SUMMARYSHEET (convert to UTF-8 for XML parsing)
        $utf8Text = self::convertToUtf8($rawText, $encoding);
        if (preg_match('/<SUMMARYSHEET\b[^>]*>(.*?)<\/SUMMARYSHEET>/si', $utf8Text, $m)) {
            $block = $m[1] ?? '';
            if (preg_match_all('/<([A-Z]+)>([^<]*)<\/\1>/i', $block, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $node) {
                    $result['summary'][strtolower($node[1])] = trim($node[2]);
                }
            }
        }

        // Parse LOGSHEET using fixed-width columns (byte-based, before UTF-8 conversion)
        if (preg_match('/<LOGSHEET\b[^>]*>(.*?)<\/LOGSHEET>/si', $rawText, $m2)) {
            $content = trim($m2[1] ?? '');
            $lines = preg_split('/\r?\n/', $content);

            // Get header line and determine column positions (byte-based)
            $headerLine = array_shift($lines);
            $columns = self::parseHeaderPositions($headerLine);

            if (empty($columns)) {
                Log::warning("Failed to parse LOGSHEET header: {$headerLine}");
                return $result;
            }

            foreach ($lines as $line) {
                if (trim($line) === '' || strpos($line, '---') === 0) {
                    continue;
                }

                $row = self::extractFixedWidthFields($line, $columns, $encoding);
                if ($row) {
                    $result['logs'][] = $row;
                } else {
                    Log::warning("Failed to parse log line: {$line}");
                }
            }
        }

        return $result;
    }

    private static function convertToUtf8(string $text, ?string $encoding): string
    {
        if ($encoding && $encoding !== 'UTF-8') {
            return mb_convert_encoding($text, 'UTF-8', $encoding);
        }
        return $text;
    }

    /**
     * Parse header line to get column positions (byte-based)
     */
    private static function parseHeaderPositions(string $headerLine): array
    {
        $columnMap = [
            'DATE(JST)' => 'date',
            'DATE (JST)' => 'date',  // スペースあり版も追加
            'DATE'      => 'date',
            'TIME'      => 'time',
            'BAND'      => 'band',
            'MHz'       => 'band',
            'Mode'      => 'mode',
            'MODE'      => 'mode',
            'CALLSIGN'  => 'callsign',
            'SENTNo'    => 'sent',
            'SENT'      => 'sent',
            'RCVDNo'    => 'rcv',   // CTESTWIN形式
            'RCVNo'     => 'rcv',   // HLTST形式
            'RCV'       => 'rcv',
            'Multi'     => 'mlt',
            'Mlt'       => 'mlt',   // CTESTWIN形式
            'MLT'       => 'mlt',
            'PT'        => 'pts',
            'Pts'       => 'pts',   // CTESTWIN形式
            'PTS'       => 'pts',
        ];

        $columns = [];
        // 長い名前を先にマッチさせるため、名前の長さでソート
        $sortedMap = $columnMap;
        uksort($sortedMap, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($sortedMap as $headerName => $fieldKey) {
            // 既にこのフィールドが見つかっていたらスキップ
            if (isset($columns[$fieldKey])) {
                continue;
            }
            $pos = strpos($headerLine, $headerName);
            if ($pos !== false) {
                $columns[$fieldKey] = [
                    'start' => $pos,
                    'len'   => strlen($headerName),
                ];
            }
        }

        $keys = array_keys($columns);
        usort($keys, fn($a, $b) => $columns[$a]['start'] <=> $columns[$b]['start']);

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $currentKey = $keys[$i];
            $nextKey = $keys[$i + 1];
            $columns[$currentKey]['len'] = $columns[$nextKey]['start'] - $columns[$currentKey]['start'];
        }
        if (count($keys) > 0) {
            $lastKey = $keys[count($keys) - 1];
            $columns[$lastKey]['len'] = 20;
        }

        return $columns;
    }

    /**
     * Extract fields from a fixed-width line (byte-based, then convert to UTF-8)
     */
    private static function extractFixedWidthFields(string $line, array $columns, ?string $encoding): ?array
    {
        $get = function ($key) use ($line, $columns, $encoding) {
            if (!isset($columns[$key])) {
                return '';
            }
            $start = $columns[$key]['start'];
            $len = $columns[$key]['len'];
            // Use substr for byte-based extraction
            $value = trim(substr($line, $start, $len));
            // Convert extracted value to UTF-8
            return self::convertToUtf8($value, $encoding);
        };

        $date = $get('date');
        $time = $get('time');
        $band = $get('band');
        $mode = $get('mode');
        $callsign = $get('callsign');
        $sent = $get('sent');
        $rcv = $get('rcv');
        $mlt = $get('mlt');
        $pts = $get('pts');

        if (empty($date) || empty($callsign)) {
            return null;
        }

        $sentRst = '';
        $sentNo = '';
        if (preg_match('/^(\d{2,3})\s*(.*)$/u', $sent, $sm)) {
            $sentRst = $sm[1];
            $sentNo = trim($sm[2]);
        }

        $rcvRst = '';
        $rcvNo = '';
        if (preg_match('/^(\d{2,3})\s*(.*)$/u', $rcv, $rm)) {
            $rcvRst = $rm[1];
            $rcvNo = trim($rm[2]);
        }

        return [
            'date'     => $date,
            'time'     => $time,
            'band'     => $band,
            'mode'     => $mode,
            'callsign' => $callsign,
            'sentRst'  => $sentRst,
            'sentNo'   => $sentNo,
            'rcvRst'   => $rcvRst,
            'rcvNo'    => $rcvNo,
            'mlt'      => $mlt,
            'pts'      => $pts,
        ];
    }
}
