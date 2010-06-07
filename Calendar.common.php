<?php

class CalendarCommon
{
    static function checkForMagicWord($string)
    {
        global $wgParser;

        $ret = $string;
        $string = str_replace("{{","",$string);
        $string = str_replace("}}","",$string);
        $string = strtolower($string);

        $string = $wgParser->getVariableValue($string);

        if(isset($string)) $ret = $string;

        return $ret;
    }

    static function datemath($dayOffset, $month, $day, $year)
    {
        $seconds = $dayOffset * 86400;
        $arr = getdate(mktime(12, 0, 0, $month, $day, $year) + $seconds);
        return array( $arr['mon'], $arr['mday'], $arr['year'] );
    }

    static function cleanWiki($text)
    {
        $text = self::swapWikiToHTML($text, "'''", "b");
        $text = self::swapWikiToHTML($text, "''", "i");
        $text = self::swapWikiToHTML($text, "<pre>", "");
        $text = self::swapWikiToHTML($text, "</pre>", "");

        return $text;
    }

    // basic tag changer for common wiki tags
    static function swapWikiToHTML($text, $tagWiki, $tagHTML)
    {
        $ret = $text;

        $lenWiki = strlen($tagWiki);
        $pos = strpos($text, $tagWiki);
        if($pos !== false){
            if($tagHTML != ""){
                $ret = substr_replace($text, "<$tagHTML>", $pos, $lenWiki);
                $ret = str_replace($tagWiki, "</$tagHTML>", $ret);
            }
            else
                $ret = str_replace($tagWiki, "", $ret);
        }

        return $ret;
    }

    static function limitText($text, $max)
    {
        if (!$max)
            return $text;

        $text = trim($text);
        if (mb_strlen($text) > $max)
            $ret = mb_substr($text, 0, $max) . "...";
        else
            $ret = $text;

        return $ret;
    }

    static function getDaysInMonth($month, $year) 
    {
        // 't' = Number of days in the given month
        return date('t', mktime(12, 0, 0, $month, 1, $year));
    }

    static function getDateArr($month, $day, $year, $hour=0, $minutes=0, $seconds=0, $add_seconds=0)
    {
        return getdate(mktime($hour, $minutes, $seconds, $month, $day, $year) + $add_seconds);
    }

    static function day_diff($date1, $date2)
    {
        if(!isset($date2))
            return 0;

        $start = mktime($date1['hours'], $date1['minutes'], $date1['seconds'], $date1['mon'], $date1['mday'], $date1['year']);
        $end = mktime($date2['hours'], $date2['minutes'], $date2['seconds'], $date2['mon'], $date2['mday'], $date2['year']);

        return ($end - $start) / 86400; //seconds
    }

    // get the offset info based on the 1st of the month
    static function wdayOffset($month, $year, $weekday)
    {
        $timestamp = mktime(12, 0, 0, $month, 1, $year);
        $max_days = date('t', $timestamp);
        $the_first = getdate($timestamp);
        $wday = $the_first["wday"];

        $offset = ($weekday - $wday) +1; //relate $wday as a negative number
        $month_offset = (7 + $offset);

        $weeks = 4;

        // this $weekday is before the 1st
        if ($offset <= 0 && ($month_offset + 28) <= $max_days)
            $weeks = 5;

        // this $weekday is after the 1st
        if ($offset > 0 && ($month_offset + 21) <= $max_days)
            $weeks = 5;

        $arr['offset'] = $offset; // delta between the 1st and the $weekday parameter(0-sun, 1-mon, etc)
        $arr['maxdays'] = $max_days; //days in month
        $arr['weeks'] = $weeks; //max weeks this weekday has

        return $arr;
    }

    static function translate($value, $key = "")
    {
        global $wgLang;

        switch($key)
        {
            case 'month':
                return $wgLang->getMonthName($value);
            case 'month-gen': //genitive case or possessive case
                return $wgLang->getMonthNameGen($value);
            case 'month_short':
                return $wgLang->getMonthAbbreviation($value);
            case 'weekday':
                return $wgLang->getWeekdayName($value);
            default:
                return wfMsg($value);
        }
        return "";
    }

    static function isWeekend($month, $day, $year){
        $dayOfWeek = date('N', mktime(12, 0, 0, $month, $day, $year));

        if($dayOfWeek > 5) return true;

        return false;
    }

    function right($value, $count){
        $value = substr($value, (strlen($value) - $count), strlen($value));
        return $value;
    }

    function left($string, $count){
        return substr($string, 0, $count);
    }

    // Local parsing and HTML rendering for individual lines of wiki markup
    static $parserOptions;
    static function parse($line, $parser = NULL)
    {
        global $wgTitle, $wgParser;
        /* Если использовать для разбора каких-либо кусков текста глобальный парсер,
           нужно передавать $clearState = false! Иначе функция parse дёргает
           Parser::clearState() и все сохранённые подстановки типа
           UNIQ35b039f153ed3bf9-h-1--QINU забываются в тексте статьи.
           Этого, между прочим, не делает даже OutputPage::parse() - а должна бы :-( */
        if (!self::$parserOptions)
        {
            self::$parserOptions = new ParserOptions();
            self::$parserOptions->setEditSection(false);
            self::$parserOptions->setTidy(false);
        }
        if (is_null($parser))
            $parser = $wgParser;
        $oldoptions = $parser->mOptions;
        $parserOutput = $parser->parse(trim($line), $parser->mTitle ? $parser->mTitle : $wgTitle, self::$parserOptions, false, $parser->mFirstCall);
        if ($oldoptions)
            $parser->mOptions = $oldoptions;
        return str_replace(array("<p>","</p>","\r","\n"), "", $parserOutput->mText);
    }
}
