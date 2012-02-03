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
    var $anchor = "";
    var $is_template = false;

    function CalendarArticle($month, $day, $year) {
        $this->month = $month;
        $this->day = $day;
        $this->year = $year;
    }
}

/**
 * Class:   CalendarArticles
 * Purpose: Contains most of the functions to retrieve article
 *          information. It also is the primary container for
 *          the main array of class::CalendarArticle articles
 */

class CalendarArticles
{
    var $arrEvents = array();
    var $arrTimeTrack = array();
    var $arrStyle = array();

    static function eventtime_sort($a, $b)
    {
        $r = ($b->is_template?1:0)-($a->is_template?1:0); if ($r != 0) return $r;
        $r = $a->year-$b->year; if ($r != 0) return $r;
        $r = $a->month-$b->month; if ($r != 0) return $r;
        $r = $a->day-$b->day; if ($r != 0) return $r;
        return strcmp($a->eventname, $b->eventname);
    }

    // this is the MAIN function that returns the events to the calendar...
    // there shouldn't be ANY formatting or logic done here....
    public function getArticleLinks($month, $day, $year)
    {
        $ret = $list = "";
        $bFound = false;

        // we want to format the normal 'add event' items in 1 table cell
        // this creates less spacing and creates a better <ul>
        $head = "<tr cellpadding=0 cellspacing=0 ><td class='calendarTransparent singleEvent'>";
        $head .= "<ul class='bullets'>";
        $foot = "</ul></td></tr>";

        if(isset($this->arrEvents))
        {
            // sort them by time
            usort($this->arrEvents, 'WikiCalendar::eventtime_sort');
            foreach($this->arrEvents as $cArticle)
            {
                if ($cArticle->month == $month && $cArticle->day == $day && $cArticle->year == $year)
                {
                    $n = $cArticle->eventname;
                    $link = $cArticle->page . ($cArticle->anchor ? '#' . $cArticle->anchor : '');
                    if (($p = strpos($n, '[[')) === false)
                    {
                        // add a wiki-link
                        $n = '[[' . $link . '|' . $n . ']]';
                    }
                    elseif (($p1 = strpos($n, ':', $p)) !== false &&
                        $wgLang->getNsIndex(substr($n, $p+2, $p1-$p-2)) == NS_IMAGE &&
                        ($p1 = strpos($n, ']]', $p)) !== false)
                    {
                        // add link= to image
                        $n = substr($n, 0, $p1) . '|link=' . $link . substr($n, $p1);
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

    // build an event based on the 1st line or ==event== type
    public function addArticle($month, $day, $year, $page, $is_template = false)
    {
        $lines = array();
        $temp = "";

        if (!is_object($page))
            $page = Title::newFromText($page);
        $article = new Article($page);
        if (!$article->exists())
            return;

        $redirectCount = 0;

        if ($article->isRedirect() && $this->setting('disableredirects'))
            return '';

        while ($article->isRedirect() && $redirectCount < 10) // FIXME remove hardcode (10)
        {
            $redirectedArticleTitle = Title::newFromRedirect($article->getContent());
            $article = new Article($redirectedArticleTitle);
            $redirectCount += 1;
        }

        $body = trim($article->fetchContent(0, false, false));
        if ($body == "")
            return "";

        $lines = explode("\n", $body);

        // dont use section events... only line 1 of the page
        $head = array();
        if ($this->setting('disablesectionevents'))
        {
            $key = array_shift($lines);
            $head[] = array('name' => $key, 'body' => implode("\n", $lines));
        }
        else
        {
            $last = array();
            foreach ($lines as $i => $line)
            {
                $line = trim($line);
                if (preg_match('/^(=+)(.*)\1/s', $line, $m))
                {
                    if ($last)
                        $head[] = $last;
                    $last['anchor'] = $last['name'] = trim($m[2]);
                    $last['body'] = '';
                }
                elseif (!$last)
                {
                    if ($last)
                        $head[] = $last;
                    $last['name'] = $line;
                    $last['body'] = '';
                }
                else
                    $last['body'] .= "$line\n";
            }
        }
        if ($last)
        {
            if (!isset($last['name']))
                $last['name'] = '';
            if (!isset($last['anchor']))
                $last['anchor'] = '';
            $head[] = $last;
        }

        foreach ($head as $ev)
            $this->add($month, $day, $year, trim($ev['name']), $page, $ev['body'], $ev['anchor'], $is_template ? 'template' : 'addevent');
    }

    public function buildEvent($month, $day, $year, $event, $page, $body, $eventType = 'addevent', $bRepeats = false, $anchor = '')
    {
        // user triggered yearly repeat event...
        if(substr($event,0,2) == '##'){
            $event = trim(str_replace("##", "", $event));
            $this->buildRecurrenceEvent($month, $day, $year, $event, $page);
        }

        // check for repeating events
        $arrEvent = explode("#", $event);
        if (isset($arrEvent[1]) && ($arrEvent[0] != 0) && $this->setting('enablerepeatevents'))
        {
            for($i = 0; $i < $arrEvent[0]; $i++)
            {
                $this->add($month, $day, $year, $arrEvent[1], $page, $body, $anchor, false, true);
                list($month, $day, $year) = CalendarCommon::datemath(1, $month, $day, $year);
            }
        }
        else
            $this->add($month, $day, $year, $event, $page, $body, $anchor, $eventType, $bRepeats);
    }

    // this is the FINAL stop; the events are stored here then pulled out
    // and displayed later via "getArticleLinks()"...
    private function add($month, $day, $year, $eventname, $page, $body, $anchor = '', $eventType = 'addevent', $bRepeats = false)
    {
        // $eventType='default' -- addevent
        // $eventType='recurrence'
        // $eventType='template'
        global $wgParser;

        $cArticle = new CalendarArticle($month, $day, $year);
        $temp = $this->checkTimeTrack($month, $day, $year, $eventname, $eventType);
        $temp = trim($temp);

        // lets get the body char limit
        $summaryLength = $this->setting('enablesummary',false);

        // format for different event types
        $class = "baseEvent ";
        if($bRepeats)
            $class .= "repeatEvent ";
        if($eventType == "recurrence")
            $class .= "recurrenceEvent ";
        $class = trim($class);

        $cArticle->month = $month;
        $cArticle->day = $day;
        $cArticle->year = $year;
        $cArticle->page = $page;
        $cArticle->eventname = $temp;
        $cArticle->body = $body;
        $cArticle->anchor = $anchor;
        if ($eventType == 'template')
            $cArticle->is_template = true;

        // wik-a-fi the $body; however, cut off text could cause html issues... so try to
        // keep all required body wiki/html to the top
        $parsedBody = $wgParser->recursiveTagParse(CalendarCommon::limitText($cArticle->body, $summaryLength));

        $this->arrEvents[] = &$cArticle;
        $this->addToIndex($cArticle);
    }

    private function addToIndex(&$cArticle)
    {
        $date = sprintf("%04d-%02d-%02d", $cArticle->year, $cArticle->month, $cArticle->day);
        if (preg_match('/(\d\d:\d\d(:\d\d)?)[^\w:]*(\d\d:\d\d(:\d\d)?)/s', $cArticle->eventname, $m))
        {
            $min = $m[1];
            $max = $m[3];
            if (empty($m[2]))
                $min .= ':00';
            if (empty($m[4]))
                $max .= ':00';
            if (strcmp($min, $max) > 0)
            {
                $t = $min;
                $min = $max;
                $max = $t;
            }
            $this->index[$date][] = array($min, $max, &$cArticle);
        }
        $this->index['articles'][$date][] = &$cArticle;
    }

    // this function checks a template event for a time trackable value
    private function checkTimeTrack($month, $day, $year, $event, $eventType){

        if((stripos($event,"::") === false) || $this->setting('disabletimetrack'))
            return $event;

        $arrEvent = explode("::", $event);

        $arrType = explode(":",$arrEvent[1]);
        if(count($arrType) == 1)
            $arrType = explode("-",$arrEvent[1]);

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

        $cntHead = explode(",", $this->setting('timetrackhead',false));
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
        if($day < 1) return "";
        $section_new = '';

        if($this->setting('disableaddevent') && strlen($text) == 0) return "";
        if($this->setting('disableaddevent') && strlen($text) > 0) return $day;

        $articleName = ""; // the name of the article to check for
        $articleCount = 1; // the article count

        $date = sprintf("%04d-%02d-%02d", $year, $month, $day);

        $argv = array('action' => 'edit');
        // if we're using multi-event mode, then default to section=new
        $sect = $this->setting('usesectionevents');
        if ($sect)
            $argv['section'] = 'new';
        $articleName = $this->getNextAvailableArticle($this->calendarPageName, $date);
        $title = Title::newFromText($articleName);

        $html = '';
        $tip = CalendarCommon::translate('add_event_tip');
        if ($this->setting('weekofyear'))
            $tip .= " (wk:" . $this->getWeekOfTheYear($month,$day,$year,true) . ")";
        $mid = false;
        if ($text == "" && ($range = $this->setting('addeventsbyhour')))
        {
            // display separate links for adding event to each hour
            list($min, $max) = explode('..', $range);
            // addeventsbyhour = 8..21 by default
            if (!$max || $min < 0 || $max > 23)
                list($min, $max) = array(8, 21);
            $min = intval($min);
            $max = intval($max);
            $mid = intval(($min+$max) / 2);
            $hours = range($min, $max);
        }
        else
        {
            // Display only one link: "Add event"
            if ($text == "")
                $text = CalendarCommon::translate("add_event");
            $hours = array(0);
        }
        foreach ($hours as $h)
        {
            if (!$sect)
            {
                $articleName = $this->getNextAvailableArticle($this->calendarPageName, $date.sprintf(" %02d", $h));
                $title = Title::newFromText($articleName);
            }
            // JS and form POST is needed here because MW does not fill edit fields from GET requests
            $onclick = sprintf("wikiaddevent('%s',".($sect ? "'wpSummary'" : "'wpTextbox1'").",'%02d:00-%02d:00')", $title->getFullURL($argv), $h, $h+1);
            $used = $this->indexHourUsed($date, $h);
            $html .= '<a title="'.$tip.'" href="javascript:void(0)" '.($used ? 'class="calendarUsed" ' : '').'onclick="'.htmlspecialchars($onclick,ENT_QUOTES).'">'.($text ? $text : sprintf("%02d",$h)).'</a> ';
            if ($h == $mid)
                $html .= '<br />';
        }
        $this->addCalendarJS();
        return $html;
    }

    function addCalendarJS()
    {
        global $wgOut;
        // Add JS source if it is not present already
        if (strpos($wgOut->mScripts, 'Calendar.addevent.js') === false)
        {
            global $wgScriptPath;
            $wgOut->addScriptFile($wgScriptPath.'/extensions/Calendar/Calendar.addevent.js');
            $wgOut->addScript('<script language="JavaScript">var msg_calendar_event_created = "'.addslashes(wfMsg('calendar-event-created')).'";</script>');
        }
    }

    function indexHourUsed($date, $h)
    {
        if (empty($this->index[$date]))
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
            $this->arrStyle = explode("\n", $displayText);
        }
    }

    public function getConfig($pagename){

        $params = array();

        $articleName = "$pagename/config";
        $article = new Article(Title::newFromText($articleName));

        if ($article->exists()){
            $body  = $article->fetchContent(0,false,false);
            $body = str_replace("\"", "", $body);

            $arr = explode("\n", $body);
            $cnt = count($arr);

            for($i=0; $i<$cnt; $i++){
                $arrParams = explode("=", $arr[$i]);
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

    private function buildStyleBySearch($text)
    {
        $stylePage = '';

        // set default style if available, if not... use std windows defaults
        $defaultStyle = $this->setting('style', false);

        for($i=0; $i < count($this->arrStyle); $i++){
            $arr = explode("::", $this->arrStyle[$i]);
            $cnt = count($arr);

            if(stripos($text, $arr[0]) !== false) {
                $stylePage = trim($arr[1]);
            }
        }

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
        $arr_rrules = explode("RRULE:", $rrules);

        $events = array();
        array_shift($arr_rrules); //1st array[0] is garbage because RRULE: in position 0(1st)

        foreach($arr_rrules as $rule){
            $arr_properties = explode(";", $rule);
            foreach($arr_properties as $property){
                $arr_rule = explode("=", $property);
                $rules[$arr_rule[0]] = $arr_rule[1]; //key and value
            }

            if(isset($rules['FREQ'])) //make sure we add valid rows
                $events[] = $rules;

            unset($rules); //clear array
        }

        return $events;
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
    var $index = array();

    var $disableConfigLink = true;

    var $arrAlerts = array();
    var $subscribedPages = array();

    var $tag_views = "";
    var $prepend_html = '';

    var $invalidateCache = false;
    var $isFullSubscribe = false;

    function WikiCalendar()
    {
        // set the calendar's initial date
        $now = getdate();

        $this->month = $this->actualMonth = $now['mon'];
        $this->year = $this->actualYear = $now['year'];
        $this->day = $this->actualDay = $now['mday'];
    }

    // alias ugly/bad preferences to newer, hopefully better names
    function legacyAliasChecks(&$params)
    {
        if(isset($params['usemultievent']))
            $params['usesectionevents'] = 'usesectionevents';
    }

    function config(&$params)
    {
        global $wgRestrictCalendarTo, $wgCalendarDisableRedirects;

        if(!isset($params["name"]))
            $params["name"] = "Public";
        $name = $params["name"] = CalendarCommon::checkForMagicWord($params["name"]);

        $this->titleObject = $params['title'];
        $title = $this->titleObject->getPrefixedText();
        $this->namespace = $this->titleObject->getNsText();
        $this->calendarPageName = "$title/$name";
        $this->configPageName = "$title/$name/config";
        $this->setTitle($title);
        $this->setName($name);

        if(isset($params["useconfigpage"]))
        {
            $configs = $this->getConfig($this->calendarPageName);
            // merge the config page and the calendar tag params; tag params overwrite config file
            $params = array_merge($configs, $params);
        }

        // just in case i rename some preferences... we can make them backwards compatible here...
        $this->legacyAliasChecks($params);

        // set defaults that are required later in the code...
        if(!isset($params["timetrackhead"]))     $params["timetrackhead"] = "Event, Value";
        if(!isset($params["maxdailyevents"]))    $params["maxdailyevents"] = 5;
        if(!isset($params["yearoffset"]))        $params["yearoffset"] = 2;
        if(!isset($params["charlimit"]))         $params["charlimit"] = 25;
        if(!isset($params["css"]))               $params["css"] = "default.css";

        // set secure mode via $wgRestrictCalendarTo global
        // this global is set via LocalSetting.php (ex: $wgRestrictCalendarTo = 'sysop';
        if ($wgRestrictCalendarTo)
        {
            global $wgUser;
            $arrGroups = $wgUser->getGroups();
            if (is_array($wgRestrictCalendarTo)
                ? count(array_intersect($wgRestrictCalendarTo, $arrGroups)) == 0
                : !in_array($wgRestrictCalendarTo, $arrGroups))
                $params['lockdown'] = true;
        }

        if (isset($wgCalendarDisableRedirects))
            $params['disableredirects'] = true;

        // no need to pass a parameter here... isset check for the params name, thats it
        if (isset($params["lockdown"]))
        {
            $params['disableaddevent'] = true;
            $params['disablelinks'] = true;
        }

        if (isset($params["5dayweek"]))
            $params['monday'] = true;

        // joint calendar...pulling data from our calendar and the subscribers...ie: "title/name" format
        if(isset($params["subscribe"]) && $params["subscribe"] != "subscribe")
            $this->subscribedPages = explode(",", $params["subscribe"]);

        // subscriber only calendar...basically, taking the subscribers identity fully...ie: "title/name" format
        if(isset($params["fullsubscribe"]) && $params["fullsubscribe"] != "fullsubscribe")
        {
            $arrString = explode('/', $params["fullsubscribe"]);
            array_pop($arrString);
            $string = implode('/', $arrString);
            $article = new Article(Title::newFromText( $string ));

            // if the fullsubscribe calendar doesn't exisit, return a warning...
            if(!$article->exists()) return "Invalid 'fullsubscribe' calendar page: <b><i>$string</i></b>";

            $this->calendarPageName = htmlspecialchars($params["fullsubscribe"]);
            $this->isFullSubscribe = true;
        }

        $this->arrSettings = $params;
    }

    // render RSS calendar feed
    function renderFeed()
    {
        $title = Title::newFromText($this->calendarPageName);
        $this->buildLastArticles($this->setting('limit'));
        usort($this->arrEvents, 'WikiCalendar::eventtime_sort');
        $feed = array(
            'name' => wfMsg('calendar-rss-title', $title),
            'info' => CalendarCommon::parse(wfMsgNoTrans('calendar-rss-info', $title)),
            'url'  => $title->getFullUrl(array('action' => 'rss-calendar')),
            'date' => false,
            'items' => array(),
        );
        for ($i = count($this->arrEvents)-1; $i >= 0; $i--)
        {
            $a = $this->arrEvents[$i];
            $article = new Article($a->page);
            $author = $article->getLastNAuthors(1);
            $body = trim($a->body);
            if (!$body)
                $body = $a->eventname;
            $a->page->setFragment('#'.$a->anchor);
            $item = array(
                'title'   => sprintf('%04d-%02d-%02d ', $a->year, $a->month, $a->day) . $a->eventname,
                'text'    => $body,
                'guid'    => $a->page->getFullUrl(),
                'author'  => $author[0],
                'created' => $article->getTimestamp(),
            );
            if (!$feed['date'] || $item['created'] >= $feed['date'])
                $feed['date'] = $item['created'];
            $feed['items'][] = $item;
        }
        return $this->rssFeed($feed);
    }

    // output RSS feed content from an array of data
    function rssFeed($feed)
    {
        $feedStream = new RSSFeed($feed['name'], $feed['info'], $feed['url'], $feed['date']);
        $feedStream->outHeader();
        foreach ($feed['items'] as $item)
            $feedStream->outItem(new FeedItem($item['title'], $item['text'], $item['guid'], $item['created'], $item['author']));
        $feedStream->outFooter();
        return $rss;
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

        if(!$this->setting('disablerecurrences'))
            $this->buildVCalEvents();

        if($this->paramstring != '')
            $this->buildTagEvents($this->paramstring);

        // mode we going into
        if ($userMode == 'year')
            $ret = $this->renderYear();
        elseif ($userMode == 'month')
            $ret = $this->renderMonth();
        elseif ($userMode == 'simplemonth')
            $ret = $this->renderSimpleMonth();
        elseif ($userMode == 'week')
            $ret = $this->renderWeek($this->setting('5dayweek'));
        elseif ($userMode == 'day')
            $ret = $this->renderDate();
        elseif ($userMode == 'events')
            $ret = $this->renderEventList();

        // tag on extra info at the end of whatever is displayed
        $ret .= $this->buildTrackTimeSummary();

        if ($this->prepend_html !== '')
            $ret = $this->prepend_html . $ret;

        $this->buildRssLink();

        return $ret;
    }

    function initalizeHTML()
    {
        wfProfileIn(__METHOD__);
        global $wgOut,$wgScriptPath, $wgVersion;

        if (!empty($this->inithtml_ok))
            return;
        $this->inithtml_ok = true;

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
        if ($dateFormat == 'long')
            $display_day = $weekday . ", " . CalendarCommon::translate($month, 'month_short') . " $day";
        elseif ($dateFormat == 'none')
            $display_day = "";

        if (($thedate['mon'] == $this->actualMonth) &&
            ($thedate['year'] == $this->actualYear) &&
            ($thedate['mday'] == $this->actualDay))
            $tag_wday = "calendarToday";
        elseif ($wday == 0 || $wday == 6)
            $tag_wday = "calendarWeekend";
        else
            $tag_wday = "calendarWeekday";

        $tag_mode = 'monthMode';
        if ($mode == 'events')
        {
            $tag_mode = 'eventsMode';
            $tag_dayCustom = "eventsDay";
        }
        elseif ($mode == 'week')
            $tag_mode = 'weekMode';
        elseif ($mode == 'day')
        {
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

        if($this->setting('dayofyear'))
            $tag_dayweekyear = $this->getDayOfTheYear($month,$day,$year);
        if($this->setting('weekofyear'))
            $tag_dayweekyear .= $this->getWeekOfTheYear($month,$day,$year);

        // kludge... for some reason, the "\n" is removed in full calendar mode
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
            $alert = explode("-", $alerts[$i]);
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

    function loadiCalLink()
    {
        global $wgContLang;
        $ical_value = CalendarCommon::translate('ical_btn');
        $ical_title = CalendarCommon::translate('ical_btn_tip');

        // FIXME remove cookie usage?
        $note = "";
        $cookieName = str_replace(' ', '_', ($this->calendarPageName . "_ical_count"));
        if(isset($_COOKIE[$cookieName]))
        {
            $cnt = $_COOKIE[$cookieName];
            $note = "<font color=red>Completed the import of <b>$cnt</b> record(s).</font>";
            setcookie($cookieName, "", time()-3600);
        }

        $ret = CalendarCommon::translate('ical_inst')
            . "<br /><input type='text' name='ical' value='".$wgContLang->getNsText(NS_FILE).":' />"
            . "<input class='btn' type='submit' title=\"$ical_title\" value=\"$ical_value\">&nbsp;&nbsp;"
            . $note;

        return $ret;
    }

    // build the 'template' button
    function buildConfigLink($bTextLink = false){

        if(!$this->setting('useconfigpage')) return;

        if($this->setting('useconfigpage',false) == 'disablelink') return "";

        $value = CalendarCommon::translate('config_btn');
        $title = CalendarCommon::translate('config_btn_tip');

        $articleConfig = Title::newFromText($this->configPageName)->getFullUrl(array('action' => 'edit'));
        if(!$bTextLink)
            $ret = "<input class='btn' type='button' title='$title' value=\"$value\" onClick=\"javascript:document.location='$articleConfig';\" />";
        else
            $ret = "<a href='$articleConfig'>($value...)</a>";

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

            $this->month = date('m');
            $this->day = date('d');
            $this->year = date('Y');

            $lm = '';
            for ($i = 0; $i < $daysOut; $i++)
            {
                if ($lm != ($nm = sprintf("%04d-%02d", $this->year, $this->month)))
                {
                    $this->initalizeMonth();
                    $lm = $nm;
                }
                $temp = $this->getHTMLForDay($this->month, $this->day, $this->year, 'long', 'events');
                if (strlen(trim($temp)) > 0)
                    $events .= "<tr>" . $temp . "</tr>";
                list($this->month, $this->day, $this->year) = CalendarCommon::datemath(1, $this->month, $this->day, $this->year);
            }

            $ret = "<table width=100%>" . $events . "</table>";
        }
        wfProfileOut(__METHOD__);
        return $ret;
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
            $useDash = explode("-",$setting);
            $useSlash = explode("/",$setting);
            $useDot = explode(".",$setting);
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
            list($this->month, $this->day, $this->year) = CalendarCommon::datemath($shift, date('m'), date('d'), date('Y'));
        wfProfileOut(__METHOD__);
    }

    // specific date mode
    function renderDate()
    {
        wfProfileIn(__METHOD__);
        $this->initalizeHTML();
        $this->initalizeDay();
        $ret = $this->getHTMLForDay($this->month, $this->day, $this->year, 'long', 'day');
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
        global $wgCalendarVersion;

        $this->initalizeMonth();

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

        /***** Build the known tag elements (non-dynamic) *****/
        // set the month's name tag
        if($this->name == 'Public')
            $tag_calendarName = CalendarCommon::translate('default_title');
        else
            $tag_calendarName = $this->name;

        $tag_about = $this->buildAboutLink();

        // set the month's mont and year tags
        $tag_calendarMonth = CalendarCommon::translate($this->month, 'month');
        $tag_calendarYear = $this->year;

        $tag_monthSelect =  $this->buildMonthSelectBox();;
        $tag_yearSelect = $this->buildYearSelectBox();

        $tag_configButton = $this->buildConfigLink(false);

        $style_value = CalendarCommon::translate('styles_btn');
        $style_tip = CalendarCommon::translate('styles_btn_tip');

        if(!$this->setting("disablestyles")){
            $articleStyle = Title::newFromText($this->calendarPageName.'/style')->getFullUrl(array('action' => 'edit'));
            $tag_eventStyleButton = "<input class='btn' type=\"button\" title=\"$style_tip\" value=\"$style_value\" onClick=\"javascript:document.location='$articleStyle';\">";
        }
        $tag_eventStyleButton = $this->buildRssLink() . ' ' . $tag_eventStyleButton;

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
        $tempString = str_replace("[[Version]]", $wgCalendarVersion, $tempString);
        $tempString = str_replace("[[ConfigurationButton]]", $tag_configButton, $tempString);
        $tempString = str_replace("[[TimeTrackValues]]", $tag_timeTrackValues, $tempString);
        $tempString = str_replace("[[Load_iCal]]", $tag_loadiCalButton, $tempString);
        $tempString = str_replace("[[About]]", $tag_about, $tempString);

        $ret .= $tempString;
        $ret = preg_replace('/^[ \t]+/m', '', $ret);

        wfProfileOut(__METHOD__);
        return $ret;
    }

    // this generates the logic and the html to organize where the days go in the "grid"
    function getMonthHTML($month, $year, $simplemonth = false)
    {
        $offset = 1;
        $dayOfWeek = date('N', mktime(12, 0, 0, $month, 1, $year)); // 0-6
        $daysInMonth = CalendarCommon::getDaysInMonth($month,$year); // 28-31
        $weeksInMonth = ceil(($dayOfWeek + $daysInMonth)/7); // 4-6

        if ($this->setting("monday") && !$this->setting("5dayweek"))
            $offset = 2;

        $counter = $theDay = -($dayOfWeek-$offset);
        $ret = "";

        $bfiveDayWeek = $this->setting("5dayweek");

        $p = addslashes($this->calendarPageName);
        $this->addCalendarJS();

        for ($week = 0; $week < $weeksInMonth; $week++)
        {
            $bValidWeek = false;
            $temp = "<tr>";

            for ($day = 0; $day < 7; $day++)
            {
                $bSkipDay = false;

                if ($counter > $daysInMonth)
                    $theDay = 0; // we want these days to be grey or empty...etc

                if (($day == 0 or $day == 6) && $bfiveDayWeek)
                    $bSkipDay = true;

                if (!$bSkipDay)
                {
                    if ($theDay > 0)
                        $bValidWeek = true;

                    if ($simplemonth)
                    {
                        $link = '';
                        $class = array();
                        if ($theDay > 0)
                        {
                            $dd = sprintf("%04d-%02d-%02d", $year, $month, $theDay);
                            if (date('Y-m-d') == $dd)
                                $class[] = 'today';
                            if (empty($this->index['articles'][$dd]))
                                $class[] = 'empty';
                            else
                            {
                                if (count($this->index['articles'][$dd]) > 4)
                                    $class[] = 'more5';
                                $class[] = 'n'.count($this->index['articles'][$dd]);
                            }
                            $link = $theDay;
                        }
                        $temp .= "<td class='yearWeekday";
                        foreach ($class as $c)
                            $temp .= " ydate_$c";
                        $temp .= "'";
                        if ($link)
                            $temp .= " onmouseover='calendarshowdate(this.firstChild,\"$p\",\"$dd\")' onmouseout='calendarhidedate(this)'";
                        $temp .= "><div class='calpopup' style='display:none'></div>$link</td>";
                    }
                    else
                        $temp .= $this->getHTMLForDay($month, $theDay, $year);
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
        $temp = explode($beginString, $html);
        if (count($temp) > 1) {
            $temp = explode($endString, $temp[1]);
            return $temp[0];
        }
        return "";
    }

    // build the day articles into memory
    function initalizeDay()
    {
        wfProfileIn(__METHOD__);

        $range = array();
        $nextday = CalendarCommon::datemath(1, $this->month, $this->day, $this->year);
        list($y, $m, $d) = explode("-", sprintf("%04d-%02d-%02d", $this->year, $this->month, $this->day));
        list($ny, $nm, $nd) = explode("-", sprintf("%04d-%02d-%02d", $nextday[2], $nextday[0], $nextday[1]));
        if ($this->setting('usetemplates'))
        {
            $range[] = array("XXXX-XX-$d",              $nd < $d ? '' : "XXXX-XX-$nd");
            $range[] = array("XXXX-$m-$d", $m == $nm && $nd < $d ? '' : "XXXX-$nm-$nd");
            $range[] = array("$y-XX-$d",   $y == $ny && $nd < $d ? '' : "$ny-XX-$nd");
        }
        $range[] = array("$y-$m-$d", "$ny-$nm-$nd");

        $this->buildRanges($range);

        wfProfileOut(__METHOD__);
    }

    // load the months articles into memory
    function initalizeMonth($month = NULL, $year = NULL)
    {
        wfProfileIn(__METHOD__);
        if (!$month)
            $month = $this->month;
        if (!$year)
            $year = $this->year;
        $min = array($month, 1, $year);
        $max = $min;
        $max[0]++;
        if ($max[0] > 12)
        {
            $max[0] = 1;
            $max[2]++;
        }
        $this->initalizeRange($min, $max);
        wfProfileOut(__METHOD__);
    }

    // load the articles from given range into memory
    // $min, $max are array(m, d, y)
    function initalizeRange($min, $max)
    {
        wfProfileIn(__METHOD__);

        list($sy, $sm, $sd) = explode("-", sprintf("%04d-%02d-%02d", $min[2], $min[0], $min[1]));
        list($ey, $em, $ed) = explode("-", sprintf("%04d-%02d-%02d", $max[2], $max[0], $max[1]));
        $range = array();
        if ($this->setting('usetemplates'))
        {
            // ranges for repeating events
            $range[] = array("XXXX-$sm-$sd", "XXXX-$em-$ed");
            if ($ey == $sy && $em == $sm)
            {
                // simplest case: range of dates within 1 month
                $range[] = array("XXXX-XX-$sd", "XXXX-XX-$ed");
                $range[] = array("$sy-XX-$sd", "$ey-XX-$ed");
            }
            elseif ($em == $sm+1 || $ey == $sy+1 && $em == 1 && $sm == 12)
            {
                // we need to add 2 ranges: (min, 31) and (01, max) instead of simply (min, max)
                // when range crosses 1 month boundary
                // for example: 2008-12-20..2009-01-10 => 2008-XX-20..2008-XX-31 + 2009-XX-01..2009-XX-10
                $range[] = array("XXXX-XX-$sd", "XXXX-XX-31");
                $range[] = array("XXXX-XX-01", "XXXX-XX-$ed");
                $range[] = array("$sy-XX-$sd", "$ey-XX-31");
                $range[] = array("$sy-XX-01", "$ey-XX-$ed");
            }
            else
            {
                $range[] = array("XXXX-XX-01", "XXXX-XX-31");
                $range[] = array("$sy-XX-01", "$ey-XX-31");
            }
        }
        $range[] = array("$sy-$sm-$sd", "$ey-$em-$ed");

        $this->buildRanges($range);

        wfProfileOut(__METHOD__);
    }

    function buildRanges($ranges)
    {
        wfProfileIn(__METHOD__);

        $pages = $this->subscribedPages;
        $pages[] = $this->calendarPageName;
        foreach ($ranges as $r)
            foreach ($pages as $page)
                $this->buildArticleRange(Title::newFromText($page), $r[0], $r[1]);

        wfProfileOut(__METHOD__);
    }

    // builds the last changed events into memory (for RSS)
    function buildLastArticles($n = NULL, $parent = NULL)
    {
        if (!$n || $n < 1)
            $n = 30;
        if (!$parent)
            $parent = Title::newFromText($this->calendarPageName);
        $dbr = wfGetDB(DB_SLAVE);
        $result = $dbr->select('page', 'page_namespace, page_title',
            array('page_namespace' => $parent->getNamespace(), 'page_title LIKE '.$dbr->addQuotes($parent->getDBkey().'/%')),
            __METHOD__,
            array('ORDER BY' => 'page_touched DESC', 'LIMIT' => $n)
        );
        $titles = array();
        while ($row = $dbr->fetchRow($result))
            if ($row = Title::newFromText($row['page_title'], $row['page_namespace']))
                $titles[] = $row;
        $dbr->freeResult($result);
        $this->buildArticles($parent, $titles);
    }

    // builds articles for given range
    function buildArticleRange($parent, $min, $max)
    {
        $this->i++;
        $dbr = wfGetDB(DB_SLAVE);
        $where = array('page_namespace' => $parent->getNamespace());
        if ($min)
            $where[] = 'page_title>='.$dbr->addQuotes(Title::newFromText($parent->getPrefixedText().'/'.$min)->getDBkey());
        if ($max)
            $where[] = 'page_title<'.$dbr->addQuotes(Title::newFromText($parent->getPrefixedText().'/'.$max)->getDBkey());
        $result = $dbr->select('page', 'page_namespace, page_title', $where, __METHOD__);
        $titles = array();
        while ($row = $dbr->fetchRow($result))
            if ($row = Title::newFromText($row['page_title'], $row['page_namespace']))
                $titles[] = $row;
        $dbr->freeResult($result);
        $this->buildArticles($parent, $titles);
    }

    // builds articles from title array
    function buildArticles($parent, $titles)
    {
        $l = strlen($parent->getDBkey())+1;
        foreach ($titles as $page)
        {
            $t = substr($page->getText(), $l);
            if (preg_match('/^(\d{4}|XXXX)-(\d{2}|XX)-(\d{2})/s', $t, $m))
            {
                $is_template = $m[1] == 'XXXX' || $m[2] == 'XX';
                $year = $m[1] == 'XXXX' ? $this->year : intval($m[1]);
                $month = $m[2] == 'XX' ? $this->month : intval($m[2]);
                $day = intval($m[3]);
                $this->addArticle($month, $day, $year, $page, $is_template);
            }
        }
    }

    // this is a general find/replace for the date format
    // users can define whatever format this wish
    // ie: 20090731, 07-01-2009, 07.01.2009, etc
    function userDateFormat($month, $day, $year)
    {
        global $wgCalendarDateFormat;

        if (empty($wgCalendarDateFormat))
            $format = 'YYYY-MM-DD';
        else
            $format = $wgCalendarDateFormat;

        $format = str_ireplace('YYYY',$year,$format);
        $format = str_ireplace('MM', str_pad($month, 2, '0', STR_PAD_LEFT), $format);
        $format = str_ireplace('DD', str_pad($day, 2, '0', STR_PAD_LEFT), $format);
        $format = str_ireplace('D',$day,$format);

        if (stripos($format,'SM') !== false || stripos($format, 'LM') !== false)
        {
            $format = str_ireplace('SM', CalendarCommon::translate($month, 'month_short'), $format);
            $format = str_ireplace('LM', CalendarCommon::translate($month, 'month'), $format);
        }
        else
            $format = str_ireplace('M',$month,$format);

        return $format;
    }

    function buildTagEvents($paramstring)
    {
        $events = explode("\n", trim($paramstring));

        foreach ($events as $event)
        {
            $arr = explode(':', $event);
            $date = array_shift($arr);
            $event = array_shift($arr);

            $body = implode(':',$arr);

            $arrDate = explode('-',$date);

            // we must have a valid date to continue
            if (count($arrDate) < 3)
                break;

            $month = $arrDate[0];
            $day = $arrDate[1];
            $year = $arrDate[2];

            $this->buildEvent($month, $day, $year, $event, $this->title);
        }
    }

    function buildSimpleCalendar($month, $year, $disableNavButtons=false)
    {
        $this->initalizeMonth($month, $year);

        $prev = $next = "";

        $monthname = CalendarCommon::translate($month, 'month_short');
        $monthyear = "<a href='".$this->titleObject->getFullUrl(array('viewSelect' => 'month', 'calendar_info' => $month.'`1`'.$year.'`'.$this->title.'`'.$this->name.'`'))."'>$monthname, $this->year</a>";

        $html = '';
        if (!$disableNavButtons)
        {
            $html .= "<form name='cal_frm' method='post'>";
            $prev = "<input class='btn' name='monthBack' type='submit' value='<<'>";
            $next = "<input class='btn' name='monthForward' type='submit' value='>>'>";
        }

        $title = "<table class='yearCalendarMonth_x' width=100% cellpadding=0 cellspacing=0><td>$prev</td><td>" . $monthyear . "</td><td>$next</td></table>";
        $html .= "<table class='yearCalendarMonth'><tr><td colspan='".($this->setting('5dayweek') ? 5 : 7)."' style='font-size:9px' class='yearTitle'>$title</tr><tr>";

        $n = $this->setting('5dayweek') ? 5 : 7;
        $m = $this->setting('monday') ? 1 : 0;
        for ($i = 0; $i < $n; $i++)
            $html .= "<td class='yearHeading'>" . mb_substr(CalendarCommon::translate(1+($i+$m)%7,'weekday'),0,1) . "</td>";
        $html .= $this->getMonthHTML($month, $year, true);
        $html .= "</table>";
        if (!$disableNavButtons)
        {
            $html .= $this->tag_HiddenData;
            $html .= "</form>";
        }

        return $html;
    }

    function renderYear()
    {
        $tag_mini_cal_year = "";

        $tag_previousYearButton = "<input class='btn' name='yearBack' type='submit' value='<<'>";
        $tag_nextYearButton = "<input class='btn' name='yearForward' type='submit' value='>>'>";

        $styleTitle = "style='text-align:center; font-size:24px; font-weight:bold;'";

        $set = $this->arrSettings;
        foreach ($set as $k => $v)
            if (is_object($v))
                unset($set[$k]);
        if (!empty($set['useconfigpage']))
            $set['useconfigpage'] = 'disablelink';
        $html_head = "<form method='post'><input type='hidden' name='settings' value='".htmlspecialchars(json_encode($set), ENT_QUOTES)."' /><table class='yearCalendar'>";
        $html_foot = "</form></table>";

        $ret = ""; $cal = "";
        $nextMon = 1;
        $nextYear = $this->year;

        $title = "$tag_previousYearButton &nbsp; $this->year &nbsp; $tag_nextYearButton";

        $ret = "<tr><td>" . $this->buildConfigLink(true) . "</td><td $styleTitle colspan=2>$title</td><td align=right>$this->tag_views</td></tr>";

        for ($m = 0; $m < 12; $m++)
        {
            $cal .= "<td style='text-align:center; vertical-align:top;'>" . $this->buildSimpleCalendar($nextMon++, $nextYear, true) . "</td>";
            if ($m == 3 || $m == 7 || $m == 11)
            {
                $ret .= "<tr>$cal</tr>";
                $cal = "";
            }
        }

        return $html_head . $ret . $this->tag_HiddenData . $html_foot;
    }

    function renderWeek($fiveDay = false)
    {
        wfProfileIn(__METHOD__);

        // defaults
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

        // this range may cross month or even year boundary!
        $this->initalizeRange(array($month, $day, $year), CalendarCommon::datemath(7, $month, $day, $year));

        $title = CalendarCommon::translate($month, 'month') . ", " . $year;

        $btnToday = CalendarCommon::translate('today');
        $tag_weekBack = "<input class='btn' name='weekBack' type='submit' value='<<'>";
        $tag_weekForward = "<input class='btn' name='weekForward' type='submit' value='>>'>";
        $tag_todayButton = "<input class='btn' name='today' type='submit' value=\"$btnToday\">";

        if(!$fiveDay)
        {
            $sunday = "<td class='calendarHeading'>" . CalendarCommon::translate(1, 'weekday'). "</td>";
            $saturday = "<td class='calendarHeading'>" . CalendarCommon::translate(7, 'weekday'). "</td>";
            $colspan = 4; // adjust for mode buttons
        }

        // hide mode buttons if selected via parameter tag
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
        $colspan += 3;
        $ret .= "<tr><td></td><td colspan='$colspan' style='font-size: 85%; text-align: right'>".$this->buildRssLink()."</td><td></td></tr>";
        $ret = $html_head . $ret . $this->tag_HiddenData . $html_foot;

        wfProfileOut(__METHOD__);
        return $ret;
    }

    function buildRssLink()
    {
        global $wgOut;
        $title = Title::newFromText($this->calendarPageName);
        $link = $title->getFullUrl(array('action' => 'rss-calendar'));
        if (empty($wgOut->_calendar_links[$link]))
        {
            $wgOut->addLink(array(
                'rel' => 'alternate',
                'type' => 'application/rss+xml',
                'title' => wfMsg('calendar-rss-title', $title),
                'href' => $link,
            ));
            $wgOut->_calendar_links[$link] = true;
        }
        return '<a href="'.htmlspecialchars($link).'">'.wfMsg('calendar-rss-link').'</a>';
    }

    function buildAboutLink()
    {
        $about_translated = CalendarCommon::translate('about');
        return "<a title='$about_translated' href='http://wiki.4intra.net/Calendar_(MediaWiki)' target='_blank'>about</a>...";
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
