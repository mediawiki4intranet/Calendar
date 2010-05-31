<?php

/* Calendar.php
 * - Vitaliy Filippov < vitalif@mail.ru >
 *   (Refactoring and security fixes)
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
    'author'      => 'Eric Fortin',
    'url'         => 'http://www.mediawiki.org/wiki/Extension:Calendar_(Kenyu73)',
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

function wfCalendarExtension()
{
    global $wgParser;
    $wgParser->setHook('calendar', 'wfCalendarDisplay');
}

function wfCalendarRefresh()
{
    global $wgRequest;
    // this is the "refresh" code that allows the calendar to switch time periods
    $v = $wgRequest->getValues();
    if (isset($v["calendar_info"]))
    {
        $today = getdate(); // today
        $temp = split("`", $v["calendar_info"]); // calling calendar info (name,title, etc..)

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
        setcookie($cookie_name, $cookie_value);

        // reload the page..clear any purge commands that may be in it from an ical load...
        $url = str_replace("&action=purge", "", $_SERVER['REQUEST_URI']);
        header("Location: " . $url);
        exit;
    }
}

// called to process <Calendar> tag.
// most $params[] values are passed right into the calendar as is...
function wfCalendarDisplay($paramstring, $params = array(), $parser)
{
    global $wgScript, $wgScriptPath, $wgRequest;
    global $wgTitle, $wgUser;
    global $wgRestrictCalendarTo, $wgCalendarDisableRedirects;
    global $wgCalendarForceNamespace, $wgCalendarDateFormat;

    wfLoadExtensionMessages( 'wfCalendarExtension' );

    wfCalendarRefresh();

    $parser->disableCache();
    $wikiRoot = $wgScript . "?title=";
    $userMode = 'month';

    // grab the page title
    $title = $wgTitle->getPrefixedText();

    $config_page = " ";

    $calendar = null;
    $calendar = new WikiCalendar($wikiRoot);

    $calendar->namespace = $wgTitle->getNsText();

    if(!isset($params["name"])) $params["name"] = "Public";

    $calendar->paramstring = $paramstring;

    // set path
    $params['path'] = str_replace("\\", "/", dirname(__FILE__));

    $name = CalendarCommon::checkForMagicWord($params["name"]);

    // normal calendar...
    $calendar->calendarPageName = "$title/$name";
    $calendar->configPageName = "$title/$name/config";

    if(isset($params["useconfigpage"])) {
        $configs = $calendar->getConfig("$title/$name");

        //merge the config page and the calendar tag params; tag params overwrite config file
        $params = array_merge($configs, $params);
    }

    // just in case i rename some preferences... we can make them backwards compatible here...
    legacyAliasChecks($params);

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

    // set defaults that are required later in the code...
    if(!isset($params["timetrackhead"]))     $params["timetrackhead"] = "Event, Value";
    if(!isset($params["maxdailyevents"]))    $params["maxdailyevents"] = 5;
    if(!isset($params["yearoffset"]))        $params["yearoffset"] = 2;
    if(!isset($params["charlimit"]))         $params["charlimit"] = 25;
    if(!isset($params["css"]))               $params["css"] = "default.css";

    // set secure mode via $wgRestrictCalendarTo global
    // this global is set via LocalSetting.php (ex: $wgRestrictCalendarTo = 'sysop';
    if( isset($wgRestrictCalendarTo) ){
        $arrGroups = $wgUser->getGroups();
        if( is_array($wgRestrictCalendarTo) ){
            if( count(array_intersect($wgRestrictCalendarTo, $arrGroups)) == 0 ){
                $params["lockdown"] = true;
            }
        }
        else{
            if( !in_array($wgRestrictCalendarTo, $arrGroups) ){
                $params["lockdown"] = true;
            }
        }
    }

    if (isset($wgCalendarDisableRedirects))
        $params['disableredirects'] = true;

    // no need to pass a parameter here... isset check for the params name, thats it
    if(isset($params["lockdown"])){
        $params['disableaddevent'] = true;
        $params['disablelinks'] = true;
        $params['locktemplates'] = true;
    }

    if(isset($params["5dayweek"])){
        $params['monday'] = true;
    }

    // this needs to be last after all required $params are updated, changed, defaulted or whatever
    $calendar->arrSettings = $params;

    // joint calendar...pulling data from our calendar and the subscribers...ie: "title/name" format
    if(isset($params["subscribe"]))
        if($params["subscribe"] != "subscribe") $calendar->subscribedPages = split(",", $params["subscribe"]);

    // subscriber only calendar...basically, taking the subscribers identity fully...ie: "title/name" format
    if( isset($params["fullsubscribe"]) ) {
        if($params["fullsubscribe"] != "fullsubscribe") {
            $arrString = explode('/', $params["fullsubscribe"]);
            array_pop($arrString);
            $string = implode('/', $arrString);
            $article = new Article(Title::newFromText( $string ));

            // if the fullsubscribe calendar doesn't exisit, return a warning...
            if(!$article->exists()) return "Invalid 'fullsubscribe' calendar page: <b><i>$string</i></b>";

            $calendar->calendarPageName = htmlspecialchars($params["fullsubscribe"]);
            $calendar->isFullSubscribe = true;
        }
    }

    // finished special conditions; set the $title and $name in the class
    $calendar->setTitle($title);
    $calendar->setName($name);

    $cookie_name = preg_replace('/(\.|\s)/',  '_', ($title . " " . $name)); //replace periods and spaces
    if(isset($_COOKIE[$cookie_name])){
        wfDebug('Calendar: Cookie loaded');

        $arrSession = split("`", $_COOKIE[$cookie_name]);
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
    if(isset($params['date'])) $userMode = 'day';
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

// alias ugly/bad preferences to newer, hopefully better names
function legacyAliasChecks(&$params) {
    if( isset($params['usemultievent']) ) $params['usesectionevents'] = 'usesectionevents';
}

function wfCalendarFunctions_Magic( &$magicWords, $langCode ) {
    $magicWords['calendar'] = array( 0, 'calendar' );
    return true;
}
