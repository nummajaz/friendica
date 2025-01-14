<?php
/**
 * @file boot.php
 * This file defines some global constants and includes the central App class.
 */

/**
 * Friendica
 *
 * Friendica is a communications platform for integrated social communications
 * utilising decentralised communications and linkage to several indie social
 * projects - as well as popular mainstream providers.
 *
 * Our mission is to free our friends and families from the clutches of
 * data-harvesting corporations, and pave the way to a future where social
 * communications are free and open and flow between alternate providers as
 * easily as email does today.
 */

use Friendica\App;
use Friendica\BaseObject;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Core\Session;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Term;
use Friendica\Util\BasePath;
use Friendica\Util\DateTimeFormat;

define('FRIENDICA_PLATFORM',     'Friendica');
define('FRIENDICA_CODENAME',     'Dalmatian Bellflower');
define('FRIENDICA_VERSION',      '2019.12-dev');
define('DFRN_PROTOCOL_VERSION',  '2.23');
define('NEW_UPDATE_ROUTINE_VERSION', 1170);

/**
 * @brief Constant with a HTML line break.
 *
 * Contains a HTML line break (br) element and a real carriage return with line
 * feed for the source.
 * This can be used in HTML and JavaScript where needed a line break.
 */
define('EOL',                    "<br />\r\n");

/**
 * @brief Image storage quality.
 *
 * Lower numbers save space at cost of image detail.
 * For ease of upgrade, please do not change here. Set system.jpegquality = n in config/local.config.php,
 * where n is between 1 and 100, and with very poor results below about 50
 */
define('JPEG_QUALITY',            100);

/**
 * system.png_quality = n where is between 0 (uncompressed) to 9
 */
define('PNG_QUALITY',             8);

/**
 * An alternate way of limiting picture upload sizes. Specify the maximum pixel
 * length that pictures are allowed to be (for non-square pictures, it will apply
 * to the longest side). Pictures longer than this length will be resized to be
 * this length (on the longest side, the other side will be scaled appropriately).
 * Modify this value using
 *
 * 'system' => [
 *      'max_image_length' => 'n',
 *      ...
 * ],
 *
 * in config/local.config.php
 *
 * If you don't want to set a maximum length, set to -1. The default value is
 * defined by 'MAX_IMAGE_LENGTH' below.
 */
define('MAX_IMAGE_LENGTH',        -1);

/**
 * Not yet used
 */
define('DEFAULT_DB_ENGINE',  'InnoDB');

/** @deprecated since version 2019.03, please use \Friendica\Module\Register::CLOSED instead */
define('REGISTER_CLOSED',        \Friendica\Module\Register::CLOSED);
/** @deprecated since version 2019.03, please use \Friendica\Module\Register::APPROVE instead */
define('REGISTER_APPROVE',       \Friendica\Module\Register::APPROVE);
/** @deprecated since version 2019.03, please use \Friendica\Module\Register::OPEN instead */
define('REGISTER_OPEN',          \Friendica\Module\Register::OPEN);

/**
 * @name CP
 *
 * Type of the community page
 * @{
 */
define('CP_NO_INTERNAL_COMMUNITY', -2);
define('CP_NO_COMMUNITY_PAGE',     -1);
define('CP_USERS_ON_SERVER',        0);
define('CP_GLOBAL_COMMUNITY',       1);
define('CP_USERS_AND_GLOBAL',       2);
/**
 * @}
 */

/**
 * These numbers are used in stored permissions
 * and existing allocations MUST NEVER BE CHANGED
 * OR RE-ASSIGNED! You may only add to them.
 */
$netgroup_ids = [
	Protocol::DFRN     => (-1),
	Protocol::ZOT      => (-2),
	Protocol::OSTATUS  => (-3),
	Protocol::FEED     => (-4),
	Protocol::DIASPORA => (-5),
	Protocol::MAIL     => (-6),
	Protocol::FACEBOOK => (-8),
	Protocol::LINKEDIN => (-9),
	Protocol::XMPP     => (-10),
	Protocol::MYSPACE  => (-11),
	Protocol::GPLUS    => (-12),
	Protocol::PUMPIO   => (-13),
	Protocol::TWITTER  => (-14),
	Protocol::DIASPORA2 => (-15),
	Protocol::STATUSNET => (-16),
	Protocol::NEWS      => (-18),
	Protocol::ICALENDAR => (-19),
	Protocol::PNUT      => (-20),

	Protocol::PHANTOM  => (-127),
];

/**
 * Maximum number of "people who like (or don't like) this"  that we will list by name
 */
define('MAX_LIKERS',    75);

/**
 * @name Notify
 *
 * Email notification options
 * @{
 */
define('NOTIFY_INTRO',        1);
define('NOTIFY_CONFIRM',      2);
define('NOTIFY_WALL',         4);
define('NOTIFY_COMMENT',      8);
define('NOTIFY_MAIL',        16);
define('NOTIFY_SUGGEST',     32);
define('NOTIFY_PROFILE',     64);
define('NOTIFY_TAGSELF',    128);
define('NOTIFY_TAGSHARE',   256);
define('NOTIFY_POKE',       512);
define('NOTIFY_SHARE',     1024);

define('SYSTEM_EMAIL',    16384);

define('NOTIFY_SYSTEM',   32768);
/* @}*/


/** @deprecated since 2019.03, use Term::UNKNOWN instead */
define('TERM_UNKNOWN',   Term::UNKNOWN);
/** @deprecated since 2019.03, use Term::HASHTAG instead */
define('TERM_HASHTAG',   Term::HASHTAG);
/** @deprecated since 2019.03, use Term::MENTION instead */
define('TERM_MENTION',   Term::MENTION);
/** @deprecated since 2019.03, use Term::CATEGORY instead */
define('TERM_CATEGORY',  Term::CATEGORY);
/** @deprecated since 2019.03, use Term::PCATEGORY instead */
define('TERM_PCATEGORY', Term::PCATEGORY);
/** @deprecated since 2019.03, use Term::FILE instead */
define('TERM_FILE',      Term::FILE);
/** @deprecated since 2019.03, use Term::SAVEDSEARCH instead */
define('TERM_SAVEDSEARCH', Term::SAVEDSEARCH);
/** @deprecated since 2019.03, use Term::CONVERSATION instead */
define('TERM_CONVERSATION', Term::CONVERSATION);

/** @deprecated since 2019.03, use Term::OBJECT_TYPE_POST instead */
define('TERM_OBJ_POST',  Term::OBJECT_TYPE_POST);
/** @deprecated since 2019.03, use Term::OBJECT_TYPE_PHOTO instead */
define('TERM_OBJ_PHOTO', Term::OBJECT_TYPE_PHOTO);

/**
 * @name Namespaces
 *
 * Various namespaces we may need to parse
 * @{
 */
define('NAMESPACE_ZOT',             'http://purl.org/zot');
define('NAMESPACE_DFRN',            'http://purl.org/macgirvin/dfrn/1.0');
define('NAMESPACE_THREAD',          'http://purl.org/syndication/thread/1.0');
define('NAMESPACE_TOMB',            'http://purl.org/atompub/tombstones/1.0');
define('NAMESPACE_ACTIVITY2',       'https://www.w3.org/ns/activitystreams#');
define('NAMESPACE_ACTIVITY',        'http://activitystrea.ms/spec/1.0/');
define('NAMESPACE_ACTIVITY_SCHEMA', 'http://activitystrea.ms/schema/1.0/');
define('NAMESPACE_MEDIA',           'http://purl.org/syndication/atommedia');
define('NAMESPACE_SALMON_ME',       'http://salmon-protocol.org/ns/magic-env');
define('NAMESPACE_OSTATUSSUB',      'http://ostatus.org/schema/1.0/subscribe');
define('NAMESPACE_GEORSS',          'http://www.georss.org/georss');
define('NAMESPACE_POCO',            'http://portablecontacts.net/spec/1.0');
define('NAMESPACE_FEED',            'http://schemas.google.com/g/2010#updates-from');
define('NAMESPACE_OSTATUS',         'http://ostatus.org/schema/1.0');
define('NAMESPACE_STATUSNET',       'http://status.net/schema/api/1/');
define('NAMESPACE_ATOM1',           'http://www.w3.org/2005/Atom');
define('NAMESPACE_MASTODON',        'http://mastodon.social/schema/1.0');
/* @}*/

/**
 * @name Activity
 *
 * Activity stream defines
 * @{
 */
define('ACTIVITY_LIKE',        NAMESPACE_ACTIVITY_SCHEMA . 'like');
define('ACTIVITY_DISLIKE',     NAMESPACE_DFRN            . '/dislike');
define('ACTIVITY_ATTEND',      NAMESPACE_ZOT             . '/activity/attendyes');
define('ACTIVITY_ATTENDNO',    NAMESPACE_ZOT             . '/activity/attendno');
define('ACTIVITY_ATTENDMAYBE', NAMESPACE_ZOT             . '/activity/attendmaybe');

define('ACTIVITY_OBJ_HEART',   NAMESPACE_DFRN            . '/heart');

define('ACTIVITY_FRIEND',      NAMESPACE_ACTIVITY_SCHEMA . 'make-friend');
define('ACTIVITY_REQ_FRIEND',  NAMESPACE_ACTIVITY_SCHEMA . 'request-friend');
define('ACTIVITY_UNFRIEND',    NAMESPACE_ACTIVITY_SCHEMA . 'remove-friend');
define('ACTIVITY_FOLLOW',      NAMESPACE_ACTIVITY_SCHEMA . 'follow');
define('ACTIVITY_UNFOLLOW',    NAMESPACE_ACTIVITY_SCHEMA . 'stop-following');
define('ACTIVITY_JOIN',        NAMESPACE_ACTIVITY_SCHEMA . 'join');

define('ACTIVITY_POST',        NAMESPACE_ACTIVITY_SCHEMA . 'post');
define('ACTIVITY_UPDATE',      NAMESPACE_ACTIVITY_SCHEMA . 'update');
define('ACTIVITY_TAG',         NAMESPACE_ACTIVITY_SCHEMA . 'tag');
define('ACTIVITY_FAVORITE',    NAMESPACE_ACTIVITY_SCHEMA . 'favorite');
define('ACTIVITY_UNFAVORITE',  NAMESPACE_ACTIVITY_SCHEMA . 'unfavorite');
define('ACTIVITY_SHARE',       NAMESPACE_ACTIVITY_SCHEMA . 'share');
define('ACTIVITY_DELETE',      NAMESPACE_ACTIVITY_SCHEMA . 'delete');
define('ACTIVITY2_ANNOUNCE',   NAMESPACE_ACTIVITY2       . 'Announce');

define('ACTIVITY_POKE',        NAMESPACE_ZOT . '/activity/poke');

define('ACTIVITY_OBJ_BOOKMARK', NAMESPACE_ACTIVITY_SCHEMA . 'bookmark');
define('ACTIVITY_OBJ_COMMENT', NAMESPACE_ACTIVITY_SCHEMA . 'comment');
define('ACTIVITY_OBJ_NOTE',    NAMESPACE_ACTIVITY_SCHEMA . 'note');
define('ACTIVITY_OBJ_PERSON',  NAMESPACE_ACTIVITY_SCHEMA . 'person');
define('ACTIVITY_OBJ_IMAGE',   NAMESPACE_ACTIVITY_SCHEMA . 'image');
define('ACTIVITY_OBJ_PHOTO',   NAMESPACE_ACTIVITY_SCHEMA . 'photo');
define('ACTIVITY_OBJ_VIDEO',   NAMESPACE_ACTIVITY_SCHEMA . 'video');
define('ACTIVITY_OBJ_P_PHOTO', NAMESPACE_ACTIVITY_SCHEMA . 'profile-photo');
define('ACTIVITY_OBJ_ALBUM',   NAMESPACE_ACTIVITY_SCHEMA . 'photo-album');
define('ACTIVITY_OBJ_EVENT',   NAMESPACE_ACTIVITY_SCHEMA . 'event');
define('ACTIVITY_OBJ_GROUP',   NAMESPACE_ACTIVITY_SCHEMA . 'group');
define('ACTIVITY_OBJ_TAGTERM', NAMESPACE_DFRN            . '/tagterm');
define('ACTIVITY_OBJ_PROFILE', NAMESPACE_DFRN            . '/profile');
define('ACTIVITY_OBJ_QUESTION', 'http://activityschema.org/object/question');
/* @}*/

/**
 * @name Gravity
 *
 * Item weight for query ordering
 * @{
 */
define('GRAVITY_PARENT',       0);
define('GRAVITY_ACTIVITY',     3);
define('GRAVITY_COMMENT',      6);
define('GRAVITY_UNKNOWN',      9);
/* @}*/

/**
 * @name Priority
 *
 * Process priority for the worker
 * @{
 */
define('PRIORITY_UNDEFINED',   0);
define('PRIORITY_CRITICAL',   10);
define('PRIORITY_HIGH',       20);
define('PRIORITY_MEDIUM',     30);
define('PRIORITY_LOW',        40);
define('PRIORITY_NEGLIGIBLE', 50);
/* @}*/

/**
 * @name Social Relay settings
 *
 * See here: https://github.com/jaywink/social-relay
 * and here: https://wiki.diasporafoundation.org/Relay_servers_for_public_posts
 * @{
 */
define('SR_SCOPE_NONE', '');
define('SR_SCOPE_ALL',  'all');
define('SR_SCOPE_TAGS', 'tags');
/* @}*/

// Normally this constant is defined - but not if "pcntl" isn't installed
if (!defined("SIGTERM")) {
	define("SIGTERM", 15);
}

/**
 * Depending on the PHP version this constant does exist - or not.
 * See here: http://php.net/manual/en/curl.constants.php#117928
 */
if (!defined('CURLE_OPERATION_TIMEDOUT')) {
	define('CURLE_OPERATION_TIMEDOUT', CURLE_OPERATION_TIMEOUTED);
}

/**
 * @brief Retrieve the App structure
 *
 * Useful in functions which require it but don't get it passed to them
 *
 * @deprecated since version 2018.09
 * @see BaseObject::getApp()
 * @return App
 */
function get_app()
{
	return BaseObject::getApp();
}

/**
 * Return the provided variable value if it exists and is truthy or the provided
 * default value instead.
 *
 * Works with initialized variables and potentially uninitialized array keys
 *
 * Usages:
 * - defaults($var, $default)
 * - defaults($array, 'key', $default)
 *
 * @param array $args
 * @brief Returns a defaut value if the provided variable or array key is falsy
 * @return mixed
 * @deprecated since version 2019.06, use native coalesce operator (??) instead
 */
function defaults(...$args)
{
	if (count($args) < 2) {
		throw new BadFunctionCallException('defaults() requires at least 2 parameters');
	}
	if (count($args) > 3) {
		throw new BadFunctionCallException('defaults() cannot use more than 3 parameters');
	}
	if (count($args) === 3 && is_null($args[1])) {
		throw new BadFunctionCallException('defaults($arr, $key, $def) $key is null');
	}

	// The default value always is the last argument
	$return = array_pop($args);

	if (count($args) == 2 && is_array($args[0]) && !empty($args[0][$args[1]])) {
		$return = $args[0][$args[1]];
	}

	if (count($args) == 1 && !empty($args[0])) {
		$return = $args[0];
	}

	return $return;
}

/**
 * @brief Used to end the current process, after saving session state.
 * @deprecated
 */
function killme()
{
	exit();
}

/**
 * @brief Returns the user id of locally logged in user or false.
 *
 * @return int|bool user id or false
 */
function local_user()
{
	if (!empty($_SESSION['authenticated']) && !empty($_SESSION['uid'])) {
		return intval($_SESSION['uid']);
	}
	return false;
}

/**
 * @brief Returns the public contact id of logged in user or false.
 *
 * @return int|bool public contact id or false
 */
function public_contact()
{
	static $public_contact_id = false;

	if (!$public_contact_id && !empty($_SESSION['authenticated'])) {
		if (!empty($_SESSION['my_address'])) {
			// Local user
			$public_contact_id = intval(Contact::getIdForURL($_SESSION['my_address'], 0, true));
		} elseif (!empty($_SESSION['visitor_home'])) {
			// Remote user
			$public_contact_id = intval(Contact::getIdForURL($_SESSION['visitor_home'], 0, true));
		}
	} elseif (empty($_SESSION['authenticated'])) {
		$public_contact_id = false;
	}

	return $public_contact_id;
}

/**
 * @brief Returns contact id of authenticated site visitor or false
 *
 * @return int|bool visitor_id or false
 */
function remote_user()
{
	if (empty($_SESSION['authenticated'])) {
		return false;
	}

	if (!empty($_SESSION['visitor_id'])) {
		return intval($_SESSION['visitor_id']);
	}

	return false;
}

/**
 * @brief Show an error message to user.
 *
 * This function save text in session, to be shown to the user at next page load
 *
 * @param string $s - Text of notice
 */
function notice($s)
{
	if (empty($_SESSION)) {
		return;
	}

	$a = \get_app();
	if (empty($_SESSION['sysmsg'])) {
		$_SESSION['sysmsg'] = [];
	}
	if ($a->interactive) {
		$_SESSION['sysmsg'][] = $s;
	}
}

/**
 * @brief Show an info message to user.
 *
 * This function save text in session, to be shown to the user at next page load
 *
 * @param string $s - Text of notice
 */
function info($s)
{
	$a = \get_app();

	if (local_user() && PConfig::get(local_user(), 'system', 'ignore_info')) {
		return;
	}

	if (empty($_SESSION['sysmsg_info'])) {
		$_SESSION['sysmsg_info'] = [];
	}
	if ($a->interactive) {
		$_SESSION['sysmsg_info'][] = $s;
	}
}

function feed_birthday($uid, $tz)
{
	/**
	 * Determine the next birthday, but only if the birthday is published
	 * in the default profile. We _could_ also look for a private profile that the
	 * recipient can see, but somebody could get mad at us if they start getting
	 * public birthday greetings when they haven't made this info public.
	 *
	 * Assuming we are able to publish this info, we are then going to convert
	 * the start time from the owner's timezone to UTC.
	 *
	 * This will potentially solve the problem found with some social networks
	 * where birthdays are converted to the viewer's timezone and salutations from
	 * elsewhere in the world show up on the wrong day. We will convert it to the
	 * viewer's timezone also, but first we are going to convert it from the birthday
	 * person's timezone to GMT - so the viewer may find the birthday starting at
	 * 6:00PM the day before, but that will correspond to midnight to the birthday person.
	 */
	$birthday = '';

	if (!strlen($tz)) {
		$tz = 'UTC';
	}

	$profile = DBA::selectFirst('profile', ['dob'], ['is-default' => true, 'uid' => $uid]);
	if (DBA::isResult($profile)) {
		$tmp_dob = substr($profile['dob'], 5);
		if (intval($tmp_dob)) {
			$y = DateTimeFormat::timezoneNow($tz, 'Y');
			$bd = $y . '-' . $tmp_dob . ' 00:00';
			$t_dob = strtotime($bd);
			$now = strtotime(DateTimeFormat::timezoneNow($tz));
			if ($t_dob < $now) {
				$bd = $y + 1 . '-' . $tmp_dob . ' 00:00';
			}
			$birthday = DateTimeFormat::convert($bd, 'UTC', $tz, DateTimeFormat::ATOM);
		}
	}

	return $birthday;
}

/**
 * @brief Check if current user has admin role.
 *
 * @return bool true if user is an admin
 */
function is_site_admin()
{
	$a = \get_app();

	$admin_email = Config::get('config', 'admin_email');

	$adminlist = explode(',', str_replace(' ', '', $admin_email));

	return local_user() && $admin_email && in_array(defaults($a->user, 'email', ''), $adminlist);
}

function explode_querystring($query)
{
	$arg_st = strpos($query, '?');
	if ($arg_st !== false) {
		$base = substr($query, 0, $arg_st);
		$arg_st += 1;
	} else {
		$base = '';
		$arg_st = 0;
	}

	$args = explode('&', substr($query, $arg_st));
	foreach ($args as $k => $arg) {
		/// @TODO really compare type-safe here?
		if ($arg === '') {
			unset($args[$k]);
		}
	}
	$args = array_values($args);

	if (!$base) {
		$base = $args[0];
		unset($args[0]);
		$args = array_values($args);
	}

	return [
		'base' => $base,
		'args' => $args,
	];
}

/**
 * Returns the complete URL of the current page, e.g.: http(s)://something.com/network
 *
 * Taken from http://webcheatsheet.com/php/get_current_page_url.php
 */
function curPageURL()
{
	$pageURL = 'http';
	if (!empty($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
		$pageURL .= "s";
	}

	$pageURL .= "://";

	if ($_SERVER["SERVER_PORT"] != "80" && $_SERVER["SERVER_PORT"] != "443") {
		$pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
	} else {
		$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
	}
	return $pageURL;
}

function get_server()
{
	$server = Config::get("system", "directory");

	if ($server == "") {
		$server = "https://dir.friendica.social";
	}

	return $server;
}

function get_temppath()
{
	$a = \get_app();

	$temppath = Config::get("system", "temppath");

	if (($temppath != "") && System::isDirectoryUsable($temppath)) {
		// We have a temp path and it is usable
		return BasePath::getRealPath($temppath);
	}

	// We don't have a working preconfigured temp path, so we take the system path.
	$temppath = sys_get_temp_dir();

	// Check if it is usable
	if (($temppath != "") && System::isDirectoryUsable($temppath)) {
		// Always store the real path, not the path through symlinks
		$temppath = BasePath::getRealPath($temppath);

		// To avoid any interferences with other systems we create our own directory
		$new_temppath = $temppath . "/" . $a->getHostName();
		if (!is_dir($new_temppath)) {
			/// @TODO There is a mkdir()+chmod() upwards, maybe generalize this (+ configurable) into a function/method?
			mkdir($new_temppath);
		}

		if (System::isDirectoryUsable($new_temppath)) {
			// The new path is usable, we are happy
			Config::set("system", "temppath", $new_temppath);
			return $new_temppath;
		} else {
			// We can't create a subdirectory, strange.
			// But the directory seems to work, so we use it but don't store it.
			return $temppath;
		}
	}

	// Reaching this point means that the operating system is configured badly.
	return '';
}

function get_cachefile($file, $writemode = true)
{
	$cache = get_itemcachepath();

	if ((!$cache) || (!is_dir($cache))) {
		return "";
	}

	$subfolder = $cache . "/" . substr($file, 0, 2);

	$cachepath = $subfolder . "/" . $file;

	if ($writemode) {
		if (!is_dir($subfolder)) {
			mkdir($subfolder);
			chmod($subfolder, 0777);
		}
	}

	return $cachepath;
}

function clear_cache($basepath = "", $path = "")
{
	if ($path == "") {
		$basepath = get_itemcachepath();
		$path = $basepath;
	}

	if (($path == "") || (!is_dir($path))) {
		return;
	}

	if (substr(realpath($path), 0, strlen($basepath)) != $basepath) {
		return;
	}

	$cachetime = (int) Config::get('system', 'itemcache_duration');
	if ($cachetime == 0) {
		$cachetime = 86400;
	}

	if (is_writable($path)) {
		if ($dh = opendir($path)) {
			while (($file = readdir($dh)) !== false) {
				$fullpath = $path . "/" . $file;
				if ((filetype($fullpath) == "dir") && ($file != ".") && ($file != "..")) {
					clear_cache($basepath, $fullpath);
				}
				if ((filetype($fullpath) == "file") && (filectime($fullpath) < (time() - $cachetime))) {
					unlink($fullpath);
				}
			}
			closedir($dh);
		}
	}
}

function get_itemcachepath()
{
	// Checking, if the cache is deactivated
	$cachetime = (int) Config::get('system', 'itemcache_duration');
	if ($cachetime < 0) {
		return "";
	}

	$itemcache = Config::get('system', 'itemcache');
	if (($itemcache != "") && System::isDirectoryUsable($itemcache)) {
		return BasePath::getRealPath($itemcache);
	}

	$temppath = get_temppath();

	if ($temppath != "") {
		$itemcache = $temppath . "/itemcache";
		if (!file_exists($itemcache) && !is_dir($itemcache)) {
			mkdir($itemcache);
		}

		if (System::isDirectoryUsable($itemcache)) {
			Config::set("system", "itemcache", $itemcache);
			return $itemcache;
		}
	}
	return "";
}

/**
 * @brief Returns the path where spool files are stored
 *
 * @return string Spool path
 */
function get_spoolpath()
{
	$spoolpath = Config::get('system', 'spoolpath');
	if (($spoolpath != "") && System::isDirectoryUsable($spoolpath)) {
		// We have a spool path and it is usable
		return $spoolpath;
	}

	// We don't have a working preconfigured spool path, so we take the temp path.
	$temppath = get_temppath();

	if ($temppath != "") {
		// To avoid any interferences with other systems we create our own directory
		$spoolpath = $temppath . "/spool";
		if (!is_dir($spoolpath)) {
			mkdir($spoolpath);
		}

		if (System::isDirectoryUsable($spoolpath)) {
			// The new path is usable, we are happy
			Config::set("system", "spoolpath", $spoolpath);
			return $spoolpath;
		} else {
			// We can't create a subdirectory, strange.
			// But the directory seems to work, so we use it but don't store it.
			return $temppath;
		}
	}

	// Reaching this point means that the operating system is configured badly.
	return "";
}

if (!function_exists('exif_imagetype')) {
	function exif_imagetype($file)
	{
		$size = getimagesize($file);
		return $size[2];
	}
}

function validate_include(&$file)
{
	$orig_file = $file;

	$file = realpath($file);

	if (strpos($file, getcwd()) !== 0) {
		return false;
	}

	$file = str_replace(getcwd() . "/", "", $file, $count);
	if ($count != 1) {
		return false;
	}

	if ($orig_file !== $file) {
		return false;
	}

	$valid = false;
	if (strpos($file, "include/") === 0) {
		$valid = true;
	}

	if (strpos($file, "addon/") === 0) {
		$valid = true;
	}

	// Simply return flag
	return $valid;
}

/**
 * PHP 5 compatible dirname() with count parameter
 *
 * @see http://php.net/manual/en/function.dirname.php#113193
 *
 * @deprecated with PHP 7
 * @param string $path
 * @param int    $levels
 * @return string
 */
function rdirname($path, $levels = 1)
{
	if ($levels > 1) {
		return dirname(rdirname($path, --$levels));
	} else {
		return dirname($path);
	}
}
