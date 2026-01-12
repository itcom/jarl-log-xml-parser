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
            if (preg_match_all('/<([A-Za-z]+)>([^<]*)<\/\1>/i', $block, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $node) {
                    $key = strtolower($node[1]);
                    // <n> タグは name としてマッピング
                    if ($key === 'n') {
                        $key = 'name';
                    }
                    $result['summary'][$key] = trim($node[2]);
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
            'RCVDNo'    => 'rcv',   // CTESTWIN形式（固定幅で取得）
            // 'RCVNo', 'RCV' => HLTST形式では動的取得するためマッピングしない
            'Multi'     => 'mlt',
            'Mlt'       => 'mlt',   // CTESTWIN形式
            'MLT'       => 'mlt',
            'PT'        => 'pts',
            'Pts'       => 'pts',   // CTESTWIN形式
            'PTS'       => 'pts',
        ];

        $columns = [];
        $sortedMap = $columnMap;
        uksort($sortedMap, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($sortedMap as $headerName => $fieldKey) {
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

        // Mltの実際の開始位置が検出できた場合、mltとptsのstartを更新
        if ($mltActualStart !== null && isset($columns['mlt'])) {
            $mltShift = $mltActualStart - $columns['mlt']['start'];
            $columns['mlt']['start'] = $mltActualStart;
            // ptsも同じ分だけシフト
            if (isset($columns['pts'])) {
                $columns['pts']['start'] += $mltShift;
            }
        }

        // キーを再ソート（start位置が変わった可能性があるため）
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
        // 末尾からMultiとPTを先に抽出（HLTST形式で日本語名によりカラム位置がずれる対策）
        // CTESTWIN形式（rcvカラムがある）は固定幅で取得できるので末尾除去不要
        $mlt = '';
        $pts = '';
        $lineForFixedWidth = $line;
        $useTrailExtraction = !isset($columns['rcv']); // RCVカラムがない場合のみ末尾抽出

        if ($useTrailExtraction && isset($columns['mlt']) && isset($columns['pts'])) {
            // 末尾パターン: [空白][Multi値(-か*のみ)][空白][PT値][空白]
            // 注意: [^\s]+ ではなく [-*] に限定（日本語名にくっついた場合の誤認識防止）
            if (preg_match('/\s+([-*])\s+(\d+)\s*$/u', $line, $tailMatch)) {
                $mlt = trim($tailMatch[1]);
                $pts = trim($tailMatch[2]);
                // 末尾部分を除去した行で固定幅処理
                $lineForFixedWidth = preg_replace('/\s+[-*]\s+\d+\s*$/u', '', $line);
            }
        }

        $get = function ($key) use ($lineForFixedWidth, $columns) {
            if (!isset($columns[$key])) {
                return '';
            }
            $startWidth = $columns[$key]['start'];
            $widthLen = $columns[$key]['len'];

            return trim(self::extractByDisplayWidth($lineForFixedWidth, $startWidth, $widthLen));
        };

        $date = $get('date');
        $time = $get('time');
        $band = $get('band');
        $mode = $get('mode');
        $callsign = $get('callsign');
        $sent = $get('sent');

        // RCVの取得
        $rcv = '';
        if (isset($columns['rcv'])) {
            // CTESTWIN形式: RCVカラムが定義されている場合は固定幅で取得
            $rcv = $get('rcv');
        } elseif (isset($columns['sent'])) {
            // HLTST形式: SENTとRCVが連続、末尾にMulti/PTが含まれている可能性
            // 末尾抽出では日本語名にMulti記号がくっついた場合に誤認識するため、一括パターンで抽出
            $combined = trim(self::extractByDisplayWidth($line, $columns['sent']['start'], 100));
            
            // パターン1: [SENT_RST][sp][SENT_Name][RCV_RST][sp][RCV_Name][Multi][sp][PT]
            // 例: "59  いたばし59  やまぐち-      1"
            if (preg_match('/^([+-]?\d{2,3})\s+(.+?)([+-]?\d{2,3})\s+(.+?)([-*]|\d{1,3})\s+(\d+)\s*$/u', $combined, $splitMatch)) {
                $sent = $splitMatch[1] . '  ' . trim($splitMatch[2]);
                $rcv = $splitMatch[3] . '  ' . trim($splitMatch[4]);
                $mlt = $splitMatch[5];
                $pts = $splitMatch[6];
            }
            // パターン2: Multi/PTが既に末尾抽出されている場合（スペース区切りあり）
            elseif (preg_match('/^([+-]?\d{2,3})\s+(.+?)([+-]?\d{2,3})\s+(.+)$/u', $combined, $splitMatch)) {
                $sent = $splitMatch[1] . '  ' . trim($splitMatch[2]);
                $rcv = $splitMatch[3] . '  ' . trim($splitMatch[4]);
            } else {
                // 分割できない場合は全体をSENTとする
                $sent = $combined;
                $rcv = '';
            }
        }

        // Multi/PTが末尾抽出されていない場合は固定幅で取得
        if (!$useTrailExtraction || !isset($columns['mlt']) || !isset($columns['pts'])) {
            $mlt = $get('mlt');
            $pts = $get('pts');
        }

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
