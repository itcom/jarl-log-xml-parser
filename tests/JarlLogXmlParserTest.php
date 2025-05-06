<?php

namespace Itcom\JarlLog\Tests;

use PHPUnit\Framework\TestCase;
use Itcom\JarlLog\JarlLogXmlParser;

class JarlLogXmlParserTest extends TestCase
{
    public function testParseSummaryAndLogs()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R1.0>
<CONTESTNAME>ALL JAコンテスト</CONTESTNAME>
<CATEGORYCODE>XAM</CATEGORYCODE>
<CATEGORYNAME>アマチュア局</CATEGORYNAME>
<CALLSIGN>JH9VIP</CALLSIGN>
<SCORE BAND=14MHz>2,0,0</SCORE>
<SCORE BAND=144MHz>11,0,0</SCORE>
<SCORE BAND=430MHz>7,0,0</SCORE>
<SCORE BAND=TOTAL>20,0,0</SCORE>
<TOTALSCORE>0</TOTALSCORE>
<ADDRESS>〒910-0808 福井県福井市舟橋１－２０４</ADDRESS>
<TEL>0776-65-1363</TEL>
<NAME>テスト　太朗</NAME>
<EMAIL>test@hoge.fuga</EMAIL>
<LICENSECLASS>第2級アマチュア無線技士</LICENSECLASS>
<POWER>100</POWER>
<POWERTYPE>定格出力</POWERTYPE>
<OPPLACE>福井県福井市</OPPLACE>
<EQUIPMENT>IC-7300(3X4B)、IC-9700(10ele YAGI x 2, 21ele F9FT x 2)</EQUIPMENT>
<COMMENTS>意見</COMMENTS>
<OATH>私は，JARL制定のコンテスト規約および電波法令にしたがい運用した結果，ここに提出するサマリーシートおよびログシートなどが事実と相違ないものであることを，私の名誉において誓います。</OATH>
<DATE>2025年5月6日</DATE>
<SIGNATURE>テスト 太朗</SIGNATURE>
</SUMMARYSHEET>
<LOGSHEET TYPE=CTESTWIN>
DATE (JST) TIME   BAND MODE  CALLSIGN      SENTNo      RCVDNo      Mlt    Pts
2025-01-01 09:20  430  SSB   JA9NOW        59  11L   59  25H   3   1
2025-01-01 09:20  430  SSB   JA9NOW        59        59  P     -   0
2025-01-01 09:20  430  SSB   JA9NOW        59  P     59        -   0
2025-01-01 09:20  430  SSB   JA9NOW        59  P     59  P     -   0
2025-01-01 09:20  430  SSB   JA9NOW        59        59        -   0
2025-01-01 09:20  430  SSB   JA9NOW        59  イシカワ     59  フクイ     -   0
</LOGSHEET>
XML;
        // Add closing tags and minimal logs
        $raw .= "\n</LOGSHEET>";

        $parsed = JarlLogXmlParser::parse($raw);

        // Assert summary
        $summary = $parsed['summary'];
        $this->assertEquals('ALL JAコンテスト', $summary['contestname']);
        $this->assertEquals('XAM', $summary['categorycode']);
        $this->assertEquals('アマチュア局', $summary['categoryname']);
        $this->assertEquals('JH9VIP', $summary['callsign']);
        $this->assertEquals('〒910-0808 福井県福井市舟橋１－２０４', $summary['address']);
        $this->assertEquals('0776-65-1363', $summary['tel']);
        $this->assertEquals('テスト　太朗', $summary['name']);
        $this->assertEquals('test@hoge.fuga', $summary['email']);
        $this->assertEquals('第2級アマチュア無線技士', $summary['licenseclass']);
        $this->assertEquals('100', $summary['power']);
        $this->assertEquals('定格出力', $summary['powertype']);
        $this->assertEquals('福井県福井市', $summary['opplace']);
        $this->assertEquals('IC-7300(3X4B)、IC-9700(10ele YAGI x 2, 21ele F9FT x 2)', $summary['equipment']);
        $this->assertEquals('意見', $summary['comments']);
        $this->assertEquals('私は，JARL制定のコンテスト規約および電波法令にしたがい運用した結果，ここに提出するサマリーシートおよびログシートなどが事実と相違ないものであることを，私の名誉において誓います。', $summary['oath']);
        $this->assertEquals('2025年5月6日', $summary['date']);
        $this->assertEquals('テスト 太朗', $summary['signature']);

        // Assert logs
        $logs = $parsed['logs'];
        $this->assertCount(6, $logs);

        $entry = $logs[0];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('11L', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('25H', $entry['rcvNo']);
        $this->assertEquals('3', $entry['mlt']);
        $this->assertEquals('1', $entry['pts']);

        $entry = $logs[1];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('P', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('0', $entry['pts']);

        $entry = $logs[2];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('P', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('0', $entry['pts']);

        $entry = $logs[3];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('P', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('P', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('0', $entry['pts']);

        $entry = $logs[4];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('0', $entry['pts']);


        $entry = $logs[5];
        $this->assertEquals('2025-01-01', $entry['date']);
        $this->assertEquals('09:20', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JA9NOW', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('イシカワ', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('フクイ', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('0', $entry['pts']);
    }
}
