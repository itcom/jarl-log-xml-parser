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
DATE (JST) TIME   BAND MODE  CALLSIGN      SENTNo          RCVDNo          Mlt    Pts
2025-01-01 09:20  430  SSB   JA9NOW        59  11L         59  25H         3      1
2025-01-01 09:20  430  SSB   JA9NOW        59              59  P           -      0
2025-01-01 09:20  430  SSB   JA9NOW        59  P           59              -      0
2025-01-01 09:20  430  SSB   JA9NOW        59  P           59  P           -      0
2025-01-01 09:20  430  SSB   JA9NOW        59              59              -      0
2025-01-01 09:20  430  SSB   JA9NOW        59  イシカワ       59  フクイ         -      0
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

    /**
     * HLTST形式のログをテスト
     */
    public function testParseHltstFormat()
    {
        // HLTST形式のサンプルデータ
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>QSOパーティ コンテスト</CONTESTNAME>
<CATEGORYCODE>30</CATEGORYCODE>
<CALLSIGN>JJ1TZB</CALLSIGN>
<TOTALSCORE>171</TOTALSCORE>
<ADDRESS>181-0001 東京都三鷹市下連雀５－１０－８</ADDRESS>
<TEL>0422-43-4302</TEL>
<n>田中 太郎</n>
<EMAIL>jj1tzb@jarl.com</EMAIL>
<POWER>50</POWER>
</SUMMARYSHEET>
<LOGSHEET TYPE=HLTST8.5.0>
DATE(JST)  TIME   MHz   Mode  CALLSIGN   SENTNo      RCVNo       Multi  PT
--------------------------------------------------------------------------
2026-01-02 09:12   430  FM    JK1LQB     59  みたかばら59  なかとら  -      1 
2026-01-02 09:16   430  FM    7K2PAB     59  みたかばら59  はやし    -      1 
2026-01-02 10:15   430  SSB   JF1HIS/1   59  みたかばら59  しばた    -      1 
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        // Assert summary
        $summary = $parsed['summary'];
        $this->assertEquals('QSOパーティ コンテスト', $summary['contestname']);
        $this->assertEquals('JJ1TZB', $summary['callsign']);
        $this->assertEquals('田中 太郎', $summary['name']);

        // Assert logs
        $logs = $parsed['logs'];
        $this->assertCount(3, $logs);

        // 1行目
        $entry = $logs[0];
        $this->assertEquals('2026-01-02', $entry['date']);
        $this->assertEquals('09:12', $entry['time']);
        $this->assertEquals('430', $entry['band']);
        $this->assertEquals('FM', $entry['mode']);
        $this->assertEquals('JK1LQB', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('みたかばら', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('なかとら', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
        $this->assertEquals('1', $entry['pts']);

        // 2行目
        $entry = $logs[1];
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('みたかばら', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('はやし', $entry['rcvNo']);

        // 3行目 (SSB)
        $entry = $logs[2];
        $this->assertEquals('JF1HIS/1', $entry['callsign']);
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('しばた', $entry['rcvNo']);
    }

    /**
     * タブ区切り形式（elog）のテスト
     */
    public function testParseTabDelimitedElogFormat()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>NEW YEAR PARTY</CONTESTNAME>
<CALLSIGN>JF1HIS/1</CALLSIGN>
<n>田部 和規</n>
</SUMMARYSHEET>
<LOGSHEET TYPE=elog>
DATE(JST)	TIME	BAND	MODE	CALLSIGN	SENTNo.	RCVDNo.	Pt
26/01/02	07:48J	432.660	FM	JA1TRQ	56タバラ	53ヤマダ	1
26/01/02	09:44J	430.210	SSB	JS2GSW	59タバラ	59イトウ	2
26/01/07	11:52J	432.660	FM	JE1AZB/1	53タバラ	53ツノダ	1
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        // Summary
        $this->assertEquals('NEW YEAR PARTY', $parsed['summary']['contestname']);
        $this->assertEquals('JF1HIS/1', $parsed['summary']['callsign']);
        $this->assertEquals('田部 和規', $parsed['summary']['name']);

        // Logs
        $this->assertCount(3, $parsed['logs']);

        // 1行目: FM、短縮日付 YY/MM/DD
        $entry = $parsed['logs'][0];
        $this->assertEquals('2026-01-02', $entry['date']);
        $this->assertEquals('07:48', $entry['time']);
        $this->assertEquals('432.660', $entry['band']);
        $this->assertEquals('FM', $entry['mode']);
        $this->assertEquals('JA1TRQ', $entry['callsign']);
        $this->assertEquals('56', $entry['sentRst']);
        $this->assertEquals('タバラ', $entry['sentNo']);
        $this->assertEquals('53', $entry['rcvRst']);
        $this->assertEquals('ヤマダ', $entry['rcvNo']);
        $this->assertEquals('1', $entry['pts']);

        // 2行目: SSB
        $entry = $parsed['logs'][1];
        $this->assertEquals('SSB', $entry['mode']);
        $this->assertEquals('JS2GSW', $entry['callsign']);
        $this->assertEquals('イトウ', $entry['rcvNo']);

        // 3行目: ポータブル局
        $entry = $parsed['logs'][2];
        $this->assertEquals('JE1AZB/1', $entry['callsign']);
    }

    /**
     * カンマ区切り形式（CSV）のテスト - 日付形式 YY/MM/DD
     */
    public function testParseCsvFormatShortDate()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>NEW YEAR PARTY</CONTESTNAME>
<CALLSIGN>JF1HIS/1</CALLSIGN>
</SUMMARYSHEET>
<LOGSHEET TYPE="テキスト">
DATE(JST),TIME,BAND,MODE,CALLSIGN,SENTNo.,RCVDNo.,JCCG#,Pt
26/01/02,09:01J,432.660,FM,JG1DVW,57タバラ,59イシカワ,1203,1
26/01/02,09:04J,432.660,FM,JK1TAE,57タバラ,57ニシムラ,100112,1
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        $this->assertCount(2, $parsed['logs']);

        $entry = $parsed['logs'][0];
        $this->assertEquals('2026-01-02', $entry['date']);
        $this->assertEquals('09:01', $entry['time']);
        $this->assertEquals('JG1DVW', $entry['callsign']);
        $this->assertEquals('57', $entry['sentRst']);
        $this->assertEquals('タバラ', $entry['sentNo']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('イシカワ', $entry['rcvNo']);

        $entry = $parsed['logs'][1];
        $this->assertEquals('JK1TAE', $entry['callsign']);
        $this->assertEquals('ニシムラ', $entry['rcvNo']);
    }

    /**
     * カンマ区切り形式（CSV）のテスト - 日付形式 YYYY/M/D
     */
    public function testParseCsvFormatLongDate()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>NEW YEAR PARTY</CONTESTNAME>
<CALLSIGN>JF1HIS/1</CALLSIGN>
</SUMMARYSHEET>
<LOGSHEET TYPE="テキスト">
DATE(JST),TIME,BAND,MODE,CALLSIGN,SENTNo.,RCVDNo.,JCCG#,Pt
2026/1/2,09:01J,432.66,FM,JG1DVW,57タバラ,59イシカワ,1203,1
2026/1/7,11:52J,432.66,FM,JE1AZB/1,53タバラ,53ツノダ,11003C,1
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        $this->assertCount(2, $parsed['logs']);

        $entry = $parsed['logs'][0];
        $this->assertEquals('2026-01-02', $entry['date']);
        $this->assertEquals('09:01', $entry['time']);

        $entry = $parsed['logs'][1];
        $this->assertEquals('2026-01-07', $entry['date']);
        $this->assertEquals('JE1AZB/1', $entry['callsign']);
    }

    /**
     * ZLOG固定幅形式のテスト（RST/Noが別カラム）
     */
    public function testParseZlogFixedWidthFormat()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>NEW YEAR PARTY</CONTESTNAME>
<CALLSIGN>JH1ABC</CALLSIGN>
</SUMMARYSHEET>
<LOGSHEET TYPE=ZLOG.ALL>
Date       Time  Callsign    RSTs ExSent RSTr ExRcvd  Mult  Mult2 MHz  Mode Pt Memo
2026/01/02 09:31 JH0JRG       59  ｲﾏｲｽﾞﾐ  59  シミズ  -     -     430  SSB  0  
2026/01/02 09:42 7M1PKZ       59  ｲﾏｲｽﾞﾐ  59  オカニワ-     -     430  SSB  0  
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        $this->assertCount(2, $parsed['logs']);

        // 1件目
        $entry = $parsed['logs'][0];
        $this->assertEquals('2026/01/02', $entry['date']);
        $this->assertEquals('09:31', $entry['time']);
        $this->assertEquals('JH0JRG', $entry['callsign']);
        $this->assertEquals('59', $entry['sentRst']);
        $this->assertEquals('59', $entry['rcvRst']);
        $this->assertEquals('シミズ', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);

        // 2件目：Multに侵食したケース
        $entry = $parsed['logs'][1];
        $this->assertEquals('7M1PKZ', $entry['callsign']);
        $this->assertEquals('オカニワ', $entry['rcvNo']);
        $this->assertEquals('-', $entry['mlt']);
    }

    /**
     * 半角カナが含まれるケースのテスト
     */
    public function testParseHalfwidthKatakana()
    {
        $raw = <<<XML
<SUMMARYSHEET VERSION=R2.1>
<CONTESTNAME>NEW YEAR PARTY</CONTESTNAME>
<CALLSIGN>JH1ABC</CALLSIGN>
</SUMMARYSHEET>
<LOGSHEET TYPE=ZLOG.ALL>
Date       Time  Callsign    RSTs ExSent RSTr ExRcvd  Mult  Mult2 MHz  Mode Pt Memo
2026/01/02 09:31 JH0JRG       59  ｲﾏｲｽﾞﾐ  59  ｼﾐｽﾞ   -     -     430  SSB  0  
</LOGSHEET>
XML;

        $parsed = JarlLogXmlParser::parse($raw);

        $this->assertCount(1, $parsed['logs']);
        $entry = $parsed['logs'][0];
        $this->assertEquals('ｲﾏｲｽﾞﾐ', $entry['sentNo']);
        $this->assertEquals('ｼﾐｽﾞ', $entry['rcvNo']);
    }
}
