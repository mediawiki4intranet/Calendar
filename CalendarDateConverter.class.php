<?php

class CalendarDateConverter
{
    function convert($newFormat, $pageName, $calName, $redirect, $go)
    {
        $search = "$pageName/$calName";
        $pages = PrefixSearch::titleSearch($search, 1000000); //search upto 1,000,000 events (no performance issue)
        $count = $erroredCount = 0;

        foreach($pages as $page) {
            $retval = false;
            $newPage = $this->convertToNewPage($page, $newFormat);

            $article = new Article(Title::newFromText($page));

            if( $newPage != '' ){

                $fromTitle = Title::newFromText($page);
                $toTitle = Title::newFromText($newPage);
                $articleNew = new Article(Title::newFromText($newPage));

                if( !$article->isRedirect() && !$articleNew->exists() ){
                    if($go)
                        $retval = $fromTitle->moveTo($toTitle, true, 'CalendarConversion', $redirect);
                    elseif($count < 10)
                        $testRun .= '&nbsp;&nbsp;' . $page . '  &rarr;&rarr;  ' . $newPage . '<br>';
                }
            }
        }
        unset($pages);

        if($go)
            $ret = "Conversion completed.";
        else
            $ret = "<b>Test Results, add '<i>go</i>' to the <i>dateConverter</i> tag to convert:</b><br>$testRun";

        return $ret;
    }

    function convertToNewPage($page, $newFormat){
        $arrPage= explode('/',$page);
        $dateStr = trim( array_pop($arrPage) ); //get last element

        $arrDateElements = explode('-',$dateStr); //arr[0]=month, arr[1]=day, arr[2]=year, arr[3]=eventid

        if(count($arrDateElements) != 4) return '';

        $eventID = array_pop($arrDateElements);

        $newDate = $this->userDateFormat( trim($arrDateElements[0]),
                                          trim($arrDateElements[1]),
                                          trim($arrDateElements[2]),
                                          trim($newFormat) );

        $newDate .= " -$eventID";

        $arrPage[] = $newDate;
        $ret = implode('/', $arrPage);

        return $ret;
    }

    function userDateFormat($month, $day, $year, $format=''){

        if($format == '') $format = 'YYYYMMDD'; //default

        $format = str_ireplace('YYYY',$year,$format);
        $format = str_ireplace('MM', str_pad($month, 2, '0', STR_PAD_LEFT), $format);
        $format = str_ireplace('DD', str_pad($day, 2, '0', STR_PAD_LEFT), $format);
        $format = str_ireplace('D',$day,$format);

        if( stripos($format,'SM') !== false || stripos($format,'LM') !== false ){
            $format = str_ireplace('SM', CalendarCommon::translate($month, 'month_short'), $format);
            $format = str_ireplace('LM', CalendarCommon::translate($month, 'month'), $format);
        }else{
            $format = str_ireplace('M',$month,$format);
        }

        return $format;
    }
}
