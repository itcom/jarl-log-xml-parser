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

        // Parse LOGSHEET using fixed-width columns
        if (preg_match('/<LOGSHEET\b[^>]*>(.*?)<\/LOGSHEET>/si', $utf8Text, $m2)) {
            $content = trim($m2[1] ?? '');
            $lines = preg_split('/\r?\n/', $content);

            // Get header line and determine column positions
            $headerLine = array_shift($lines);

            // 有効なデータ行を収集
            $dataLines = [];
            foreach ($lines as $line) {
                if (trim($line) !== '' && strpos($line, '---') !== 0) {
                    $dataLines[] = $line;
                }
            }

            $columns = self::parseHeaderPositions($headerLine, $dataLines);

            if (empty($columns)) {
                Log::warning("Failed to parse LOGSHEET header: {$headerLine}");
                return $result;
            }

            foreach ($dataLines as $line) {
                $row = self::extractFixedWidthFields($line, $columns);
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
     * Parse header line to get column positions
     * データ行の末尾から逆算してカラム幅を決定
     */
    private static function parseHeaderPositions(string $headerLine, array $dataLines = []): array
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
            $pos = mb_strpos($headerLine, $headerName);
            if ($pos !== false) {
                $columns[$fieldKey] = [
                    'start' => $pos,
                    'len'   => mb_strlen($headerName),
                ];
            }
        }

        $keys = array_keys($columns);
        usort($keys, fn($a, $b) => $columns[$a]['start'] <=> $columns[$b]['start']);

        // データ行からMltの実際の開始位置を検出
        $mltActualStart = null;
        if (!empty($dataLines)) {
            $mltActualStart = self::detectMltPosition($dataLines);
        }

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $currentKey = $keys[$i];
            $nextKey = $keys[$i + 1];

            // rcvカラムの場合、データ行から検出したMlt位置を使用
            if ($currentKey === 'rcv' && $mltActualStart !== null) {
                $columns[$currentKey]['len'] = $mltActualStart - $columns[$currentKey]['start'];
            } else {
                $columns[$currentKey]['len'] = $columns[$nextKey]['start'] - $columns[$currentKey]['start'];
            }
        }

        if (count($keys) > 0) {
            $lastKey = $keys[count($keys) - 1];
            $columns[$lastKey]['len'] = 20;
        }

        return $columns;
    }

    /**
     * データ行からMltカラムの実際の開始位置を検出
     * 末尾パターン: [空白][Mlt値][空白][Pts値]
     */
    private static function detectMltPosition(array $dataLines): ?int
    {
        foreach ($dataLines as $line) {
            // 末尾のパターンを検出: "   -       0" や "   1       5" など
            // Mlt値(-, 数字等) と Pts値(数字) のパターン
            if (preg_match('/\s+([^\s]+)\s+(\d+)\s*$/u', $line, $match, PREG_OFFSET_CAPTURE)) {
                // $match[1][1] がMlt値の開始バイト位置
                // 表示幅位置に変換するため、その前の文字列の表示幅を計算
                $prefix = substr($line, 0, $match[1][1]);
                return mb_strwidth($prefix, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * Extract fields from a fixed-width line (display-width-based)
     */
    private static function extractFixedWidthFields(string $line, array $columns): ?array
    {
        $get = function ($key) use ($line, $columns) {
            if (!isset($columns[$key])) {
                return '';
            }
            $startWidth = $columns[$key]['start'];
            $widthLen = $columns[$key]['len'];

            return trim(self::extractByDisplayWidth($line, $startWidth, $widthLen));
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
        if (preg_match('/^([+-]?\d{2,3})\s*(.*)$/u', $sent, $sm)) {
            $sentRst = $sm[1];
            $sentNo = trim($sm[2]);
        }

        $rcvRst = '';
        $rcvNo = '';
        if (preg_match('/^([+-]?\d{2,3})\s*(.*)$/u', $rcv, $rm)) {
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

    /**
     * 表示幅ベースで文字列を切り出す
     */
    private static function extractByDisplayWidth(string $str, int $startWidth, int $widthLen): string
    {
        $prefix = mb_strimwidth($str, 0, $startWidth, '', 'UTF-8');
        $offset = strlen($prefix);
        $remainder = substr($str, $offset);
        return mb_strimwidth($remainder, 0, $widthLen, '', 'UTF-8');
    }
}
