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

$gCalendarVersion = "v3.8.4 (8/18/2009)";

if (!$wgCalendarDateFormat)
    $wgCalendarDateFormat = 'D.M.YYYY';

# Credits
$wgExtensionCredits['parserhook'][] = array(
    'name'        => 'Calendar',
    'author'      => 'Vitaliy Filippov, Eric Fortin, Simson Garfinkel, Michael Walters',
    'url'         => 'http://yourcmc.ru/wiki/Calendar_(MediaWiki)',
    'description' => 'MediaWiki Calendar',
    'version'     => $gCalendarVersion,
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
        $params['title'] = Title::newFromText($t->prefix($t->getBaseText()));
        $params['name'] = $t->getSubpageText();
        $calendar->config($params);
        $calendar->renderFeed();
        return false;
    }
    return true;
}

function wfCalendarRefresh()
{
    global $wgRequest, $wgTitle, $wgScriptPath;
    // this is the "refresh" code that allows the calendar to switch time periods
    $v = $wgRequest->getValues();
    if (isset($v["calendar_info"]))
    {
        $today = getdate(); // today
        $temp = explode("`", $v["calendar_info"]); // calling calendar info (name,title, etc..)

        // set the initial values
        $month = $temp[0];
        $day = $temp[1];
        $year = $temp[2];
        $title = $temp[3];
        $name = $temp[4];

        // the yearSelect and monthSelect must be on top... the onChange triggers
        // whenever the other buttons are clicked
        if(isset($v["yearSelect"])) $year = $v["yearSelect"];
        if(isset($v["monthSelect"])) $month = $v["monthSelect"];

        if(isset($v["yearBack"])) --$year;
        if(isset($v["yearForward"])) ++$year;

        if(isset($v["today"])){
            $day = $today['mday'];
            $month = $today['mon'];
            $year = $today['year'];
        }

        if(isset($v["monthBack"])){
            $year = ($month == 1 ? --$year : $year);
            $month = ($month == 1 ? 12 : --$month);
        }

        if(isset($v["monthForward"])){
            $year = ($month == 12 ? ++$year : $year);
            $month = ($month == 12 ? 1 : ++$month);
        }

        if(isset($v["weekBack"])){
            $arr = getdate( mktime(12, 0, 0,$month, $day-7, $year) );
            $month = $arr['mon'];
            $day = $arr['mday'];
            $year = $arr['year'];
        }

        if(isset($v["weekForward"])) {
            $arr = getdate( mktime(12, 0, 0,$month, $day+7, $year) );
            $month = $arr['mon'];
            $day = $arr['mday'];
            $year = $arr['year'];
        }

        if(isset($v["viewSelect"])){
            $mode = $v["viewSelect"];
        }

        $cookie_name = preg_replace('/(\.|\s)/',  '_', ($title . " " . $name)); // replace periods and spaces
        $cookie_value = $month . "`" . $day . "`" . $year . "`" . $title . "`" . $name . "`" . $mode . "`";
        setcookie($cookie_name, $cookie_value, time()+86400*365, $wgScriptPath);

        // reload the page..clear any purge commands that may be in it from an ical load...
        $url = $wgTitle->getFullUrl();
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
    if(isset($wgCalendarForceNamespace)){
        if(is_array($wgCalendarForceNamespace)){
            if(!in_array($calendar->namespace,$wgCalendarForceNamespace)  && !isset($params["fullsubscribe"]) ) {

                $namespaces = "";
                foreach($wgCalendarForceNamespace as $namespace){
                    $namespaces .= $namespace . ", ";
                }

                return CalendarCommon::translate('invalid_namespace') . '<b>'.$namespaces.'</b>';
            }
        }
        else if ( $wgCalendarForceNamespace != $calendar->namespace  && !isset($params["fullsubscribe"]) ){
            return CalendarCommon::translate('invalid_namespace') . '<b>'.$wgCalendarForceNamespace.'</b>';
        }
    }

    // finished special conditions

    $cookie_name = preg_replace('/(\.|\s)/',  '_', ($title . " " . $name)); //replace periods and spaces
    if(isset($_COOKIE[$cookie_name])){
        wfDebug('Calendar: Cookie loaded: '.$_COOKIE[$cookie_name]."\n");

        $arrSession = explode("`", $_COOKIE[$cookie_name]);
        $calendar->setMonth($arrSession[0]);
        $calendar->setDay($arrSession[1]);
        $calendar->setYear($arrSession[2]);
        $calendar->setTitle($arrSession[3]);
        $calendar->setName($arrSession[4]);

        if(strlen($arrSession[5]) > 0)
            $userMode = $arrSession[5];
    }
    else{
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
