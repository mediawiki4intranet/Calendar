<?php

# examples:
#       <dateConverter pagename='Calendars:TeamPage' /> - defaults to 'Public' and YYYYMMDD
#       <dateConverter pagename='Calendars:TeamPage' calname='Team Vacation' />
#       <dateConverter pagename='Calendars:TeamPage' calname='Team Vacation' newformat='LM D, YYYY' /> //ex: July 1, 2009
#
#   require_once( "$IP/extensions/Calendar/CalendarDateConverter.php" );
#
#   newformat: format of converted events (YYYY MM DD M D SM LM) SM=short month, LM=longmonth
#   pagename: wikipage name
#   calname: calendar name (default: Public)
#   redirect: add redirects to the old page name (default: no redirects)
#
#   Note: if the desination page already exists during the convert/move, it will report as an invalid succesful

# Confirm MW environment
if (!defined('MEDIAWIKI'))
    die(__FILE__." is a MediaWiki extension and cannot be used outside of it");

$wgHooks['ParserFirstCallInit'][] = "wfRegisterDateConverter";
$wgAutoloadClasses['CalendarDateConverter'] = dirname(__FILE__).'/CalendarDateConverter.class.php';

function wfRegisterDateConverter($parser)
{
    $parser->setHook("dateconverter", "convertCalendarDates");
    return true;
}

// CalendarEvents:NSC Interface Calendar/Public/1-1-2008 -Event 0
function convertCalendarDates( $paramstring, $params = array() )
{
    $converter = new CalendarDateConverter;

    // defaults
    if( !isset($params['newformat'] ) ) $params['newformat'] = 'YYYYMMDD';
    if( !isset($params['calname'] ) ) $params['calname'] = 'Public';

    $ret = $converter->convert( $params['newformat'],
                                $params['pagename'],
                                $params['calname'],
                                isset($params['redirect']),
                                isset($params['go']) );

    return $ret;
}
