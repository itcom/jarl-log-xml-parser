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

        // Parse LOGSHEET using fixed-width columns or tab-delimited
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

            // タブ区切りか固定幅かを判定
            $isTabDelimited = strpos($headerLine, "\t") !== false;

            if ($isTabDelimited) {
                // タブ区切り形式
                $headerColumns = self::parseTabDelimitedHeader($headerLine);
                foreach ($dataLines as $line) {
                    $row = self::extractTabDelimitedFields($line, $headerColumns);
                    if ($row) {
                        $result['logs'][] = $row;
                    } else {
                        Log::warning("Failed to parse log line: {$line}");
                    }
                }
            } else {
                // 固定幅形式
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
            'Date'      => 'date',   // ZLOG形式
            'TIME'      => 'time',
            'Time'      => 'time',   // ZLOG形式
            'BAND'      => 'band',
            'MHz'       => 'band',
            'Mode'      => 'mode',
            'MODE'      => 'mode',
            'CALLSIGN'  => 'callsign',
            'Callsign'  => 'callsign', // ZLOG形式
            'SENTNo'    => 'sent',
            'SENT'      => 'sent',
            'RCVDNo'    => 'rcv',   // CTESTWIN形式（固定幅で取得）
            // ZLOG形式: RSTとNoが別カラム
            'RSTs'      => 'sentRst',
            'ExSent'    => 'sentNo',
            'RSTr'      => 'rcvRst',
            'ExRcvd'    => 'rcvNo',
            // マルチ/ポイント
            'Multi'     => 'mlt',
            'Mult'      => 'mlt',   // ZLOG形式
            'Mlt'       => 'mlt',   // CTESTWIN形式
            'MLT'       => 'mlt',
            'PT'        => 'pts',
            'Pt'        => 'pts',   // ZLOG形式
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

        // SENT+RCVの取得（CTESTWIN/HLTST両方で日本語文字により固定幅抽出がずれるため、一括パターンで抽出）
        $rcv = '';
        if (isset($columns['sent'])) {
            $combined = trim(self::extractByDisplayWidth($line, $columns['sent']['start'], 100));
            
            // パターン1: [SENT_RST][sp][SENT_Name][RCV_RST][sp][RCV_Name][Multi][sp][PT]
            // 例: "59  いたばし59  やまぐち-      1" または "58 おぐら   59 ぐんじ             1"
            if (preg_match('/^([+-]?\d{2,3})\s+(.+?)([+-]?\d{2,3})\s+(.+?)([-*]|\d{1,3})\s+(\d+)\s*$/u', $combined, $splitMatch)) {
                $sent = $splitMatch[1] . '  ' . trim($splitMatch[2]);
                $rcv = $splitMatch[3] . '  ' . trim($splitMatch[4]);
                $mlt = $splitMatch[5];
                $pts = $splitMatch[6];
            }
            // パターン2: Multiが空でPTのみ（CTESTWIN形式でMulti空欄の場合）
            // 例: "58 おぐら   59 ぐんじ             1"
            elseif (preg_match('/^([+-]?\d{2,3})\s+(.+?)([+-]?\d{2,3})\s+(\S+)\s+(\d+)\s*$/u', $combined, $splitMatch)) {
                $sent = $splitMatch[1] . '  ' . trim($splitMatch[2]);
                $rcv = $splitMatch[3] . '  ' . trim($splitMatch[4]);
                $mlt = '';
                $pts = $splitMatch[5];
            }
            // パターン3: Multi/PTがスペース区切りの場合
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

    /**
     * タブ区切りヘッダーをパース
     */
    private static function parseTabDelimitedHeader(string $headerLine): array
    {
        $columnMap = [
            'DATE(JST)' => 'date',
            'DATE'      => 'date',
            'TIME'      => 'time',
            'BAND'      => 'band',
            'MHz'       => 'band',
            'Mode'      => 'mode',
            'MODE'      => 'mode',
            'CALLSIGN'  => 'callsign',
            'SENTNo.'   => 'sent',
            'SENTNo'    => 'sent',
            'SENT'      => 'sent',
            'RCVDNo.'   => 'rcv',
            'RCVDNo'    => 'rcv',
            'RCVNo.'    => 'rcv',
            'RCVNo'     => 'rcv',
            'Multi'     => 'mlt',
            'Mlt'       => 'mlt',
            'MLT'       => 'mlt',
            'PT'        => 'pts',
            'Pt'        => 'pts',
            'Pts'       => 'pts',
            'PTS'       => 'pts',
        ];

        $headers = explode("\t", $headerLine);
        $result = [];

        foreach ($headers as $index => $header) {
            $header = trim($header);
            if (isset($columnMap[$header])) {
                $result[$columnMap[$header]] = $index;
            }
        }

        return $result;
    }

    /**
     * タブ区切り行からフィールドを抽出
     */
    private static function extractTabDelimitedFields(string $line, array $headerColumns): ?array
    {
        $fields = explode("\t", $line);

        $get = function ($key) use ($fields, $headerColumns) {
            if (!isset($headerColumns[$key])) {
                return '';
            }
            return trim($fields[$headerColumns[$key]] ?? '');
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

        // TIMEの末尾のJを除去（elog形式: "07:48J" -> "07:48"）
        $time = preg_replace('/J$/i', '', $time);

        // DATE形式の変換: "26/01/02" -> "2026-01-02"
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $date, $dm)) {
            $year = (int)$dm[1];
            $year = $year < 50 ? 2000 + $year : 1900 + $year;
            $date = sprintf('%04d-%02d-%02d', $year, (int)$dm[2], (int)$dm[3]);
        }

        if (empty($date) || empty($callsign)) {
            return null;
        }

        // SENT/RCVからRSTとNoを分離（elog形式: "56タバラ" -> RST=56, No=タバラ）
        $sentRst = '';
        $sentNo = '';
        if (preg_match('/^([+-]?\d{2,3})(.*)$/u', $sent, $sm)) {
            $sentRst = $sm[1];
            $sentNo = trim($sm[2]);
        }

        $rcvRst = '';
        $rcvNo = '';
        if (preg_match('/^([+-]?\d{2,3})(.*)$/u', $rcv, $rm)) {
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
