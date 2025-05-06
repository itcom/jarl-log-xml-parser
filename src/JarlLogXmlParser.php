<?php

namespace Itcom\JarlLog;

class JarlLogXmlParser
{
    /**
     * @param string $rawText
     * @return array{
     *   summary: array<string,string>,
     *   logs: array<int,array{
     *     date: string,
     *     time: string,
     *     band: string,
     *     mode: string,
     *     callsign: string,
     *     sentRst: string,
     *     sentNo: string|null,
     *     rcvRst: string,
     *     rcvNo: string|null,
     *     mlt: string,
     *     pts: string
     *   }>
     * }
     */
    public static function parse(string $rawText): array
    {
        $result = ['summary' => [], 'logs' => []];

        // Parse SUMMARYSHEET
        if (preg_match('/<SUMMARYSHEET\b[^>]*>(.*?)<\/SUMMARYSHEET>/si', $rawText, $m)) {
            $block = $m[1] ?? '';
            if (preg_match_all('/<([A-Z]+)>([^<]*)<\/\1>/i', $block, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $node) {
                    $result['summary'][strtolower($node[1])] = trim($node[2]);
                }
            }
        }

        // Parse LOGSHEET
        if (preg_match('/<LOGSHEET\b[^>]*>(.*?)<\/LOGSHEET>/si', $rawText, $m2)) {
            $content = trim($m2[1] ?? '');
            $lines = preg_split('/\r?\n/', $content);
            array_shift($lines); // remove header

            // Pattern captures optional sentNo and rcvNo correctly regardless of content
            $pattern = '/^
                (?P<date>\d{4}-\d{2}-\d{2})\s+
                (?P<time>\d{2}:\d{2})\s+
                (?P<band>\d+(?:\.\d+)?)\s+
                (?P<mode>\w+)\s+
                (?P<callsign>\S+)\s+
                (?P<sentRst>\d{2,3})
                (?:\s+(?P<sentNo>\S+))?\s+
                (?P<rcvRst>\d{2,3})
                (?:\s+(?P<rcvNo>\S+))?\s+
                (?P<mlt>\S+)\s+
                (?P<pts>\d+)
            $/xu';

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match($pattern, $line, $f)) {
                    $result['logs'][] = [
                        'date'     => $f['date'],
                        'time'     => $f['time'],
                        'band'     => $f['band'],
                        'mode'     => $f['mode'],
                        'callsign' => $f['callsign'],
                        'sentRst'  => $f['sentRst'],
                        'sentNo'   => $f['sentNo'] ?? '',
                        'rcvRst'   => $f['rcvRst'],
                        'rcvNo'    => $f['rcvNo'] ?? '',
                        'mlt'      => $f['mlt'],
                        'pts'      => $f['pts'],
                    ];
                }
            }
        }

        return $result;
    }
}
