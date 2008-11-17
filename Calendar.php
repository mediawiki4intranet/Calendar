<?php
/* Calendar.php
 * Version 2.0.5
 * - Corrected typo in line 541; thanks for Nilson Silva de Morais
 *  Filho for the bugfix!
 * Version 2.0.4
 * - corrected typos in lines 430 and 529; thanks to Andreas Holen for
 * the bug fix!
 *
 * Version 2.0.3
 *
 *  Description:
 *   This extension implements a calendar in PHP, designed to integrate into 
 *   the MediaWiki wiki.  This calendar supports custom themes 
 *   (via a template), generation of wiki articles for events, listing the 
 *   events for a day including a "summary" of the events, and allows you to
 *   name calendars to make them unique or to share them within multiple
 *   articles.
 * 
 * Use:
 *   To use this calendar, simply place a calendar tag into an article:
 *   <calendar></calendar>
 * 
 *   If you wish to name your calendar, you can add a name attribute.
 *   <calendar name="IT Calendar"></calendar>
 *   By giving a calendar a name, the events won't show up on other calendars
 *   with different names or no name.
 * 
 *   Note, the names have to be simple and valid for article titles.  Some
 *   characters will cause the attribute to be ignored completely, like an
 *   apostrophe.
 * 
 *   Other attributes:
 *   startyear - The first year to show on the calendar, defaults to this year.
 *   yearsahead - The number of years to include ahead of the current year,
 *                defaults to 3.
 * 
 *   noadd="1"  - Prevents the "Add Event" tag from being displayed.
 *              - You could then have one calendar which displays the Add events
 *              - and another which doesnt:
 *              - <calendar name="MyCalendar"></calendar> - shows the Add Event
 *              - <calendar name="MyCalendar" noadd="1"></calendar> - shows same calendar w/o Add Event
 * 
 *   date="2007-07-04" - just show the calendar for July 4, 2007
 *   date="today"      - just show the calendar for today
 *   date="tomorrow"   - just show the calendar for tomorrow
 *
 *   Each event can contain multiple entries, like this:
 *       ==foo== 
 *       first entry
 *       ==bar==
 *       second entry

 * Templating:
 *   You are required to use a template file for the calendar.  The template
 *   file is named "calendar_template.html" and should be placed in the
 *   extensions folder of MediaWiki.  You should have gotten a basic template
 *   with this code.  You can use this template as a guide to making your own
 *   calendars.  Note, all the sections are important and the comment tags such
 *   as "<!-- Monday Start -->" are very important and must remain in the
 *   template.  They define the boundaries of the HTML to pull when generating
 *   the calendar code.  More information can be found in the included template
 *   file.
 * 
 * Article Summaries:
 *   When you add events to the calendar, it is possible to have the calendar
 *   pull "summaries" of the articles for display on the calendar.  This is
 *   done by pulling the first section heading and the text within that
 *   section.  The heading is displayed on the calendar and the text within
 *   that section is displayed with a mouse-over, via the title attribute of
 *   the anchor tag.  The lengths are truncated if too long, 25 characters for
 *   the event title and 100 characters for the mouse-over text.
 * 
 *   So when you create an article, you can do something like this:
 *   == Event Title ==
 *   Some informative text for the event to display on mouse-over.
 *   blah blah blah...
 * 
 * Notes:
 *   Although this calendar works well, it has not been tested much.  There may
 *   be some input that does break it or that does not work well.  If you find
 *   anything that breaks the calendar, let me know.  Something I do not have
 *   much control over if its handled by MediaWiki.  This has been tested
 *   against version 1.7.1 and 1.8.2.  If you have problems running it on newer
 *   versions, let me know and I will try to get it updated.
 * 
 *   Since all the links for the events are not contained within an article,
 *   they will all be included in your orphaned page list.  There's no good
 *   way around this.
 * 
 *   For abuse protection, the code limits a max of 20 events per day.  You
 *   shouldn't need more than this, but if you do, you will need to modify the
 *   code in the getArticlesForDay() function, and the code at the end of the
 *   CalendarAdjust.php file.  Really, you may want to categorize your
 *   calendars using names if you have a lot of events you are adding to a
 *   day.
 * 
 * Included Files:
 *   Below is a list of files included with this calendar extension.  These
 *   files need to exist for this extension to work.
 *   - Calendar.php
 *   - CalendarAdjust.php
 *   - calendar_template.html
 *
 * Author Notes:
 *   Maintainer: Simson L. Garfinkel < simsong at acm.org >
 *   Originally Written By: Michael Walters < mcw6 at aol.com > 
 * 
 * Functions:
 * class Calendar - the class
 *   html_week_array - returns the week
 *   getDaysInMonth($month) - number of days in $month (1..12)
 *   Calendar()      - constructor
 *   getHTMLForDay($month,$day,$year) - returns HTML for that day
 *   getHTMLForMonth()                - returns HTML for the 'current' month
 *   searchHTML()    - returns HTml that appears between two search strings
 *   stripLeadingSpace()
 *   getArticlesForDay($month,$day,$year) - returns array of articles
 *   articleLink($title,$text0       - returns a link to a given article
 *   getSummariesForArticle - returns all of the bullets for a given article
 *   setMonth / getMonth 
 *   setYear / getYear
 *   setTitle / getTitle
 *   setName / getName
 *   setStartYear  
 *   setYearsAhead
 * displayCalendar() - called by the <Calendar> tag.
 */

$wgExtensionFunctions[] = "wfCalendarExtension";

// function adds the wiki extension
function wfCalendarExtension() {
    global $wgParser;
    $wgParser->setHook( "calendar", "displayCalendar" );
}

// path to the root of the web server
$scriptfn = $_SERVER["SCRIPT_FILENAME"];
$wgLocalPath = substr($scriptfn, 0, strlen($scriptfn) - strlen($_SERVER["SCRIPT_NAME"]));
$wgLocalPath = str_replace("\\", "/" , $wgLocalPath);

//for debugging:
//$e = fopen('php://stderr','w');


class Calendar
{   
    /*
     *  Constructor for the Calendar class
     * The first will be internationalized at some point. 
     */
    var $title      = "Calendar";    // the title of the page the calendar is on
    var $dayNames   = array("Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday");
    var $monthNames = array("January", "February", "March", "April", "May", "June",
                            "July", "August", "September", "October", "November", "December");



    var $month = 1;    // the current month
    var $name = "";    // the name of the calendar (to make it unique)
    var $calendarStartYear = 2006;    // the start year
    var $yearsAhead = 3;    // the number of years to include ahead of this year
	
    function html_week_array($format){
	$ret = array();
	for($i=0;$i<7;$i++){
	    $ret[$i] = $this->searchHTML($this->html_template,
					 sprintf($format,$this->dayNames[$i],"Start"),
					 sprintf($format,$this->dayNames[$i],"End"));
	}
	return $ret;
    }

    // Calculate the number of days in a month, taking into account leap years.
    var $daysInMonth = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);   
    function getDaysInMonth($year,$month) {	// Leap year rule good through 3999
        if ($month < 1 || $month > 12) return 0;
        $d = $this->daysInMonth[$month - 1];
        if ($month == 2 && $year%4==0) {
	    $d = 29;
	    if ($year%100 == 0 && $year%400 != 0) $d = 28;
	}
        return $d;
    }

    function Calendar() {
	$today = getdate();    	// set the calendar's date
	$this->trimCount = 50;
    	$this->month = $today['mon'];
    	$this->year = $today['year'];
    	$this->calendarStartYear = $this->year;
	$this->displayDay = false;		/* default - don't display just a single day */

	$this->calendarTemplate = str_replace("\\", "/", dirname(__FILE__));
	$this->calendarTemplate .= "/calendar_template.html";
	$this->html_template = file_get_contents($this->calendarTemplate);

	$this->daysNormalHTML   = $this->html_week_array("<!-- %s %s -->");
	$this->daysSelectedHTML = $this->html_week_array("<!-- Selected %s %s -->");
	$this->daysMissingHTML  = $this->html_week_array("<!-- Missing %s %s -->");

	$uri= $_SERVER['REQUEST_URI'];
	$pos = strpos($uri,'index.php');
	$this->calendarAdjustPath = substr($uri,0,$pos) . "extensions/Calendar/CalendarAdjust.php";
    }

    // Generate the HTML for a given month
    // $day may be out of range; if so, give blank HTML
    function getHTMLForDay($month,$day,$year){
	global $wgScriptPath;
	if ($day <=0 || $day > $this->getDaysInMonth($year,$month)){
	    return $this->daysMissingHTML[0];
	}

	$thedate = getdate(mktime(12, 0, 0, $month, $day, $year));
	$today = getdate();
	$wday  = $thedate['wday'];

	if ($thedate['mon'] == $today['mon']
	    && $thedate['year'] == $today['year']
	    && $thedate['mday'] == $today['mday']) {
	    $tempString = $this->daysSelectedHTML[$wday];
	}
	else {
	    $tempString = $this->daysNormalHTML[$wday];
	}
  				
	// add event link value
	if($this->showAddEvent){
	    $tag_addEvent = "<a href=\"" . $this->calendarAdjustPath . "?day=" . ($day) .
		"&month=" . $month . "&year=" . $year . "&title=" . urlencode($this->title) . 
		"&name=" . urlencode($this->name) . "&path=" . urlencode($wgScriptPath) . 
		"\">Add Event</a>";
	}
	else {
	    $tag_addEvent = "";
	}
	    
	// event list tag
	$events = $this->getArticlesForDay($month, $day, $year);
	$tag_eventList = "";
	if (count($events) > 0) {
	    $tag_eventList .= "<ul>";
	    for ($k = 0; $k < count($events); $k++){
		$summaries = $this->getSummariesForArticle($events[$k]);
		for($j = 0; $j < count($summaries); $j++){
		    $tag_eventList .= "<li>" . $summaries[$j] . "</li>\n";
		}
	    }
	    $tag_eventList .= "</ul>";
	}
	    
	// replace variable tags in the string
	$tempString = str_replace("[[Day]]", $day, $tempString);
	$tempString = str_replace("[[AddEvent]]", $tag_addEvent, $tempString);
	$tempString = str_replace("[[EventList]]", $tag_eventList, $tempString);
	return $tempString;
    }

    function getHTMLForMonth() {   
	    global $wgLocalPath;
       	
	    /***** Replacement tags *****/

	    $tag_monthSelect = "";         // the month select box [[MonthSelect]] 
	    $tag_previousMonthButton = ""; // the previous month button [[PreviousMonthButton]]
	    $tag_nextMonthButton = "";     // the next month button [[NextMonthButton]]
	    $tag_yearSelect = "";          // the year select box [[YearSelect]]
	    $tag_previousYearButton = "";  // the previous year button [[PreviousYearButton]]
	    $tag_nextYearButton = "";      // the next year button [[NextYearButton]]
	    $tag_calendarName = "";        // the calendar name [[CalendarName]]
	    $tag_calendarMonth = "";       // the calendar month [[CalendarMonth]]
	    $tag_calendarYear = "";        // the calendar year [[CalendarYear]]
	    $tag_day = "";                 // the calendar day [[Day]]
	    $tag_addEvent = "";            // the add event link [[AddEvent]]
	    $tag_eventList = "";           // the event list [[EventList]]
        
	    /***** Calendar parts (loaded from template) *****/

	    $html_calendar_start = "";     // calendar pieces
	    $html_calendar_end = "";
	    $html_header = "";             // the calendar header
	    $html_day_heading = "";        // the day heading
	    $html_week_start = "";         // the calendar week pieces
	    $html_week_end = "";
	    $html_footer = "";             // the calendar footer

	    /***** Other variables *****/

	    $ret = "";          // the string to return

	    // the date for the first day of the month
	    $firstDate = getdate(mktime(12, 0, 0, $this->month, 1, $this->year));

	    $first = $firstDate["wday"];   // the first day of the month

	    $today = getdate();    	// today's date
	    $isSelected = false;    	// if the day being processed is today
	    $isMissing = false;    	// if the calendar cell being processed is in the current month
    	
	    // the path to this extension (install location)
	    $calendarExtensionPath = str_replace("\\", "/", 
						 substr(dirname(__FILE__), strlen($wgLocalPath)));

	    // referrer (the page with the calendar currently displayed)
	    $referrerURL = $_SERVER['PHP_SELF'];
	    if ($_SERVER['QUERY_STRING'] != '') {
    		$referrerURL .= "?" . $_SERVER['QUERY_STRING'];
	    }
	    // the path to the CalendarAdjust.php file

    	
	    /***** Build the known tag elements (non-dynamic) *****/
	    // set the month's name tag
	    $tag_calendarName = str_replace('_', ' ', $this->name);
	    if ($tag_calendarName == "") {
    		$tag_calendarName = "Calendar";
	    }
    	
	    // set the month's mont and year tags
	    $tag_calendarMonth = $this->monthNames[$this->month - 1];
	    $tag_calendarYear = $this->year;
    	
	    // build the month select box
	    $tag_monthSelect = "<select onChange=\"javascript:document.location='" . 
		$this->calendarAdjustPath . "?month=' + this.options[this.selectedIndex].value + '&year=" .
		$this->year . "&title=" . urlencode($this->title) . "&name=" . urlencode($this->name) .
		"&referer=" . urlencode($referrerURL) . "';\">\n";
	    for ($i = 0; $i < count($this->monthNames); $i += 1) {
    		if ($i + 1 == $this->month) {
		    $tag_monthSelect .= "<option value=\"" . ($i + 1) . "\" selected=\"true\">" . 
			$this->monthNames[$i] . "</option>\n";
    		}
    		else {
		    $tag_monthSelect .= "<option value=\"" . ($i + 1) . "\">" . 
			$this->monthNames[$i] . "</option>\n";
    		}
	    }
	    $tag_monthSelect .= "</select>";
    	
	    // build the year select box, with +/- 5 years in relation to the currently selected year
	    $tag_yearSelect = "<select onChange=\"javascript:document.location='" . 
		$this->calendarAdjustPath . 
		"?year=' + this.options[this.selectedIndex].value + '&month=" . 
		$this->month . "&title=" . urlencode($this->title) . 
		"&name=" . urlencode($this->name) . "&referer=" . urlencode($referrerURL) . "';\">\n";
	    for ($i = $this->calendarStartYear; $i <= $today['year'] + $this->yearsAhead; $i += 1) {
    		if ($i == $this->year) {
		    $tag_yearSelect .= "<option value=\"" . $i . "\" selected=\"true\">" . 
			$i . "</option>\n";
    		}
    		else {
		    $tag_yearSelect .= "<option value=\"" . $i . "\">" . $i . "</option>\n";
    		}
	    }
	    $tag_yearSelect .= "</select>";
    	
	    // build the previous month button
	    $tag_previousMonthButton = "<input type=\"button\" value= \"<<\" onClick=\"javascript:document.location='" . 
		$this->calendarAdjustPath . "?year=" . $this->year . 
		"&month=" . ($this->month == 1 ? 12 : $this->month - 1) . 
		"&title=" . urlencode($this->title) . "&name=" . urlencode($this->name) . 
		"&referer=" . urlencode($referrerURL) . "';\">";
    	
	    // build the next month button
	    $tag_nextMonthButton = "<input type=\"button\"  value= \">>\" onClick=\"javascript:document.location='" . 
		$this->calendarAdjustPath . "?year=" . $this->year .
		"&month=" . ($this->month == 12 ? 1 : $this->month + 1) .
		"&title=" . urlencode($this->title) . "&name=" . urlencode($this->name) .
		"&referer=" . urlencode($referrerURL) . "';\">";
    	
	    // build the previous year button
	    $tag_previousYearButton = "<input type=\"button\" value= \"<<\" onClick=\"javascript:document.location='" .
		$this->calendarAdjustPath .
		"?year=" . ($this->year == $this->calendarStartYear ? $this->calendarStartYear : $this->year - 1) .
		"&month=" . $this->month . "&title=" . urlencode($this->title) .
		"&name=" . urlencode($this->name) . "&referer=" . urlencode($referrerURL) . "';\">";
    	
	    // build the next year button
	    $tag_nextYearButton = "<input type=\"button\"  value= \">>\" onClick=\"javascript:document.location='" .
		$this->calendarAdjustPath .
		"?year=" . ($this->year == $today['year'] + $this->yearsAhead ? $today['year'] + $this->yearsAhead : $this->year + 1) .
		"&month=" . $this->month . "&title=" . urlencode($this->title) .
		"&name=" . urlencode($this->name) . "&referer=" . urlencode($referrerURL) . "';\">";
    	
	    /***** load the html code pieces from the template *****/
	    // load the template file

    	
	    // grab the HTML for the calendar
	    // calendar pieces
	    $html_calendar_start = $this->searchHTML($this->html_template, 
						     "<!-- Calendar Start -->", "<!-- Header Start -->");
	    $html_calendar_end = $this->searchHTML($this->html_template,
						   "<!-- Footer End -->", "<!-- Calendar End -->");;
	    // the calendar header
	    $html_header = $this->searchHTML($this->html_template,
					     "<!-- Header Start -->", "<!-- Header End -->");
	    // the day heading
	    $html_day_heading = $this->searchHTML($this->html_template,
						  "<!-- Day Heading Start -->",
						  "<!-- Day Heading End -->");
	    // the calendar week pieces
	    $html_week_start = $this->searchHTML($this->html_template,
						 "<!-- Week Start -->", "<!-- Sunday Start -->");
	    $html_week_end = $this->searchHTML($this->html_template,
					       "<!-- Saturday End -->", "<!-- Week End -->");
	    // the individual day cells
        
	    // the calendar footer
	    $html_footer = $this->searchHTML($this->html_template,
					     "<!-- Footer Start -->", "<!-- Footer End -->");
    	
	    /***** Begin Building the Calendar (pre-week) *****/    	
	    // add the header to the calendar HTML code string
	    $ret .= $html_calendar_start;
	    $ret .= $html_header;
	    $ret .= $html_day_heading;
    	
	    /***** Search and replace variable tags at this point *****/
	    $ret = str_replace("[[MonthSelect]]", $tag_monthSelect, $ret);
	    $ret = str_replace("[[PreviousMonthButton]]", $tag_previousMonthButton, $ret);
	    $ret = str_replace("[[NextMonthButton]]", $tag_nextMonthButton, $ret);
	    $ret = str_replace("[[YearSelect]]", $tag_yearSelect, $ret);
	    $ret = str_replace("[[PreviousYearButton]]", $tag_previousYearButton, $ret);
	    $ret = str_replace("[[NextYearButton]]", $tag_nextYearButton, $ret);
	    $ret = str_replace("[[CalendarName]]", $tag_calendarName, $ret);
	    $ret = str_replace("[[CalendarMonth]]", $tag_calendarMonth, $ret); 
	    $ret = str_replace("[[CalendarYear]]", $tag_calendarYear, $ret);    	
    	
	    /***** Begin building the calendar days *****/
	    // determine the starting day offset for the month
	    $dayOffset = -$first + 1;
	    
	    // determine the number of weeks in the month
	    $numWeeks = floor(($this->getDaysInMonth($this->year,$this->month) - $dayOffset + 6) / 7);  	

	    // begin writing out month weeks
	    for ($i = 0; $i < $numWeeks; $i += 1) {

		$ret .= $html_week_start;		// write out the week start code
  			
		// write out the days in the week
		for ($j = 0; $j < 7; $j += 1) {
		    $ret .= $this->getHTMLForDay($this->month,$dayOffset,$this->year);
		    $dayOffset += 1;
		}
		$ret .= $html_week_end; 		// add the week end code
	    }
  		
	    /***** Do footer *****/
	    $tempString = $html_footer;
  		
	    // replace potential variables in footer
	    $tempString = str_replace("[[MonthSelect]]", $tag_monthSelect, $tempString);
	    $tempString = str_replace("[[PreviousMonthButton]]", $tag_previousMonthButton, $tempString);
	    $tempString = str_replace("[[NextMonthButton]]", $tag_nextMonthButton, $tempString);
	    $tempString = str_replace("[[YearSelect]]", $tag_yearSelect, $tempString);
	    $tempString = str_replace("[[PreviousYearButton]]", $tag_previousYearButton, $tempString);
	    $tempString = str_replace("[[NextYearButton]]", $tag_nextYearButton, $tempString);
	    $tempString = str_replace("[[CalendarName]]", $tag_calendarName, $tempString);
	    $tempString = str_replace("[[CalendarMonth]]", $tag_calendarMonth, $tempString);    	
	    $tempString = str_replace("[[CalendarYear]]", $tag_calendarYear, $tempString);
		
	    $ret .= $tempString;
  		
	    /***** Do calendar end code *****/
	    $ret .= $html_calendar_end;
    	
	    // return the generated calendar code
	    return $this->stripLeadingSpace($ret);  	
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
    
    // returns an array of existing article names for a specific day
    function getArticlesForDay($month, $day, $year) {
    	$articleName = "";    	// the name of the article to check for
    	$articleCount = 0;    	// the article count
    	$articleArray = array();    	// the array of article names

	// Loop for articles that may have been deleted
	for ($i = 0; $i <= 20; $i++) {
	    $articleName = $this->title . "/Calendar_";
	    if ($this->name != "") {
		$articleName .= "\"" . $this->name . "\"_";			
	    }
	    $articleName .= "(" . $month . "-" . $day . "-" . $year . ")_-_Event_" . $i;
	    $article = new Article(Title::newFromText($articleName));
	    if ($article->exists()) {	    // save name
		$articleArray[$articleCount] = $article;
		$articleCount += 1;
	    }
    	}
    	return $articleArray;
    }
    
    // returns the link for an article, along with summary in the title tag, given a name
    function articleLink($title,$text){
	global $wgScript;		/* wiki CGI url */
		
	if(strlen($text)==0) $text="Event";
	if(strlen($text) > $this->trimCount) {
	    $text = substr($text,0,$this->trimCount-3) . "...";
	}
	// old syntax:
	// return "<a href='" . $wgScript . "?title=" . htmlspecialchars($title->getText()) . ">" . htmlspecialchars($text) . "</a>";
        // new syntax:			   
	return "<a href='" . $wgScript . "/" . htmlspecialchars($title->getText()) . "'>" . htmlspecialchars($text) . "</a>";
    }

    function getSummariesForArticle($article) {
		
	/* $title = the title of the wiki article of the event.
	 * $displayText = what is displayed
	 */
	$redirectCount = 0;
	while($article->isRedirect() && $redirectCount < 10){
	    $redirectedArticleTitle = Title::newFromRedirect($article->getContent());
	    $article = new Article($redirectedArticleTitle);
	    $redirectCount += 1;
	}

	$title        = $article->getTitle();
	$displayText  = $article->fetchContent(0,false,false);

	// $displayText is the text that is displayed for the article.
	// if it has any ==headings==, return an array of them.
	// otherwise return the first line.

	$ret = array();
	$lines = split("\n",$displayText);
	for($i=0;$i<count($lines);$i++){
	    $line = $lines[$i];
	    if(substr($line,0,2)=='=='){
		$head = split("==",$line);
		$ret[count($ret)] = $this->articleLink($title,$head[1]);
	    }
	}
	if(count($ret)==0){
	    $ret[0] = $this->articleLink($title,$lines[0]);
	}

	return $ret;
    }
	
    // Set/Get accessors
    function setMonth($month) { $this->month = $month; } /* currently displayed month */
    function getMonth() { return $this->month; }
	
    function setYear($year) { $this->year = $year; } /* currently displayed year */
    function getYear() { return $this->year; }
	
    function setTitle($title) { $this->title = str_replace(' ', '_', $title); }
    function getTitle() { return $this->title; }
	
    function setName($name) { $this->name = str_replace(' ', '_', $name); }
    function getName() { return $this->name; }
	
    function setStartYear($year) { $this->calendarStartYear = $year; }
    function setYearsAhead($years) { $this->yearsAhead = $years; }
}

/** called to process <Calendar> tag.
 **/
function displayCalendar($paramstring = "", $params = array()) {
    global $wgParser;
	
    $wgParser->disableCache();
	
    // grab the page title
    if (defined('MAG_PAGENAME')) {
	$title = $wgParser->getVariableValue(MAG_PAGENAME);
    }
    else {
	$title = $wgParser->getVariableValue("pagename");
    }
	
    // check for the calendar "name" parameter.
    $name = "";
    if (isset($params["name"])) {
	$name = $params["name"];
    }
	
    $calendar = null;    // the calendar
	
    // generate the cookie name
    $cookie_name = 'calendar_' . str_replace(' ', '_', $title) . str_replace(' ', '_', $name);
	
    // check if this user has a calendar saved in their session	
    if (isset($_COOKIE[$cookie_name])) {
	$temp = split("`", $_COOKIE[$cookie_name]);
	$calendar = new Calendar();
	$calendar->setMonth($temp[0]);
	$calendar->setYear($temp[1]);
	$calendar->setTitle($temp[2]);
	$calendar->setName($temp[3]);
    }
    else {
	$calendar = new Calendar();
	$calendar->setTitle($title);
	$calendar->setName($name);
	// save the calendar back into the session
	setcookie($cookie_name, $calendar->getMonth() . "`" . $calendar->getYear() . "`" .
		  $calendar->getTitle() . "`" . $calendar->getName(), 0, "/", '');
    }
	
    // check for the "startyear" parameter
    if (isset($params["startyear"])) {
	$calendar->setStartYear($params["startyear"]);
    }
	
    // check for the "yearsahead" parameter
    if (isset($params["yearsahead"])) {
	$calendar->setYearsAhead($params["yearsahead"]);
    }
	
    // check for date parameter, which does a calendar on a specific date.
    if (isset($params["date"])) {
	$calendar->displayDay = $params["date"];
	$calendar->trimCount = 100;
	if (($calendar->displayDay == "today") || ($calendar->displayDay=="tomorrow")){
	    $calendar->year  = $today['year'];
	    $calendar->month = $today['mon'];
	    $calendar->mday  = $today['mday'];
	    if ($calendar->displayDay == "tomorrow" ){
		$calendar->mday++;
		if($calendar->mday > $this->getDaysInMonth($calendar->year,$calendar->month)){
		    $calendar->mday = 1;
		    $calendar->month++;
		    if($calendar->month > 12){
			$calendar->month = 1;
			$calendar->year++;
		    }
		}
	    }
	}
	else {
	    $temp = split("-",$params["date"]);
	    $calendar->year = $temp[0];
	    $calendar->month = $temp[1] + 0; /* converts to integer */
	    $calendar->day   = $temp[2] + 0;
	}
    }

    $calendar->showAddEvent = true;
    if (isset($params["noadd"])) {
	$calendar->showAddEvent = false;
    }

    if ($calendar->displayDay ) {
	return "<html>"
	    . $calendar->getHTMLForDay($calendar->month,$calendar->day,$calendar->year)
	    . "</html>";
    }

    return "<html>" . $calendar->getHTMLForMonth() . "</html>";
}
?>
