<?php

/**
 * Class:   CalendarArticle
 * Purpose: Structure to hold article/event data and then store into an array for future retrieval
 */

class CalendarArticle
{
    var $day = "";
    var $month = "";
    var $year = "";
    var $page = ""; // full wiki page name
    var $eventname = ""; // 1st line of body; unformated plain text
    var $body = ""; // everything except line 1 in the event page
    var $html = ""; // html link displayed in calendar
    var $isImage = false;

    function CalendarArticle($month, $day, $year){
        $this->month = $month;
        $this->day = $day;
        $this->year = $year;
    }
}

/**
 * Class: CalendarArticles
 * Purpose: Contains most of the functions to retrieve article
 *          information. It also is the primary container for
 *          the main array of class::CalendarArticle articles
 */

class CalendarArticles
{
    private $arrArticles = array();
    public $wikiRoot = "";
    private $arrTimeTrack = array();
    private $arrStyle = array();

    // build an event based on the 1st line or ==event== type
    public function addArticle($month, $day, $year, $page){
        $lines = array();
        $temp = "";
        $head = array();

        $article = new Article(Title::newFromText($page));
        if(!$article->exists()) return "";

        $redirectCount = 0;

        if( $article->isRedirect() && $this->setting('disableredirects') ) return '';

         while($article->isRedirect() && $redirectCount < 10){
             $redirectedArticleTitle = Title::newFromRedirect($article->getContent());
             $article = new Article($redirectedArticleTitle);
             $redirectCount += 1;
         }

        $body = $article->fetchContent(0,false,false);

        if(strlen(trim($body)) == 0) return "";

        $lines = split("\n",$body);
        $cntLines = count($lines);

        // dont use section events... only line 1 of the page
        if($this->setting('disablesectionevents')){
            $key = $lines[0]; //initalize the key
            $head[$key] = "";
            $cntLines = 0;
        }

        for($i=0; $i<$cntLines; $i++){
            $line = $lines[$i];
            if(substr($line,0,2) == '=='){
                $arr = split("==",$line);
                $key = $arr[1];
                $head[$key] = ""; $temp = "";
            }
            else{
                if($i == 0){ // $i=0  means this is a one event page no (==event==) data
                    $key = $line; //initalize the key
                    $head[$key] = "";
                }
                else{
                    $temp .= "$line\n";
                    $head[$key] = CalendarCommon::cleanWiki($temp);
                }
            }
        }

        while (list($event,$body) = each($head)){
            $this->buildEvent($month, $day, $year, trim($event), $page, $body);
        }
    }

    // this is the main logic/format handler; the '$event' is checked for triggers here...
    public function buildEvent($month, $day, $year, $event, $page, $body, $eventType='addevent', $bRepeats=false){

        // user triggered yearly repeat event...
        if(substr($event,0,2) == '##'){
            $event = trim(str_replace("##", "", $event));
            $this->buildRecurrenceEvent($month, $day, $year, $event, $page);
        }

        //check for repeating events
        $arrEvent = split("#",$event);
        if( isset($arrEvent[1]) && ($arrEvent[0] != 0) && $this->setting('enablerepeatevents') )
        {
            for($i=0; $i<$arrEvent[0]; $i++)
            {
                $this->add($month, $day, $year, $arrEvent[1], $page, $body, false, true);
                list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);
            }
        }
        else
            $this->add($month, $day, $year, $event, $page, $body, $eventType, $bRepeats);
    }

    static function eventname_sort($a, $b)
    {
        return strcmp($a->eventname, $b->eventname);
    }

    // this is the MAIN function that returns the events to the calendar...
    // there shouldn't be ANY formatting or logic done here....
    public function getArticleLinks($month, $day, $year)
    {
        $ret = $list = "";
        $bFound = false;

        // not using 'templates' array, but the purpose was to put all these events above other events...
        if(isset($this->arrArticles['templates']))
            foreach($this->arrArticles['templates'] as $cArticle)
                if($cArticle->month == $month && $cArticle->day == $day && $cArticle->year == $year)
                    $ret .= $cArticle->html;

        // we want to format the normal 'add event' items in 1 table cell
        // this creates less spacing and creates a better <ul>
        $head = "<tr cellpadding=0 cellspacing=0 ><td class='calendarTransparent singleEvent'>";
        $head .= "<ul class='bullets'>";
        $foot = "</ul></td></tr>";

        if(isset($this->arrArticles['events']))
        {
            // sort them by name
            usort($this->arrArticles['events'], 'WikiCalendar::eventname_sort');
            foreach($this->arrArticles['events'] as $cArticle)
            {
                if (preg_match('/(\d\d:\d\d(:\d\d)?)[^\w:]*(\d\d:\d\d(:\d\d)?)/s', $cArticle->eventname, $m))
                {
                    $min = $m[1];
                    $max = $m[3];
                    if (!$m[2])
                        $min .= ':00';
                    if (!$m[4])
                        $max .= ':00';
                    if (strcmp($min, $max) > 0)
                    {
                        $t = $min;
                        $min = $max;
                        $max = $t;
                    }
                    $this->index[sprintf("%04d-%02d-%02d", $cArticle->year, $cArticle->month, $cArticle->day)][] = array($min, $max);
                }
                if ($cArticle->month == $month && $cArticle->day == $day && $cArticle->year == $year)
                {
                    $n = $cArticle->eventname;
                    if (($p = strpos($n, '[[')) === false)
                    {
                        // add a wiki-link
                        $n = '[[' . $cArticle->page . '|' . $n . ']]';
                    }
                    elseif (($p1 = strpos($n, ':', $p)) !== false &&
                        $wgLang->getNsIndex(substr($n, $p+2, $p1-$p-2)) == NS_IMAGE &&
                        ($p1 = strpos($n, ']]', $p)) !== false)
                    {
                        // add link= to image
                        $n = substr($n, 0, $p1) . '|link=' . $cArticle->page . substr($n, $p1);
                    }
                    $n .= "<br />";
                    $list .= CalendarCommon::parse($n);
                    $bFound = true;
                }
            }
        }

        if($bFound)
            $ret .= $head . $list . $foot;

        return $ret;
    }

    public function buildSimpleEvent($month, $day, $year, $event, $body, $page)
    {
        $cArticle = new CalendarArticle($month, $day, $year);
        $temp = $this->checkTimeTrack($month, $day, $year, $event, '');
        $temp = trim($temp);
        $summaryLength = $this->setting('enablesummary',false);

        $html_link = $this->articleLink('', $temp, true);

        // format for different event types
        $class = "baseEvent ";
        $class = trim($class);

        $cArticle->month = $month;
        $cArticle->day = $day;
        $cArticle->year = $year;
        $cArticle->page = $page;
        $cArticle->eventname = $event;
        $cArticle->body = $body;

        // this will be the main link displayed in the calendar....
        $cArticle->html = "<span class='$class'>$html_link</span><br/>" . CalendarCommon::limitText($cArticle->body, $summaryLength);

        $this->arrArticles['events'][] = $cArticle;
    }

    // when the calendar loads, we want to put all the template events into memory
    // so we dont have to read the wiki db for every day
    public function addTemplate($month, $year, $pagename){
        $displayText = "";
        $arrEvent = array();

        $articleName = $pagename . "/" . $month . "-" . $year . " -Template";
        $article = new Article(Title::newFromText($articleName));

        if (!$article->exists()) return "";

        $displayText  = $article->fetchContent(0,false,false);

        $arrAllEvents=split(chr(10),$displayText);
        if (count($arrAllEvents) > 0){
            for($i=0; $i<count($arrAllEvents); $i++){
                $arrEvent = split("#",$arrAllEvents[$i]);

                if(!isset($arrEvent[1])) continue;//skip

                if(strlen($arrEvent[1]) > 0){
                    $day = $arrEvent[0];
                    $arrRepeat = split("-",$arrEvent[0]);

                    if(count($arrRepeat) > 1){
                        $day = $arrRepeat[0];
                        while($day <= $arrRepeat[1]){
                            $this->buildEvent($month, $day, $year,  $arrEvent[1], $articleName, "", "templates", true);
                            $day++;
                        }
                    }else{
                        $this->buildEvent($month, $day, $year, $arrEvent[1], $articleName, "", "templates");
                    }
                }
            }
        }
    }

    // this is the FINAL stop; the events are stored here then pulled out
    // and displayed later via "getArticleLinks()"...
    private function add($month, $day, $year, $eventname, $page, $body, $eventType='addevent', $bRepeats=false){
        // $eventType='default' -- addevent
        // $eventType='recurrence'
        // $eventType='template'
        global $wgParser;

        $cArticle = new CalendarArticle($month, $day, $year);
        $temp = $this->checkTimeTrack($month, $day, $year, $eventname, $eventType);
        $temp = trim($temp);

        // lets get the body char limit
        $summaryLength = $this->setting('enablesummary',false);

        $html_link = $this->articleLink($page, $temp);

        // format for different event types
        $class = "baseEvent ";
        if($bRepeats) $class .= "repeatEvent ";
        if($eventType == "recurrence") $class .= "recurrenceEvent ";
        $class = trim($class);

        $cArticle->month = $month;
        $cArticle->day = $day;
        $cArticle->year = $year;
        $cArticle->page = $page;
        $cArticle->eventname = $temp;
        $cArticle->body = $body;

        $cArticle->isImage = $eventType;

        // wik-a-fi the $body; however, cut off text could cause html issues... so try to
        // keep all required body wiki/html to the top
        $parsedBody = $wgParser->recursiveTagParse( CalendarCommon::limitText($cArticle->body, $summaryLength) );

        // this will be the main link displayed in the calendar....
        $cArticle->html = "<span class='$class'>$html_link</span><br/>" . $parsedBody;

        $this->arrArticles['events'][] = $cArticle;
    }

    // this function checks a template event for a time trackable value
    private function checkTimeTrack($month, $day, $year, $event, $eventType){

        if((stripos($event,"::") === false) || $this->setting('disabletimetrack'))
            return $event;

        $arrEvent = split("::", $event);

        $arrType = split(":",$arrEvent[1]);
        if(count($arrType) == 1)
            $arrType = split("-",$arrEvent[1]);

        if(count($arrType) != 2) return $event;

        $type = trim(strtolower($arrType[0]));

        // we only want the displayed calendar year totals
        if($this->year == $year){
            if($eventType=='templates')
                $this->arrTimeTrack[$type.' (y)'][] = $arrType[1];
            else
                $this->arrTimeTrack[$type.' (m)'][] = $arrType[1];
        }

        //piece together any prefixes that the code may have added - like (r) for repeat events
        $ret = $arrType[0] . " <i>-(track)</i>";

        return $ret;
    }

    public function buildTrackTimeSummary(){

        if($this->setting('disabletimetrack')) return "";

        $ret = "";
        $cntValue = count($this->arrTimeTrack);

        if($cntValue == 0) return "";

        $cntHead = split(",", $this->setting('timetrackhead',false));
        $linktitle = "Time summaries of time specific enties. Prefix events with :: to track time values.";

        $html_head = "<hr><table title='$linktitle' width=15% border=1 cellpadding=0 cellspacing=0><th>$cntHead[0]</th><th>$cntHead[1]</th>";
        $html_foot = "</table><small>"
            . "(m) - total month only; doesn't add to year total <br/>"
            . "(y) - total year; must use monthly templates<br/></small><br>";

        while (list($key,$val) = each($this->arrTimeTrack)) {
            $ret .= "<tr><td align='center'>$key</td><td align='center'>" . array_sum($this->arrTimeTrack[$key]) . "</td></tr>";
        }

        return $html_head . $ret . $html_foot;
    }

    // find the number of current events and "build" the <add event> link
    public function buildAddEventLink($month, $day, $year, $text="")
    {
        global $wgOut;

        if($day < 1) return "";
        $section_new = '';

        if($this->setting('disableaddevent') && strlen($text) == 0) return "";
        if($this->setting('disableaddevent') && strlen($text) > 0) return $day;

        $articleName = ""; // the name of the article to check for
        $articleCount = 1; // the article count

        $date = sprintf("%04d-%02d-%02d", $year, $month, $day);

        $argv = array('action' => 'edit');
        // if we're using multi-event mode, then default to section=new
        if ($this->setting('usesectionevents'))
        {
            $sect = true;
            $argv['section'] = 'new';
        }
        $articleName = $this->getNextAvailableArticle($this->calendarPageName, $date);
        $title = Title::newFromText($articleName);

        $html = '';
        // addeventsbyhour = 8..21 by default
        if ($range = $this->setting('addeventsbyhour'))
        {
            list($min, $max) = split('..', $range);
            if (!$max || $min < 0 || $max > 23)
                list($min, $max) = array(8, 21);
            $min = intval($min);
            $max = intval($max);
            $mid = intval(($min+$max) / 2);
            // display separate links for adding event to each hour
            foreach (range($min, $max) as $h)
            {
                if (!$sect)
                {
                    $articleName = $this->getNextAvailableArticle($this->calendarPageName, $date.sprintf(" %02d", $h));
                    $title = Title::newFromText($articleName);
                }
                // JS and form POST is needed here because MW does not fill edit fields from GET requests
                $onclick = sprintf("wikiaddevent('%s',".($sect ? "'wpSummary'" : "'wpTextbox1'").",'%02d:00-%02d:00')", $title->getFullURL($argv), $h, $h+1);
                $used = $this->indexHourUsed($date, $h);
                $html .= '<a href="javascript:void(0)" '.($used ? 'class="calendarUsed" ' : '').'onclick="'.htmlspecialchars($onclick,ENT_QUOTES).'">'.sprintf("%02d",$h).'</a> ';
                if ($h == $mid)
                    $html .= '<br />';
            }
            // Add JS source if it is not present already
            if (strpos($wgOut->mScripts, 'Calendar.addevent.js') === false)
            {
                global $wgScriptPath;
                $wgOut->addScriptFile($wgScriptPath.'/extensions/Calendar/Calendar.addevent.js');
            }
        }
        else
        {
            // Display only one link: "Add event"
            if ($text == "")
                $text = CalendarCommon::translate("add_event");
            $tip = CalendarCommon::translate('add_event_tip');
            if ($this->setting('weekofyear'))
                $tip .= " (wk:" . $this->getWeekOfTheYear($month,$day,$year,true) . ")";
            $html = '<a title="'.$tip.'" href="' . $title->getFullURL($argv).'">'.$text.'</a>';
        }
        return $html;
    }

    public function indexHourUsed($date, $h)
    {
        if (!$this->index[$date])
            return false;
        foreach ($this->index[$date] as $i)
            if (intval(substr($i[0], 0, 2)) <= $h &&
                intval(substr($i[1], 0, 2)) >= $h && strcmp($i[1], sprintf("%02d:00:00", $h)) > 0)
                return true;
        return false;
    }

    public function getNextAvailableArticle($page, $date, $event_zero = false)
    {
        $page = $page.'/'.$date;

        // for ical option and setting all icals to Event -0 (== event ==) style
        if($event_zero)
            return $page;

        $max_articles = $this->setting('maxdailyevents', false);

        // bump up the max for iCal imports...but not to much in case of a runaway
        // we also want to ignore the inforced 'usesectionevents'..however, the
        // calendar will still only display the 'maxdailyevents' value
        if ($this->setting('ical'))
            $max_articles += 5;
        elseif ($this->setting('usesectionevents'))
            return $page;

        // dont care about the articles here, just need to get next available article
        $page .= "_N";
        $next_article = 1;
        while ($next_article < $max_articles && /* limit */
            ($article = new Article(Title::newFromText($page . sprintf("%02d", $next_article)))) && /* create article */
            $article->exists() && /* article exists */
            strlen($article->fetchContent(0, false, false)) > 0) /* article is non-empty */
            $next_article++;

        return $page . sprintf("%02d", $next_article);
    }

    function readStylepage(){
        $articleName = $this->calendarPageName . "/" . "style";
        $article = new Article(Title::newFromText($articleName));

        if ($article->exists()){
            $displayText = $article->fetchContent(0,false,false);
            $this->arrStyle = split("\n", $displayText);
        }
    }

    public function getConfig($pagename){

        $params = array();

        $articleName = "$pagename/config";
        $article = new Article(Title::newFromText($articleName));

        if ($article->exists()){
            $body  = $article->fetchContent(0,false,false);
            $body = str_replace("\"", "", $body);

            $arr = split("\n", $body);
            $cnt = count($arr);

            for($i=0; $i<$cnt; $i++){
                $arrParams = split("=", $arr[$i]);
                $key = trim($arrParams[0]);

                if($key != 'useconfigpage'){        // we dont want users to lock themselves out of the config page....
                    if(count($arrParams) == 2)
                        $params[$key] = trim($arrParams[1]); // we have both $key and $value
                    else
                        $params[$key] = $key; // init the value with itself if $value is null
                }
            }
        }
        return $params;
    }

    // returns the link for an article, along with summary in the title tag, given a name
    private function articleLink($title, $text, $noLink=false){
        if(strlen($text)==0) return "";
        $arrText = $this->buildTextAndHTMLString($text);
        $style = $arrText[2];

        //locked links
        if($this->setting('disablelinks') || $noLink)
            $ret = "<span $style>" . $arrText[1] . "</span>";
        else
            if($this->setting('defaultedit'))
                $ret = "<a $style title='$arrText[0]' href='" . $this->wikiRoot . wfUrlencode($title) . "&action=edit'>$arrText[1]</a>";
            else
                $ret = "<a $style title='$arrText[0]' href='" . $this->wikiRoot . wfUrlencode($title)  . "'>$arrText[1]</a>";

        return $ret;
    }

    private function buildTextAndHTMLString($string){

        $string = CalendarCommon::cleanWiki($string);
        $htmltext = $string;
        $plaintext = strip_tags($string);
        $charlimit = $this->setting('charlimit',false);

        if(mb_strlen($plaintext) > $charlimit) {
            $temp = mb_substr($plaintext,0,$charlimit) . "..."; //plaintext
            $ret[0] = $plaintext; //full plain text
            $ret[1] = str_replace($plaintext, $temp, $htmltext); //html
            $ret[2] = ""; //styles
        }
        else{
            $ret[0] = $plaintext; //full plain text
            $ret[1] = $htmltext;
            $ret[2] = ""; //styles
        }

        if(!$this->setting('disablestyles'))
            $ret[2] = $this->buildStyleBySearch($plaintext);

        return $ret;
    }



    private function buildStyleBySearch($text){

        $stylePage = '';

        // set default style if available, if not... use std windows defaults
        $defaultStyle = $this->setting('style', false);

        for($i=0; $i < count($this->arrStyle); $i++){
            $arr = split("::", $this->arrStyle[$i]);
            $cnt = count($arr);

            if(stripos($text, $arr[0]) !== false) {
                $stylePage = trim($arr[1]);
            }
        }

        //return "style='" . str_replace("'", "", "$defaultStyle;$stylePage") . "' ";
        return "style='$defaultStyle;$stylePage' ";
    }

    // creates a new page and populates it as required
    function createNewPage($page, $event, $description, $summary){
        $article = new Article(Title::newFromText($page));

        $event = $event . "\n\n" . $description;

        $article->doEdit($event, EDIT_NEW);
    }

    function createNewMultiPage($page, $event, $description, $summary, $overwrite=false){
        $article = new Article(Title::newFromText($page));
        $bExists = $article->exists();

        $event = "==$event==\n\n" . $description;

        if($bExists){
            if($overwrite){
                $article->doEdit($body.$event, $summary, EDIT_UPDATE);
            }
            else{
                $body  = trim($article->fetchContent(0,false,false));
                if(strlen($body) > 0) $body = "$body\n\n";
                $article->doEdit($body.$event, $summary, EDIT_UPDATE);
            }
        }
        else{
            $article->doEdit($event, $summary, EDIT_NEW);
        }
    }

    private function buildRecurrenceEvent($month, $day, $year, $event, $page)
    {
        wfProfileIn(__METHOD__);

        $recurrence_page = "$this->calendarPageName/recurrence";

        $article = new Article(Title::newFromText($page));
        $bExists = $article->exists();

        if($bExists){
            $article->doEdit('', 'recurrence event moved...', EDIT_UPDATE);
            unset($article);
        }

        $rrule = "RRULE:FREQ=YEARLY;INTERVAL=1"
            . ";BYMONTH=$month"
            . ";DAY=$day"
            . ";SUMMARY=$event";

        $this->updateRecurrence($recurrence_page, $rrule, $event, 'recurrence update');
        $this->invalidateCache = true;

        wfProfileOut(__METHOD__);
    }

    function updateRecurrence($page, $rrule, $event, $summary, $overwrite=false){
        $article = new Article(Title::newFromText($page));
        $bExists = $article->exists();

        $ret = 0;
        $rrule = trim($rrule);

        if($bExists){
            if($overwrite){
                $article->doEdit("$rrule", $summary, EDIT_UPDATE);
                $ret = 1;
            }
            else{
                $body  = trim($article->fetchContent(0,false,false));
                if((stripos($body, $rrule) === false)){     // lets not re-add duplicate rrule lines
                    $article->doEdit("$body\n" . "$rrule", $summary, EDIT_UPDATE);
                    $ret = 1;
                }
            }
        }
        else{
            $article->doEdit("$event", $summary, EDIT_NEW);
            $ret = 1;
        }

        return $ret;
    }

    // RRULE:FREQ=YEARLY;INTERVAL=1;BYMONTH=10;BYDAY=2MO;MONTH=10;DAY=14;SUMMARY=Columbus Day
    public function addVCalEvents($page, $year, $month){
        $arrRRULES = array();

        $ical_short_day = array("SU"=>0,"MO"=>1,"TU"=>2,"WE"=>3,"TH"=>4,"FR"=>5,"SA"=>6);

        $articleName = "$page/recurrence";
        $article = new Article(Title::newFromText($articleName));
        $bExists = $article->exists();

        if($bExists){
            $body  = trim($article->fetchContent(0,false,false));
            $arrRRULES = $this->convertRRULEs($body);
        } else return;

        foreach($arrRRULES as $rules){

            $bExpired = false;
            if(isset($rules['UNTIL'])){
                $bExpired = $this->checkExpiredRRULE($rules['UNTIL']);
            }

            if($bExpired) continue; // skip the rest of the current loop iteration

            if($rules['FREQ'] == 'YEARLY' && !isset($rules['BYDAY']) && $rules['BYMONTH'] == $month){ //std sameday recurrence
                $this->buildEvent($month, $rules['DAY'], $year, $rules['SUMMARY'], $articleName, "", 'recurrence');
            }
            else if ($rules['FREQ'] == 'YEARLY' && isset($rules['BYDAY']) && $rules['BYMONTH'] == $month){
                $num = $rules['BYDAY'];

                // parse the ical format for BYDAY (1MO, 4TH, 2WE, etc)
                settype($num, 'integer'); //get the numeric value of BYDAY
                $ical_weekday = str_replace($num, "", $rules['BYDAY']); //get the weekday text value of BYDAY
                $day = $ical_short_day[$ical_weekday]; // take the text and get the 0-6 numeric value (SU=0, MO=1, etc)

                $wday_info = CalendarCommon::wdayOffset($month,$year,$day);
                $offset = $wday_info['offset'];

                // if we have something like -1SU; then handle it here...
                if($num < 0)
                    $num = ($wday_info['weeks'] +1) + $num;

                // kick back the week count if needed
                if($offset > 0 && $num != 0) $num--;

                $theday = $offset + (7 * $num);
                $this->buildEvent($month, $theday, $year, $rules['SUMMARY'], $articleName, "", 'recurrence');
            }
        }
        unset($rules);
    }

    // filter out RRULE-'UNTIL' expired events
    function checkExpiredRRULE($date){

        $bRet = false;

        $expire_year = substr($date,0,4);
        $expire_month = substr($date,4,2);

        if($this->year > $expire_year){
            $bRet = true;
        }
        else if($this->year == $expire_year){
            if($this->month > $expire_month){
                $bRet = true;
            }
        }

        return $bRet;
    }

    // converts an RRULE line into an easy to use 2d-array
    function convertRRULEs($rrules){
        $arr_rrules = split("RRULE:", $rrules);

        $events = array();
        array_shift($arr_rrules); //1st array[0] is garbage because RRULE: in position 0(1st)

        foreach($arr_rrules as $rule){
            $arr_properties = split(";", $rule);
            foreach($arr_properties as $property){
                $arr_rule = split("=", $property);
                $rules[$arr_rule[0]] = $arr_rule[1]; //key and value
            }

            if(isset($rules['FREQ'])) //make sure we add valid rows
                $events[] = $rules;

            unset($rules); //clear array
        }

        return $events;
    }

    // any custom MW tags or code can be filtered out here...
    // this is only for calendar event display and doesn't edit the article itself
    private function cleanEventData($content){

        $ret = $content;

        // remove [[xyz]] type strings...
        $ret = preg_replace('[(\[\[)+.+(\]\])]', '', $ret);

        // remove  __xyz__   type strings...
        $ret = preg_replace('[(__)+.+(__)]', '', $ret);

        // remove  {{xyz}}  type strings...
        $ret = preg_replace('[({{)+.+(}})]', '', $ret);

        return $ret;
    }
}

/**
 * Class:   Calendar
 * Purpose: Main class
 */

class WikiCalendar extends CalendarArticles
{
    var $arrSettings = array();

    // [begin] set calendar parameter defaults
    var $calendarMode = "normal";
    var $title = "";

    var $disableConfigLink = true;

    var $arrAlerts = array();
    var $subscribedPages = array();

    var $tag_views = "";

    var $invalidateCache = false;
    var $isFullSubscribe = false;

    function WikiCalendar($wikiRoot)
    {
        $this->wikiRoot = $wikiRoot;

        // set the calendar's initial date
        $now = getdate();

        $this->month = $this->actualMonth = $now['mon'];
        $this->year = $this->actualYear = $now['year'];
        $this->day = $this->actualDay = $now['mday'];
    }

    // render the calendar
    function renderCalendar($userMode)
    {
        $ret = "";
        $this->mode = $userMode;

        // need to change the running calendar date
        // or the other functions wont set correctly
        if($userMode == 'day')
            $this->updateDate();

        $this->initalizeHTML();
        $this->readStylepage();

        if($this->setting('usetemplates'))
            $this->buildTemplateEvents();

        if(!$this->setting('disablerecurrences'))
            $this->buildVCalEvents();

        if($this->paramstring != '')
            $this->buildTagEvents($this->paramstring);

        //grab last months events for overlapped repeating events
        if($this->setting('enablerepeatevents')){
            $daysBack = $this->setting('enablerepeatevents',false);
            if($daysBack == '') $daysBack = 15; //default - this checks 1/2 way into the previous month
            $this->initalizeMonth( ($this->day + $daysBack), 0);
        }
        else
            $this->initalizeMonth($this->day, 0); // just go back to the 1st of the current month

        // what mode we going into
        if($userMode == 'year')
            $ret = $this->renderYear();

        if($userMode == 'month')
            $ret = $this->renderMonth();

        if($userMode == 'simplemonth')
            $ret = $this->renderSimpleMonth();

        if($userMode == 'week')
            $ret = $this->renderWeek($this->setting('5dayweek'));

        if($userMode == 'day')
            $ret = $this->renderDate();

        if($userMode == 'events')
            $ret = $this->renderEventList();

        // tag on extra info at the end of whatever is displayed
        $ret .= $this->buildTrackTimeSummary();

        if ($this->prepend_html)
            $ret = $this->prepend_html . $ret;

        return $ret;
    }

    // build the months articles into memory
    // $back: days back from ($this->day)
    // $forward: days ahead from ($this->day)
    function initalizeMonth($back, $forward)
    {
        wfProfileIn(__METHOD__);

        // just make sure we have a solid negitive here
        $back = -(abs($back));

        $cnt = abs($back) + $forward;

        list($month, $day, $year) = CalendarCommon::datemath($back, $this->month, $this->day, $this->year);

        for ($i = 1; $i <= $cnt; $i++) {
            $this->buildArticlesForDay($month, $day, $year);
            list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);
        }
        wfProfileOut(__METHOD__);
    }

    function initalizeHTML()
    {
        wfProfileIn(__METHOD__);
        global $wgOut,$wgScriptPath, $wgVersion;

        $cssURL = $this->getURLRelativePath() . "/templates/";

        // set paths
        $extensionPath = $this->setting('path');
        $extensionPath = str_replace("\\", "/", $extensionPath);

        $css = $this->setting('css');

        $css_data = file_get_contents($extensionPath . "/templates/$css");    //ugly method
        $this->html_template = file_get_contents($extensionPath . "/templates/calendar_template.html");

        //add css; this is set as 'default.css' or an override
        if($wgVersion >= '1.14'){
            $wgOut->addStyle($cssURL . $css); //clean method
        }
        else{
            $wgOut->addHTML($css_data); //ugly method
        }

        $this->templateHTML['normal'] = $this->searchHTML($this->html_template,"<!-- Day Start -->", "<!-- Day End -->");
        $this->templateHTML['missing'] = $this->searchHTML($this->html_template,"<!-- Missing Start -->", "<!-- Missing End -->");

        $year = CalendarCommon::translate('year');
        $month = CalendarCommon::translate('month');
        $week = CalendarCommon::translate('week');

        if(!$this->setting('disablemodes')){
            $selected = "selected='true'";
            $this->tag_views = "<select name='viewSelect' method='post' onChange='javascript:this.form.submit()'>";

            ($this->mode == 'year') ?
                $this->tag_views .= "<option class='lst' value='year' $selected>$year</option>" :
                $this->tag_views .= "<option class='lst' value='year'>$year</option>";

            ($this->mode == 'month') ?
                $this->tag_views .= "<option class='lst' value='month' $selected>$month</option>" :
                $this->tag_views .= "<option class='lst' value='month'>$month</option>";
            ($this->mode == 'week') ?
                $this->tag_views .= "<option class='lst' value='week' $selected>$week</option>" :
                $this->tag_views .= "<option class='lst' value='week'>$week</option>";

            $this->tag_views .= "</select>&nbsp;&nbsp;";
        }

        // build the hidden calendar date info (used to offset the calendar via sessions)
        $this->tag_HiddenData = "<input class='btn' type='hidden' name='calendar_info' value='"
            . $this->month . "`"
            . $this->day . "`"
            . $this->year . "`"
            . $this->title . "`"
            . $this->name . "`"
            . "'>";

        $this->tag_heading =
            '<td class="calendarHeading">[[day1]]</td>
            <td class="calendarHeading">[[day2]]</td>
            <td class="calendarHeading">[[day3]]</td>
            <td class="calendarHeading">[[day4]]</td>
            <td class="calendarHeading">[[day5]]</td>';

        if(!$this->setting('5dayweek') ){
            $this->tag_heading .=
                '<td class="calendarHeading">[[day6]]</td>
                <td class="calendarHeading">[[day7]]</td>';
        }
        wfProfileOut(__METHOD__);
    }

    // Generate the HTML for a given month
    // $day may be out of range; if so, give blank HTML
    function getHTMLForDay($month, $day, $year, $dateFormat='default', $mode='month'){
        $tag_eventList = $tag_dayCustom = "";
        $tag_dayweekyear = "";

        // return an empty plain table cell (includes style class )
        if ($day <= 0) {
            return $this->templateHTML['missing'];
        }

        if( $this->setting("5dayweek") ){
            $dayOfWeek = date('N', mktime(12, 0, 0, $month, $day, $year));    // 0-6

            if($dayOfWeek > 5){
                return "";
            }
        }

        // template table cell
        $tempString = $this->templateHTML['normal'];

        $thedate = getdate(mktime(12, 0, 0, $month, $day, $year));
        $wday = $thedate['wday'];
        $weekday = CalendarCommon::translate($wday+1, 'weekday');

        $display_day = $day;
        if($dateFormat == 'long'){
            $display_day = $weekday . ", " . CalendarCommon::translate($month, 'month_short') . " $day";
        }

        if($dateFormat == 'none')
            $display_day = "";

        if ( ($thedate['mon'] == $this->actualMonth) && ($thedate['year'] == $this->actualYear) && ($thedate['mday'] == $this->actualDay) ) {
            $tag_wday = "calendarToday";
        }
        elseif($wday==0 || $wday ==6){
            $tag_wday = "calendarWeekend";
        }
        else {
            $tag_wday = "calendarWeekday";
        }

        $tag_mode = 'monthMode';
        if($mode == 'events'){
            $tag_mode = 'eventsMode';
            $tag_dayCustom = "eventsDay";
        }
        if($mode=='week'){
            $tag_mode = 'weekMode';
        }
        if($mode == 'day'){
            $tag_mode = 'dayMode';
            $tag_dayCustom = "singleDay";
        }

        // build formatted event list
        $tag_eventList = $this->getArticleLinks($month, $day, $year, true);

        // build Add event bar after building event list
        $tag_addEvent = $this->buildAddEventLink($month, $day, $year);

        // no events, then return nothing!
        if((strlen($tag_eventList) == 0) && ($mode == 'events')) return "";

        $tag_alerts = $this->buildAlertLink($day, $month);

        if($this->setting('dayofyear')){
            $tag_dayweekyear = $this->getDayOfTheYear($month,$day,$year);
        }
        if($this->setting('weekofyear')){
            $tag_dayweekyear .= $this->getWeekOfTheYear($month,$day,$year);
        }

        //kludge... for some reason, the "\n" is removed in full calendar mode
        if($mode == "monthMode")
            $tag_eventList = str_replace("\n", " ", $tag_eventList);

        $tempString = str_replace("[[Day]]", $display_day, $tempString);
        $tempString = str_replace("[[AddEvent]]", $tag_addEvent, $tempString);
        $tempString = str_replace("[[EventList]]", "<ul>" . $tag_eventList . "</ul>", $tempString);
        $tempString = str_replace("[[Alert]]", $tag_alerts, $tempString);
        $tempString = str_replace("[[DayWeekYear]]", $tag_dayweekyear, $tempString);
        $tempString = str_replace("[[mode]]", $tag_mode, $tempString);
        $tempString = str_replace("[[wday]]", $tag_wday, $tempString);
        $tempString = str_replace("[[dayCustom]]", $tag_dayCustom, $tempString);

        return $tempString;
    }

    function buildAlertLink($day, $month){
        $ret = "";

        $alerts = $this->arrAlerts;
        $alertList = "";
        for ($i=0; $i < count($alerts); $i++){
            $alert = split("-", $alerts[$i]);
            if(($alert[0] == $day) && ($alert[1] == $month))
                $alertList .= $alert[2];
        }

        if (strlen($alertList) > 0)
            $ret = "<a style='color:red' href=\"javascript:alert('" .$alertList . "')\"><i>alert!</i></a>";

        return $ret;
    }

    function getWeekOfTheYear($month, $day, $year, $noHTML=false){

        $timestamp = mktime(12,0,0,$month, $day, $year);
        $weekDay = date("w", $timestamp);
        $week = date("W", $timestamp);

        if($noHTML) return $week;

        $translated = CalendarCommon::translate('weekyearTranslated');

        if($this->setting('dayofyear')){
            $html = "<span title='" . $translated . "'>/$week</span>";
        }
        else{
            $html = "<span title='" . $translated . "'>$week</span>";
        }

        return $html;
    }

    function getDayOfTheYear($month, $day, $year){

        $timestamp = mktime(12,0,0,$month, $day, $year);
        $dayYear = (date("z", $timestamp) +1);

        $translated = CalendarCommon::translate('dayyearTranslated');
        $html = "<span title='" . $translated . "'>$dayYear</span>";

        return $html;
    }

    // build the 'template' button
    function buildTemplateLink(){
        if(!$this->setting('usetemplates')) return "";

        $articleName = $this->wikiRoot . wfUrlencode($this->calendarPageName) . "/" . $this->month . "-" . $this->year . " -Template&action=edit" . "'\">";

        $value = CalendarCommon::translate('template_btn');
        $title = CalendarCommon::translate('template_btn_tip');

        if($this->setting('locktemplates'))
            $ret = "<input class='btn' type='button' title='$title' disabled value=\"$value\" onClick=\"javascript:document.location='" . $articleName;
        else
            $ret = "<input class='btn' type='button' title='$title' value=\"$value\" onClick=\"javascript:document.location='" . $articleName;

        return $ret;
    }

    function loadiCalLink(){
        $ical_value = CalendarCommon::translate('ical_btn');
        $ical_title = CalendarCommon::translate('ical_btn_tip');
        $bws_title = CalendarCommon::translate('ical_browse_tip');

        $note = "";
        $cookieName = str_replace(' ', '_', ($this->calendarPageName . "_ical_count"));
        if(isset($_COOKIE[$cookieName])){
            $cnt = $_COOKIE[$cookieName];
            $note = "<font color=red>Completed the import of <b>$cnt</b> record(s).</font>";
            setcookie($cookieName, "", time()-3600);
        }

        $ret = CalendarCommon::translate('ical_inst') . "<br>"
            . "<input name='uploadedfile' type='file' title=\"$bws_title\" size='50'><br>"
            . "<input name='ical' class='btn' type='submit' title=\"$ical_title\" value=\"$ical_value\">&nbsp;&nbsp;"
            . $note;

        return $ret;
    }

    // build the 'template' button
    function buildConfigLink($bTextLink = false){

        if(!$this->setting('useconfigpage')) return;

        if($this->setting('useconfigpage',false) == 'disablelinks') return "";

        $value = CalendarCommon::translate('config_btn');
        $title = CalendarCommon::translate('config_btn_tip');

        if(!$bTextLink){
            $articleConfig = $this->wikiRoot . wfUrlencode($this->configPageName) . "&action=edit" . "';\">";
            $ret = "<input class='btn' type='button' title='$title' value=\"$value\" onClick=\"javascript:document.location='" . $articleConfig;
        }else
            $ret = "<a href='" . $this->wikiRoot . wfUrlencode($this->configPageName) . "&action=edit'>($value...)</a>";

        return $ret;
    }

    function renderEventList()
    {
        wfProfileIn(__METHOD__);

        $events = "";
        $setting = $this->setting('useeventlist',false);

        if($setting && $setting > 0)
        {
            $this->calendarMode = "eventlist";
            $daysOut = ($setting <= 120 ? $setting : 120);

            $month = $this->month;
            $day = $this->day;
            $year = $this->year;

            $this->updateSetting('charlimit',100);

            // build the days out....
            $this->initalizeMonth(0, $daysOut);

            for ($i = 0; $i < $daysOut; $i++)
            {
                $temp = $this->getHTMLForDay($month, $day, $year, 'long', 'events');
                if (strlen(trim($temp)) > 0)
                    $events .= "<tr>" . $temp . "</tr>";
                list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);
            }

            $ret = "<i> " . $this->buildConfigLink(true) . "</i>" . $events;
            $ret = "<table width=100%>" . $ret . "</table>";
        }
        wfProfileOut(__METHOD__);
        return $ret;
    }

    function buildTemplateEvents()
    {
        $year = $this->year;
        $month = 1;
        $additionMonths = $this->month + 12;

        // lets just grab the next 12 months...this load only takes about .01 second per subscribed calendar
        for ($i = 0; $i < $additionMonths; $i++)
        {
            // loop thru 12 months
            foreach($this->subscribedPages as $page)
                $this->addTemplate($month, $year, $page);

            $this->addTemplate($month, $year, ($this->calendarPageName));
            $year = ($month == 12 ? ++$year : $year);
            $month = ($month == 12 ? 1 : ++$month);
        }
    }

    // load ical RRULE (recurrence) events into memory
    function buildVCalEvents()
    {
        wfProfileIn(__METHOD__);

        $year = $this->year;
        $month = 1;
        $additionMonths = $this->month + 12;

        // lets just grab the next 12 months...this load only takes about .01 second per subscribed calendar
        for ($i = 0; $i < $additionMonths; $i++)
        {
            // loop thru 12 months
            foreach($this->subscribedPages as $page)
                $this->addVCalEvents($page, $year, $month);

            $this->addVCalEvents($this->calendarPageName, $year, $month);
            $year = ($month == 12 ? ++$year : $year);
            $month = ($month == 12 ? 1 : ++$month);
        }
        wfProfileOut(__METHOD__);
    }

    // used for 'date' mode only... technically, this can be any date
    function updateDate()
    {
        wfProfileIn(__METHOD__);
        $this->calendarMode = "date";
        $setting = $this->setting("date", false);
        $shift = false;
        if ($setting == "today")
            $shift = 0;
        elseif ($setting == "tomorrow")
            $shift = 1;
        elseif ($setting == "yesterday")
            $shift = -1;
        /* offset from today */
        elseif (preg_match('/^[\+\-](\d+)$/s', $setting, $m))
            $shift = intval($setting);
        elseif ($setting)
        {
            $useDash = split("-",$setting);
            $useSlash = split("/",$setting);
            $useDot = split(".",$setting);
            /* YYYY-MM-DD */
            if (count($d = $useDash) > 1)
                $i = array(0, 1, 2);
            /* MM/DD/YYYY */
            elseif (count($d = $useSlash) > 1)
                $i = array(2, 0, 1);
            /* DD.MM.YYYY */
            elseif (count($d = $useDot) > 1)
                $i = array(2, 1, 0);
            if ($i)
            {
                $this->year = intval($d[$i[0]]);
                $this->month = intval($d[$i[1]]);
                $this->day = intval($d[$i[2]]);
            }
        }
        /* relative dates */
        if ($shift !== false)
            list($this->month, $this->day, $this->year) = CalendarCommon::datemath($shift, $this->month, $this->day, $this->year);
        wfProfileOut(__METHOD__);
    }

    // specific date mode
    function renderDate()
    {
        wfProfileIn(__METHOD__);
        $this->initalizeMonth(0,1);
        $ret = $this->buildConfigLink(true) . $this->getHTMLForDay($this->month, $this->day, $this->year, 'long', 'day');
        $ret = "<table>$ret</table>";
        wfProfileOut(__METHOD__);
        return $ret;
    }

    function renderSimpleMonth()
    {
        $ret = $this->buildSimpleCalendar($this->month, $this->year);
        return $ret;
    }

    function buildMonthSelectBox($shortMonths=false)
    {
        // build the month select box
        $monthSelect = "<select name='monthSelect' method='post' onChange='javascript:this.form.submit()'>";
        for ($i = 1; $i <= 12; $i++) {
            if ($i == $this->month) {
                $monthSelect .= "<option class='lst' value='" . ($i) . "' selected='true'>" .
                CalendarCommon::translate($i, 'month') . "</option>\n";
            }
            else {
                $monthSelect .= "<option class='lst' value='" . ($i) . "'>" .
                CalendarCommon::translate($i, 'month') . "</option>\n";
            }
        }
        $monthSelect .= "</select>";

        return $monthSelect;
    }

    function buildYearSelectBox(){

        $yearoffset = $this->setting('yearoffset',false);

        // build the year select box, with +/- 5 years in relation to the currently selected year
        $yearSelect = "<select name='yearSelect' method='post' onChange='javascript:this.form.submit()'>";
        for ($i = ($this->year - $yearoffset); $i <= ($this->year + $yearoffset); $i += 1) {
            if ($i == $this->year) {
                $yearSelect .= "<option class='lst' value='$i' selected='true'>" .
                $i . "</option>\n";
            }
            else {
                $yearSelect .= "<option class='lst' value='$i'>$i</option>\n";
            }
        }
        $yearSelect .= "</select>";

        return $yearSelect;
    }

    function renderMonth()
    {
        wfProfileIn(__METHOD__);
        global $gCalendarVersion;

        $tag_templateButton = "";

        $this->calendarMode = "normal";

        /***** Replacement tags *****/
        $tag_monthSelect = "";             // the month select box [[MonthSelect]]
        $tag_previousMonthButton = "";     // the previous month button [[PreviousMonthButton]]
        $tag_nextMonthButton = "";         // the next month button [[NextMonthButton]]
        $tag_yearSelect = "";              // the year select box [[YearSelect]]
        $tag_previousYearButton = "";      // the previous year button [[PreviousYearButton]]
        $tag_nextYearButton = "";          // the next year button [[NextYearButton]]
        $tag_calendarName = "";            // the calendar name [[CalendarName]]
        $tag_calendarMonth = "";           // the calendar month [[CalendarMonth]]
        $tag_calendarYear = "";            // the calendar year [[CalendarYear]]
        $tag_day = "";                     // the calendar day [[Day]]
        $tag_addEvent = "";                // the add event link [[AddEvent]]
        $tag_eventList = "";               // the event list [[EventList]]
        $tag_eventStyleButton = "";        // event style buttonn [[EventStyleBtn]]
        $tag_templateButton = "";        // template button for multiple events [[TemplateButton]]
        $tag_todayButton = "";            // today button [[TodayButton]]
        $tag_configButton = "";         // config page button
        $tag_timeTrackValues = "";         // summary of time tracked events
        $tag_loadiCalButton = "";
        $tag_about = "";

        /***** Calendar parts (loaded from template) *****/
        $html_header = "";             // the calendar header
        $html_day_heading = "";        // the day heading
        $html_week_start = "";         // the calendar week pieces
        $html_week_end = "";
        $html_footer = "";             // the calendar footer

        /***** Other variables *****/

        $ret = "";          // the string to return

        //build events into memory for the remainder of the month
        //the previous days have already been loaded
        $this->initalizeMonth(0, (32 - $this->day));

        /***** Build the known tag elements (non-dynamic) *****/
        // set the month's name tag
        if($this->name == 'Public')
            $tag_calendarName = CalendarCommon::translate('default_title');
        else
            $tag_calendarName = $this->name;

        $about_translated = CalendarCommon::translate('about');
        $tag_about = "<a title='$about_translated' href='http://www.mediawiki.org/wiki/Extension:Calendar_(Kenyu73)' target='new'>about</a>...";

        // set the month's mont and year tags
        $tag_calendarMonth = CalendarCommon::translate($this->month, 'month');
        $tag_calendarYear = $this->year;

        $tag_monthSelect =  $this->buildMonthSelectBox();;
        $tag_yearSelect = $this->buildYearSelectBox();

        $tag_templateButton = $this->buildTemplateLink();
        $tag_configButton = $this->buildConfigLink(false);

        $style_value = CalendarCommon::translate('styles_btn');
        $style_tip = CalendarCommon::translate('styles_btn_tip');

        if(!$this->setting("disablestyles")){
            $articleStyle = $this->wikiRoot . wfUrlencode($this->calendarPageName) . "/style&action=edit" . "';\">";
            $tag_eventStyleButton = "<input class='btn' type=\"button\" title=\"$style_tip\" value=\"$style_value\" onClick=\"javascript:document.location='" . $articleStyle;
        }

        // build the 'today' button
        $btnToday = CalendarCommon::translate('today');
        $tag_todayButton = "<input class='btn' name='today' type='submit' value=\"$btnToday\">";
        $tag_previousMonthButton = "<input class='btn' name='monthBack' type='submit' value='<<'>";
        $tag_nextMonthButton = "<input class='btn' name='monthForward' type='submit' value='>>'>";
        $tag_previousYearButton = "<input class='btn' name='yearBack' type='submit' value='<<'>";
        $tag_nextYearButton = "<input class='btn' name='yearForward' type='submit' value='>>'>";

        // grab the HTML peices for the calendar
        $html_header = $this->searchHTML($this->html_template,
                         "<!-- Header Start -->", "<!-- Header End -->");

        $html_day_heading = $this->searchHTML($this->html_template,
                          "<!-- Heading Start -->","<!-- Heading End -->");

        // the calendar week pieces
        $html_week_start = "<tr>";
        $html_week_end = "</tr>";

        // the calendar footer
        $html_footer = $this->searchHTML($this->html_template,
                         "<!-- Footer Start -->", "<!-- Footer End -->");

        /***** Begin Building the Calendar (pre-week) *****/
        // add the header to the calendar HTML code string
        $ret .= $html_header;
        $ret .= $html_day_heading;

        //$ret = str_replace("[[HEADER]]", $this->tag_header_month_view, $ret);

        /***** Search and replace variable tags at this point *****/
        $ret = str_replace("[[TodayButton]]", $tag_todayButton, $ret);
        $ret = str_replace("[[MonthSelect]]", $tag_monthSelect, $ret);
        $ret = str_replace("[[PreviousMonthButton]]", $tag_previousMonthButton, $ret);
        $ret = str_replace("[[NextMonthButton]]", $tag_nextMonthButton, $ret);
        $ret = str_replace("[[YearSelect]]", $tag_yearSelect, $ret);
        $ret = str_replace("[[PreviousYearButton]]", $tag_previousYearButton, $ret);
        $ret = str_replace("[[NextYearButton]]", $tag_nextYearButton, $ret);
        $ret = str_replace("[[CalendarName]]", $tag_calendarName, $ret);
        $ret = str_replace("[[CalendarMonth]]", $tag_calendarMonth, $ret);
        $ret = str_replace("[[CalendarYear]]", $tag_calendarYear, $ret);
        $ret = str_replace("[[Views]]", $this->tag_views, $ret);

        $heading = $this->tag_heading;

        if($this->setting('monday')){
            $heading = str_replace("[[day1]]", CalendarCommon::translate(2,'weekday'), $heading);
            $heading = str_replace("[[day2]]", CalendarCommon::translate(3,'weekday'), $heading);
            $heading = str_replace("[[day3]]", CalendarCommon::translate(4,'weekday'), $heading);
            $heading = str_replace("[[day4]]", CalendarCommon::translate(5,'weekday'), $heading);
            $heading = str_replace("[[day5]]", CalendarCommon::translate(6,'weekday'), $heading);

            if( !$this->setting('5dayweek') ){
                $heading = str_replace("[[day6]]", CalendarCommon::translate(7,'weekday'), $heading);
                $heading = str_replace("[[day7]]", CalendarCommon::translate(1,'weekday'), $heading);
            }
        }
        else{
            $heading = str_replace("[[day1]]", CalendarCommon::translate(1,'weekday'), $heading);
            $heading = str_replace("[[day2]]", CalendarCommon::translate(2,'weekday'), $heading);
            $heading = str_replace("[[day3]]", CalendarCommon::translate(3,'weekday'), $heading);
            $heading = str_replace("[[day4]]", CalendarCommon::translate(4,'weekday'), $heading);
            $heading = str_replace("[[day5]]", CalendarCommon::translate(5,'weekday'), $heading);

            if( !$this->setting('5dayweek') ){
                $heading = str_replace("[[day6]]", CalendarCommon::translate(6,'weekday'), $heading);
                $heading = str_replace("[[day7]]", CalendarCommon::translate(7,'weekday'), $heading);
            }
        }

        $ret = str_replace("[[HEADING]]", $heading, $ret);

        $ret .= $this->getMonthHTML($this->month, $this->year);

        /***** Do footer *****/
        $tempString = $html_footer;

        if($this->setting('ical'))
            $tag_loadiCalButton = $this->loadiCalLink();

        // replace potential variables in footer
        $tempString = str_replace("[[TodayData]]", $this->tag_HiddenData, $tempString);
        $tempString = str_replace("[[TemplateButton]]", $tag_templateButton, $tempString);
        $tempString = str_replace("[[EventStyleBtn]]", $tag_eventStyleButton, $tempString);
        $tempString = str_replace("[[Version]]", $gCalendarVersion, $tempString);
        $tempString = str_replace("[[ConfigurationButton]]", $tag_configButton, $tempString);
        $tempString = str_replace("[[TimeTrackValues]]", $tag_timeTrackValues, $tempString);
        $tempString = str_replace("[[Load_iCal]]", $tag_loadiCalButton, $tempString);
        $tempString = str_replace("[[About]]", $tag_about, $tempString);

        $ret .= $tempString;
        $ret = $this->stripLeadingSpace($ret);

        wfProfileOut(__METHOD__);
        return $ret;
    }

    // this generates the logic and the html to organize where the days go in the "grid"
    function getMonthHTML($month, $year, $simplemonth=false){
        $offset = 1;
        $dayOfWeek = date('N', mktime(12, 0, 0, $month, 1, $year));    // 0-6
        $daysInMonth = CalendarCommon::getDaysInMonth($month,$year); // 28-31
        $weeksInMonth = ceil( ($dayOfWeek + $daysInMonth)/7 ) ; // 4-6

        if( $this->setting("monday") && !$this->setting("5dayweek") ) $offset = 2;

        $counter = $theDay = -($dayOfWeek-$offset);
        $ret = "";

        $bfiveDayWeek = $this->setting("5dayweek");

        for ($week = 0; $week < $weeksInMonth; $week+=1){
            $bValidWeek = false;
            $temp = "<tr>";

            for ($day = 0; $day < 7; $day+=1){
                $bSkipDay = false;


                if($counter > $daysInMonth) $theDay = 0; // we want these days to be grey or empty...etc

                if( ($day == 0 or $day == 6) && $bfiveDayWeek ){
                    $bSkipDay = true;
                }

                if( !$bSkipDay ){
                    if($theDay > 0) $bValidWeek = true;

                    if($simplemonth){
                        $todayStyle = "style='background-color: #C0C0C0;font-weight:bold;'";
                        $link = $this->buildAddEventLink($month, $theDay, $year, $theDay);

                        $temp .= "<td class='yearWeekday $todayStyle'>$link</td>";
                    }
                    else{
                        $temp .= $this->getHTMLForDay($month, $theDay, $year);
                    }
                }

                $counter++;
                $theDay++;
            }

            $temp .= "</tr>";

            // dont display a completely "greyed" out 5 day week
            if($bValidWeek == true){
                $ret .= $temp;
            }
        }

        return $ret;
    }

    // returns the HTML that appears between two search strings.
    // the returned results include the text between the search strings,
    // else an empty string will be returned if not found.
    function searchHTML($html, $beginString, $endString) {

        $temp = split($beginString, $html);
        if (count($temp) > 1) {
            $temp = split($endString, $temp[1]);
            return $temp[0];
        }
        return "";
    }

    // strips the leading spaces and tabs from lines of HTML (to prevent <pre> tags in Wiki)
    function stripLeadingSpace($html) {

        $index = 0;

        $temp = split("\n", $html);

        $tempString = "";
        while ($index < count($temp)) {
        while (strlen($temp[$index]) > 0
           && (substr($temp[$index], 0, 1) == ' ' || substr($temp[$index], 0, 1) == '\t')) {
        $temp[$index] = substr($temp[$index], 1);
        }
            $tempString .= $temp[$index];
            $index += 1;
        }

        return $tempString;
    }

    function cleanDayHTML($tempString){
        // kludge to clean classes from "day" only parameter; causes oddness if the main calendar
        // was displayed with a single day calendar on the same page... the class defines carried over...
        $tempString = str_replace("calendarTransparent", "", $tempString);
        $tempString = str_replace("calendarDayNumber", "", $tempString);
        $tempString = str_replace("calendarEventAdd", "", $tempString);
        $tempString = str_replace("calendarEventList", "", $tempString);

        $tempString = str_replace("calendarToday", "", $tempString);
        $tempString = str_replace("calendarMonday", "", $tempString);
        $tempString = str_replace("calendarTuesday", "", $tempString);
        $tempString = str_replace("calendarWednesday", "", $tempString);
        $tempString = str_replace("calendarThursday", "", $tempString);
        $tempString = str_replace("calendarFriday", "", $tempString);
        $tempString = str_replace("calendarSaturday", "", $tempString);
        $tempString = str_replace("calendarSunday", "", $tempString);

        return $tempString;
    }

    // builds the day events into memory
    // uses prefix seaching (NS:page/name/date)... anything after doesn't matter
    function buildArticlesForDay($month, $day, $year)
    {
        $date = sprintf("%04d-%02d-%02d", $year, $month, $day);

        $search = "$this->calendarPageName/$date";
        $pages = PrefixSearch::titleSearch( $search, '100');

        foreach($pages as $page) {
            $this->addArticle($month, $day, $year, $page);
        }
        unset ($pages);

        // subscribed events
        foreach($this->subscribedPages as $subscribedPage){
            $search = "$subscribedPage/$date";
            $pages = PrefixSearch::titleSearch( $search, '100' );
            foreach($pages as $page)
                $this->addArticle($month, $day, $year, $page);
        }

        // depreciated (around 1/1/2009)
        // old format: ** name (12-15-2008) - Event 1 **
        if($this->setting('enablelegacy')){
            $date = "$month-$day-$year";
            $name = $this->setting('name');
            $search = "$this->namespace:$name ($date)";
            $pages = PrefixSearch::titleSearch( $search, '100');

            foreach($pages as $page) {
                $this->addArticle($month, $day, $year, $page);
            }
            unset ($pages);
        }
    }

    // this is a general find/replace for the date format
    // users can define whatever format this wish
    // ie: 20090731, 07-01-2009, 07.01.2009, etc
    function userDateFormat($month, $day, $year) {
        global $wgCalendarDateFormat;

        $format = $wgCalendarDateFormat;
        if($format == '') $format = 'M-D-YYYY'; //default

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

    function buildTagEvents($paramstring){

        $events = split( "\n", trim($paramstring) );

        foreach($events as $event) {
            $arr = split(':', $event);
            $date = array_shift($arr);
            $event = array_shift($arr);

            $body = implode(':',$arr);

            $arrDate = split('-',$date);

            // we must have a valid date to continue
            if(count($arrDate) < 3)
                break;

            $month = $arrDate[0];
            $day = $arrDate[1];
            $year = $arrDate[2];

            $this->buildEvent($month, $day, $year, $event, $this->title);
        }
    }

    function buildSimpleCalendar($month, $year,$disableNavButtons=false){

        $prev = $next = "";

        $monthname = CalendarCommon::translate($month,'month_short');
        if ( $this->isFullSubscribe ) {
            $monthname = "<a title='$this->calendarPageName' href='" . $this->wikiRoot . substr($this->calendarPageName, 0, strrpos($this->calendarPageName, "/")) . "'>" . $monthname . "</a>";
        }

        $monthyear = "$monthname, $this->year";

        if(!$disableNavButtons){
            $prev = "<input class='btn' name='monthBack' type='submit' value='<<'>";
            $next = "<input class='btn' name='monthForward' type='submit' value='>>'>";
        }

        $title = "<table class='yearCalendarMonth_x' width=100% cellpadding=0 cellspacing=0><td>$prev</td><td>" . $monthyear . "</td><td>$next</td></table>";

        $header = "<table class='yearCalendarMonth'><tr><td style='font-size:9px' class='yearTitle'>$title</tr></table>";

        $ret = "<tr>";

        if($this->setting('monday')){
            $ret .= "
                <td class='yearHeading'>" . substr(CalendarCommon::translate(2,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(3,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(4,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(5,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(6,'weekday'),0,1) . "</td>";
            if(!$this->setting('5dayweek')){
                $ret .=
                    "<td class='yearHeading'>" . substr(CalendarCommon::translate(7,'weekday'),0,1) . "</td>
                    <td class='yearHeading'>" . substr(CalendarCommon::translate(1,'weekday'),0,1) . "</td>";
            }
        }
        else{
            $ret .= "
                <td class='yearHeading'>" . substr(CalendarCommon::translate(1,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(2,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(3,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(4,'weekday'),0,1) . "</td>
                <td class='yearHeading'>" . substr(CalendarCommon::translate(5,'weekday'),0,1) . "</td>";
            if(!$this->setting('5dayweek')){
                $ret .=
                    "<td class='yearHeading'>" . substr(CalendarCommon::translate(6,'weekday'),0,1) . "</td>
                    <td class='yearHeading'>" . substr(CalendarCommon::translate(7,'weekday'),0,1) . "</td>";
            }
        }

        $ret.= $this->getMonthHTML($month,$year,true);

        $hidden = $this->tag_HiddenData;

        return "<form name='cal_frm' method='post'>" . $header . "<table class='yearCalendarMonth'>$ret</table>$hidden</form>";
    }

    function renderYear(){

        $tag_mini_cal_year = "";

        $tag_previousYearButton = "<input class='btn' name='yearBack' type='submit' value='<<'>";
        $tag_nextYearButton = "<input class='btn' name='yearForward' type='submit' value='>>'>";

        $styleTitle = "style='text-align:center; font-size:24px; font-weight:bold;'";

        $html_head = "<table class='yearCalendar'><form  method='post'>";
        $html_foot = "</table></form>";

        $ret = ""; $cal = "";
        $nextMon=1;
        $nextYear = $this->year;

        $title = "$tag_previousYearButton &nbsp; $this->year &nbsp; $tag_nextYearButton";

        $ret = "<tr><td>" . $this->buildConfigLink(true) . "</td><td $styleTitle colspan=2>$title</td><td align=right>$this->tag_views</td></tr>";

        for($m=0;$m <12; $m++){
            $cal .= "<td style='text-align:center; vertical-align:top;'>" . $this->buildSimpleCalendar($nextMon++, $nextYear, true) . "</td>";

            if($m==3 || $m==7 || $m==11){
                $ret .= "<tr>$cal</tr>";
                $cal = "";
            }
        }

        return $html_head . $ret . $this->tag_HiddenData . $html_foot ;
    }

    function renderWeek($fiveDay = false)
    {
        wfProfileIn(__METHOD__);
        $this->initalizeMonth(0,8);

        //defaults
        $sunday = $saturday  = $ret = $week = "";
        $colspan = 2;

        $styleTable = "style='border-collapse:collapse; width:100%;'";
        $styleTitle = "style='font-size: 24px;'";

        $html_head = "<form  method='post'><table $styleTable border=0>";
        $html_foot = "</table></form>";

        $weekday = date('w', mktime(12, 0, 0, $this->month, $this->day, $this->year));

        if($this->setting('monday'))
            $weekday--;
        list($month, $day, $year) = CalendarCommon::datemath(-($weekday), $this->month, $this->day, $this->year);

        $title = CalendarCommon::translate($month, 'month') . ", " . $year;

        $btnToday = CalendarCommon::translate('today');
        $tag_weekBack = "<input class='btn' name='weekBack' type='submit' value='<<'>";
        $tag_weekForward = "<input class='btn' name='weekForward' type='submit' value='>>'>";
        $tag_todayButton = "<input class='btn' name='today' type='submit' value=\"$btnToday\">";

        if(!$fiveDay){
            $sunday = "<td class='calendarHeading'>" . CalendarCommon::translate(1, 'weekday'). "</td>";
            $saturday = "<td class='calendarHeading'>" . CalendarCommon::translate(7, 'weekday'). "</td>";
            $colspan = 4; //adjust for mode buttons
        }

        //hide mode buttons if selected via parameter tag
        $ret .= "<tr>&nbsp;<td></td><td $styleTitle colspan=2>&nbsp;$title</td>" . "<td>&nbsp;<i>". $this->buildConfigLink(true) . "</i></td>"
            . "<td align=right colspan=$colspan>$tag_todayButton &nbsp;&nbsp; $this->tag_views</td><td>&nbsp;</td></tr>";

        if($this->setting('monday')){
            $ret .= "<tr><td></td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(2, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(3, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(4, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(5, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(6, 'weekday'). "</td>";
            $ret .= $saturday;
            $ret .= $sunday;
            $ret .= "<td></td></tr>";
        }
        else{
            $ret .= "<tr><td></td>";
            $ret .= $sunday;
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(2, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(3, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(4, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(5, 'weekday'). "</td>";
            $ret .= "<td class='calendarHeading'>" . CalendarCommon::translate(6, 'weekday'). "</td>";
            $ret .= $saturday;
            $ret .= "<td></td></tr>";
        }

        if($fiveDay && !$this->setting('monday'))
            list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);

        for($i=0; $i<7; $i++){
            if($fiveDay && $i==0) $i=2;
            $week .= $this->getHTMLForDay($month, $day, $year, 'short', 'week');
            list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);
        }

        $ret .= "<tr><td width=1% valign=top>$tag_weekBack</td>" . $week . "<td width=1% valign=top>$tag_weekForward</td></tr>";
        $ret = $html_head . $ret . $this->tag_HiddenData . $html_foot;

        wfProfileOut(__METHOD__);
        return $ret;
    }

    //hopefully a catchall of most types of returns values
    function setting($param, $retBool=true){

        //not set; return bool false
        if(!isset($this->arrSettings[$param]) && $retBool) return false;
        if(!isset($this->arrSettings[$param]) && !$retBool) return "";

        //set, but no value; return bool true
        if($param == $this->arrSettings[$param] && $retBool) return true;
        if($param == $this->arrSettings[$param] && !$retBool) return "";

        // contains data; so lets return it
        return $this->arrSettings[$param];
    }

    function updateSetting($params, $value = null){
        $this->arrSettings[$params] = $value;
    }

    // php has a defualt of 30sec to run a script, so it can timeout...
    function load_iCal($ical_data)
    {
        wfProfileIn(__METHOD__);

        $bMulti = false;
        $iCal = new CalendarICAL;

        $bExpired = false;
        $bOverwrite = false;
        $description = "";
        $summary = "";

        //make sure we're good before we go further
        if (!$iCal->setFile($ical_data))
            return;

        $arr = $iCal->getData();

        if ($this->setting('ical', false) == 'overwrite')
            $bOverwrite = true;

        if (ini_get('max_execution_time') < 120)
            set_time_limit(120); //increase the script timeout for this load to 2min
        $cnt = 0;
        foreach($arr as $event)
        {
            $bExpired = false; //reset per loop

            if(isset($event['DTSTART'])){
                $start = $event['DTSTART'];

                if(isset($event['SUMMARY']))
                    $summary = $event['SUMMARY'];

                if(isset($event['DESCRIPTION']))
                    $description = $event['DESCRIPTION'];

                if(!isset($event['DTEND']))
                    $event['DTEND'] = $event['DTSTART'];

                $date_string = $this->userDateFormat($start['mon'], $start['mday'], $start['year']);
                $page = $this->getNextAvailableArticle($this->calendarPageName, $date_string, true);

                $date_diff = ceil(CalendarCommon::day_diff($event['DTSTART'], $event['DTEND']));
                if($date_diff > 1)
                    $summary = $date_diff . "#" . $summary; //multiple day events

                // add events
                if(!isset($event['RRULE'])){
                    $this->createNewMultiPage($page, $summary, $description, "iCal Import");
                    $cnt++;
                }
                else{
                    $recurrence_page = "$this->calendarPageName/recurrence";

                    //clean up the RRULE some to fit this calendars need...
                    $byday = $bymonth = "";
                    if(stripos($event['RRULE'], "BYDAY") === false) $byday = ";DAY=" . $start['mday'];
                    if(stripos($event['RRULE'], "BYMONTH") === false) $bymonth = ";BYMONTH=" . $start['mon'];

                    $rrule = "RRULE:" . $event['RRULE']
                        . $bymonth
                        . $byday
                        . ";SUMMARY=" . $summary;

                    $rules = $this->convertRRULEs($rrule);

                    if(isset($rules[0]['UNTIL'])){
                        $bExpired = $this->checkExpiredRRULE($rules[0]['UNTIL']);
                    }

                    if(!$bExpired){
                        //add recurrences
                        $cnt += $this->updateRecurrence($recurrence_page, $rrule, $summary, "iCal Import", $bOverwrite);
                        $bOverwrite = false; //just need to hit the overwrite one time to delete the page...
                    }

                    unset($rules);
                }
            }
        }

        $cookieName = str_replace(' ', '_', ($this->calendarPageName . "_ical_count"));
        setcookie($cookieName,$cnt);

        wfProfileOut(__METHOD__);
    }

    // get the extension short 'URL' path ex:( /mediawiki/extensions/calendar/ )
    // ... there has to be a better way then this!
    function getURLRelativePath()
    {
        global $wgScriptPath,$wgCalendarURLPath;
        if($wgCalendarURLPath)
            return $wgCalendarURLPath;
        else
            return $wgScriptPath . "/extensions/Calendar";
    }

    // Set/Get accessors
    function setMonth($month) { $this->month = $month; } /* currently displayed month */
    function setDay($day) { $this->day = $day; } /* currently displayed month */
    function setYear($year) { $this->year = $year; } /* currently displayed year */
    function setTitle($title) { $this->title = $title; }
    function setName($name) { $this->name = $name; }
    function setMode($mode) { $this->mode = $mode; }
    function createAlert($day, $month, $text){$this->arrAlerts[] = $day . "-" . $month . "-" . $text . "\\n"; }
}
