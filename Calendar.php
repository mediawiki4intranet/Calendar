<?php

/* Calendar.php
 * - Vitaliy Filippov < vitalif@mail.ru >
 *   (Refactoring, security fixes, RSS)
 *
 * - Original author(s):
 *       Eric Fortin < kenyu73@gmail.com >
 *       Simson L. Garfinkel < simsong@acm.org >
 *       Michael Walters < mcw6@aol.com >
 * See Readme file for full details
 */

# Confirm MW environment
if (!defined('MEDIAWIKI')) {
    die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

$wgCalendarVersion = "v3.8.4 (8/18/2009)";

if (!isset($wgCalendarDateFormat))
    $wgCalendarDateFormat = 'YYYY-MM-DD';

# Credits
$wgExtensionCredits['parserhook'][] = array(
    'name'        => 'Calendar',
    'author'      => 'Vitaliy Filippov, Eric Fortin, Simson Garfinkel, Michael Walters',
    'url'         => 'http://yourcmc.ru/wiki/Calendar_(MediaWiki)',
    'description' => 'MediaWiki Calendar',
    'version'     => $wgCalendarVersion,
);

$path = dirname(__FILE__);

$wgExtensionFunctions[] = "wfCalendarExtension";
$wgExtensionMessagesFiles['wfCalendarExtension'] = "$path/Calendar.i18n.php";
$wgAutoloadClasses['WikiCalendar'] = "$path/Calendar.class.php";
$wgAutoloadClasses['CalendarCommon'] = "$path/Calendar.common.php";
$wgAutoloadClasses['CalendarArticle'] = "$path/Calendar.class.php";
$wgAutoloadClasses['CalendarArticles'] = "$path/Calendar.class.php";
$wgHooks['UnknownAction'][] = 'wfCalendarUnknownAction';
$wgAjaxExportList[] = 'wfCalendarLoadDay';

function wfCalendarExtension()
{
    global $wgParser;
    $wgParser->setHook('calendar', 'wfCalendarDisplay');
}

function wfCalendarLoadDay($title, $date, $parstr)
{
    global $wgRequest;
    wfLoadExtensionMessages('wfCalendarExtension');
    CalendarCommon::parse('');
    $calendar = new WikiCalendar();
    $t = Title::newFromText($title);
    $params = json_decode($parstr);
    if ($params)
        $params = (array)$params;
    else
        $params = array();
    $params['path'] = str_replace("\\", "/", dirname(__FILE__));
    $params['title'] = Title::newFromText($t->prefix($t->getBaseText()));
    $params['name'] = $t->getSubpageText();
    $calendar->config($params);
    $date = explode('-', $date);
    $calendar->day = intval($date[2]);
    $calendar->month = intval($date[1]);
    $calendar->year = intval($date[0]);
    ob_start();
    $response = $calendar->renderDate();
    $response .= ob_get_contents();
    ob_end_flush();
    return $response;
}

function wfCalendarUnknownAction($action, $article)
{
    if ($action == 'rss-calendar')
    {
        global $wgRequest;
        wfLoadExtensionMessages('wfCalendarExtension');
        CalendarCommon::parse('');
        $calendar = new WikiCalendar();
        $params = $wgRequest->getValues();
        $t = $article->getTitle();
        $params['path'] = str_replace("\\", "/", dirname(__FILE__));
        $params['title'] = Title::makeTitleSafe($t->getNamespace(), $t->getBaseText());
        $params['name'] = $t->getSubpageText();
        $calendar->config($params);
        $calendar->renderFeed();
        return false;
    }
    return true;
}

function wfCalendarIsValidMode($mode)
{
    $isValidMode = array('year' => 1, 'month' => 1, 'simplemonth' => 1, 'week' => 1, 'day' => 1, 'events' => 1);
    return $isValidMode[$mode];
}

// FIXME for the future: remove this handler and directly output URLs with correct parameter
function wfCalendarRefresh()
{
    global $wgRequest, $wgTitle, $wgScriptPath;
    // this is the "refresh" code that allows the calendar to switch time periods
    $v = $wgRequest->getValues();
    if (isset($v["calendar_info"]))
    {
        $today = getdate(); // today
        $temp = explode("`", $v["calendar_info"]); // calling calendar info (name, title, etc..)

        // set the initial values
        $month = $temp[0];
        $day = $temp[1];
        $year = $temp[2];
        $title = $temp[3];
        $name = $temp[4];

        // the yearSelect and monthSelect must be on top... the onChange triggers
        // whenever the other buttons are clicked
        if ($v["yearSelect"]) $year = $v["yearSelect"];
        if ($v["monthSelect"]) $month = $v["monthSelect"];

        if (isset($v["yearBack"])) --$year;
        if (isset($v["yearForward"])) ++$year;

        if (isset($v["today"]))
        {
            $day = $today['mday'];
            $month = $today['mon'];
            $year = $today['year'];
        }

        if (isset($v["monthBack"]))
        {
            $year = ($month == 1 ? --$year : $year);
            $month = ($month == 1 ? 12 : --$month);
        }

        if (isset($v["monthForward"]))
        {
            $year = ($month == 12 ? ++$year : $year);
            $month = ($month == 12 ? 1 : ++$month);
        }

        if(isset($v["weekBack"]))
        {
            $arr = getdate(mktime(12, 0, 0, $month, $day-7, $year));
            $month = $arr['mon'];
            $day = $arr['mday'];
            $year = $arr['year'];
        }

        if(isset($v["weekForward"]))
        {
            $arr = getdate(mktime(12, 0, 0, $month, $day+7, $year));
            $month = $arr['mon'];
            $day = $arr['mday'];
            $year = $arr['year'];
        }

        if (wfCalendarIsValidMode($v["viewSelect"]))
            $mode = $v["viewSelect"];

        $p = "cal".crc32("$title $name");
        $v = sprintf("%04d-%02d-%02d-%s", $year, $month, $day, $mode);

        // reload the page... clear any purge commands that may be in it from an ical load...
        $url = $wgTitle->getFullUrl(array($p => $v));
        header("Location: " . $url);
        exit;
    }
}

// called to process <Calendar> tag.
// most $params[] values are passed right into the calendar as is...
function wfCalendarDisplay($paramstring, $params = array(), $parser)
{
    global $wgRequest;
    global $wgTitle, $wgUser;
    global $wgRestrictCalendarTo, $wgCalendarDisableRedirects;
    global $wgCalendarForceNamespace, $wgCalendarDateFormat;

    wfLoadExtensionMessages( 'wfCalendarExtension' );

    wfCalendarRefresh();

    $parser->disableCache();
    $userMode = 'month';

    $calendar = new WikiCalendar();

    // set path
    $params['path'] = str_replace("\\", "/", dirname(__FILE__));

    // normal calendar...
    $params['title'] = $wgTitle;
    $calendar->paramstring = $paramstring;
    $calendar->config($params);
    $title = $params['title']->getPrefixedText();
    $name = $params['name'];

    // if the calendar isn't in a namespace(s) specificed in $wgCalendarForceNamespace, return a warning
    // this can be a string or an array
    if (isset($wgCalendarForceNamespace))
    {
        $ns = $wgCalendarForceNamespace;
        if (!is_array($ns))
            $ns = array($ns);
        if (!in_array($calendar->namespace, $ns) && !isset($params["fullsubscribe"]))
            return CalendarCommon::translate('invalid_namespace') . '<b>'.implode(', ', $ns).'</b>';
    }

    // finished special conditions

    $p = 'cal'.crc32("$title $name");
    if ($v = $wgRequest->getVal($p))
    {
        list($year, $month, $day, $mode) = explode('-', $v, 4);
        $calendar->setYear(0+$year);
        $calendar->setMonth(0+$month);
        $calendar->setDay(0+$day);
        if (wfCalendarIsValidMode($mode))
            $userMode = $mode;
    }
    else
    {
        // defaults from the <calendar /> parameters; must restart browser to enable
        if(isset($params['week'])) $userMode = 'week';
        if(isset($params['year'])) $userMode = 'year';
    }

    if(isset($params['useeventlist'])) $userMode = 'events';
    if(isset($params['date']) && strtolower($params['date']) != 'off') $userMode = 'day';
    if(isset($params['simplemonth'])) $userMode = 'simplemonth';

    if (($ical = $wgRequest->getText('ical')) &&
        ($title = Title::newFromText($ical, NS_FILE)) &&
        ($img = wfLocalFile($title)))
    {
        $calendar->load_iCal($img->getFullPath());
        // refresh the calendar's newly added events
        $calendar->invalidateCache = true;
    }

    $render = $calendar->renderCalendar($userMode);

    // purge main calendar before displaying the calendar
    if($calendar->invalidateCache){
        $article = new Article(Title::newFromText($title));
        $article->purge();
        header("Location: " . $wikiRoot . $title);
    }

    return $render;
}

function wfCalendarFunctions_Magic( &$magicWords, $langCode ) {
    $magicWords['calendar'] = array( 0, 'calendar' );
    return true;
}
