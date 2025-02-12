<?php
/**
 * @file src/Worker/Notifier.php
 */
namespace Friendica\Worker;

use Friendica\BaseObject;
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\APContact;
use Friendica\Model\Contact;
use Friendica\Model\Conversation;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Model\ItemDeliveryData;
use Friendica\Model\PushSubscriber;
use Friendica\Model\User;
use Friendica\Network\Probe;
use Friendica\Protocol\ActivityPub;
use Friendica\Protocol\Diaspora;
use Friendica\Protocol\OStatus;
use Friendica\Protocol\Salmon;

require_once 'include/items.php';

/*
 * The notifier is typically called with:
 *
 *		Worker::add(PRIORITY_HIGH, "Notifier", COMMAND, ITEM_ID);
 *
 * where COMMAND is one of the constants that are defined in Worker/Delivery.php
 * and ITEM_ID is the id of the item in the database that needs to be sent to others.
 */

class Notifier
{
	public static function execute($cmd, $target_id)
	{
		$a = BaseObject::getApp();

		Logger::log('Invoked: ' . $cmd . ': ' . $target_id, Logger::DEBUG);

		$top_level = false;
		$recipients = [];
		$url_recipients = [];

		$delivery_contacts_stmt = null;
		$target_item = [];
		$items = [];
		$delivery_queue_count = 0;

		if ($cmd == Delivery::MAIL) {
			$message = DBA::selectFirst('mail', ['uid', 'contact-id'], ['id' => $target_id]);
			if (!DBA::isResult($message)) {
				return;
			}
			$uid = $message['uid'];
			$recipients[] = $message['contact-id'];

			$mail = ActivityPub\Transmitter::ItemArrayFromMail($target_id);
			$inboxes = ActivityPub\Transmitter::fetchTargetInboxes($mail, $uid, true);
			foreach ($inboxes as $inbox) {
				Logger::info('Delivery via ActivityPub', ['cmd' => $cmd, 'id' => $target_id, 'inbox' => $inbox]);
				Worker::add(['priority' => PRIORITY_HIGH, 'created' => $a->queue['created'], 'dont_fork' => true],
					'APDelivery', $cmd, $target_id, $inbox, $uid);
			}
		} elseif ($cmd == Delivery::SUGGESTION) {
			$suggest = DBA::selectFirst('fsuggest', ['uid', 'cid'], ['id' => $target_id]);
			if (!DBA::isResult($suggest)) {
				return;
			}
			$uid = $suggest['uid'];
			$recipients[] = $suggest['cid'];
		} elseif ($cmd == Delivery::REMOVAL) {
			return self::notifySelfRemoval($target_id, $a->queue['priority'], $a->queue['created']);
		} elseif ($cmd == Delivery::RELOCATION) {
			$uid = $target_id;

			$condition = ['uid' => $target_id, 'self' => false, 'network' => [Protocol::DFRN, Protocol::DIASPORA]];
			$delivery_contacts_stmt = DBA::select('contact', ['id', 'url', 'network', 'protocol', 'batch'], $condition);
		} else {
			// find ancestors
			$condition = ['id' => $target_id, 'visible' => true, 'moderated' => false];
			$target_item = Item::selectFirst([], $condition);

			if (!DBA::isResult($target_item) || !intval($target_item['parent'])) {
				return;
			}

			if (!empty($target_item['contact-uid'])) {
				$uid = $target_item['contact-uid'];
			} elseif (!empty($target_item['uid'])) {
				$uid = $target_item['uid'];
			} else {
				Logger::log('Only public users for item ' . $target_id, Logger::DEBUG);
				return;
			}

			$condition = ['parent' => $target_item['parent'], 'visible' => true, 'moderated' => false];
			$params = ['order' => ['id']];
			$items_stmt = Item::select([], $condition, $params);
			if (!DBA::isResult($items_stmt)) {
				return;
			}

			$items = Item::inArray($items_stmt);

			// avoid race condition with deleting entries
			if ($items[0]['deleted']) {
				foreach ($items as $item) {
					$item['deleted'] = 1;
				}
			}

			if ((count($items) == 1) && ($items[0]['id'] === $target_item['id']) && ($items[0]['uri'] === $items[0]['parent-uri'])) {
				Logger::log('Top level post');
				$top_level = true;
			}
		}

		$owner = User::getOwnerDataById($uid);
		if (!$owner) {
			return;
		}

		// Should the post be transmitted to Diaspora?
		$diaspora_delivery = true;

		// If this is a public conversation, notify the feed hub
		$public_message = true;

		// Do a PuSH
		$push_notify = false;

		// Deliver directly to a forum, don't PuSH
		$direct_forum_delivery = false;

		$followup = false;
		$recipients_followup = [];

		if (!empty($target_item) && !empty($items)) {
			$parent = $items[0];

			if (!self::isRemovalActivity($cmd, $owner, Protocol::ACTIVITYPUB)) {
				$delivery_queue_count += self::activityPubDelivery($cmd, $target_item, $parent, $a->queue['priority'], $a->queue['created'], $owner);
			}

			$fields = ['network', 'author-id', 'author-link', 'owner-id'];
			$condition = ['uri' => $target_item["thr-parent"], 'uid' => $target_item["uid"]];
			$thr_parent = Item::selectFirst($fields, $condition);

			Logger::log('GUID: ' . $target_item["guid"] . ': Parent is ' . $parent['network'] . '. Thread parent is ' . $thr_parent['network'], Logger::DEBUG);

			// Only deliver threaded replies (comment to a comment) to Diaspora
			// when the original comment author does support the Diaspora protocol.
			if ($target_item['parent-uri'] != $target_item['thr-parent']) {
				$diaspora_delivery = Diaspora::isSupportedByContactUrl($thr_parent['author-link']);
				Logger::info('Threaded comment', ['diaspora_delivery' => (int)$diaspora_delivery]);
			}

			// This is IMPORTANT!!!!

			// We will only send a "notify owner to relay" or followup message if the referenced post
			// originated on our system by virtue of having our hostname somewhere
			// in the URI, AND it was a comment (not top_level) AND the parent originated elsewhere.

			// if $parent['wall'] == 1 we will already have the parent message in our array
			// and we will relay the whole lot.

			$localhost = str_replace('www.','',$a->getHostName());
			if (strpos($localhost,':')) {
				$localhost = substr($localhost,0,strpos($localhost,':'));
			}
			/**
			 *
			 * Be VERY CAREFUL if you make any changes to the following several lines. Seemingly innocuous changes
			 * have been known to cause runaway conditions which affected several servers, along with
			 * permissions issues.
			 *
			 */

			$relay_to_owner = false;

			if (!$top_level && ($parent['wall'] == 0) && (stristr($target_item['uri'],$localhost))) {
				$relay_to_owner = true;
			}


			if (($cmd === Delivery::UPLINK) && (intval($parent['forum_mode']) == 1) && !$top_level) {
				$relay_to_owner = true;
			}

			// until the 'origin' flag has been in use for several months
			// we will just use it as a fallback test
			// later we will be able to use it as the primary test of whether or not to relay.

			if (!$target_item['origin']) {
				$relay_to_owner = false;
			}
			if ($parent['origin']) {
				$relay_to_owner = false;
			}

			// Special treatment for forum posts
			if (Item::isForumPost($target_item, $owner)) {
				$relay_to_owner = true;
				$direct_forum_delivery = true;
			}

			// Avoid that comments in a forum thread are sent to OStatus
			if (Item::isForumPost($parent, $owner)) {
				$direct_forum_delivery = true;
			}

			if ($relay_to_owner) {
				// local followup to remote post
				$followup = true;
				$public_message = false; // not public
				$recipients = [$parent['contact-id']];
				$recipients_followup  = [$parent['contact-id']];

				Logger::log('Followup ' . $target_item['guid'] . ' to ' . $parent['contact-id'], Logger::DEBUG);

				//if (!$target_item['private'] && $target_item['wall'] &&
				if (!$target_item['private'] &&
					(strlen($target_item['allow_cid'].$target_item['allow_gid'].
						$target_item['deny_cid'].$target_item['deny_gid']) == 0))
					$push_notify = true;

				if (($thr_parent && ($thr_parent['network'] == Protocol::OSTATUS)) || ($parent['network'] == Protocol::OSTATUS)) {
					$push_notify = true;

					if ($parent["network"] == Protocol::OSTATUS) {
						// Distribute the message to the DFRN contacts as if this wasn't a followup since OStatus can't relay comments
						// Currently it is work at progress
						$condition = ['uid' => $uid, 'network' => Protocol::DFRN, 'blocked' => false, 'pending' => false, 'archive' => false];
						$followup_contacts_stmt = DBA::select('contact', ['id'], $condition);
						while($followup_contact = DBA::fetch($followup_contacts_stmt)) {
							$recipients_followup[] = $followup_contact['id'];
						}
						DBA::close($followup_contacts_stmt);
					}
				}

				if ($direct_forum_delivery) {
					$push_notify = false;
				}

				Logger::log('Notify ' . $target_item["guid"] .' via PuSH: ' . ($push_notify ? "Yes":"No"), Logger::DEBUG);
			} else {
				$followup = false;

				Logger::log('Distributing directly ' . $target_item["guid"], Logger::DEBUG);

				// don't send deletions onward for other people's stuff

				if ($target_item['deleted'] && !intval($target_item['wall'])) {
					Logger::log('Ignoring delete notification for non-wall item');
					return;
				}

				if (strlen($parent['allow_cid'])
					|| strlen($parent['allow_gid'])
					|| strlen($parent['deny_cid'])
					|| strlen($parent['deny_gid'])) {
					$public_message = false; // private recipients, not public
				}

				$allow_people = expand_acl($parent['allow_cid']);
				$allow_groups = Group::expand($uid, expand_acl($parent['allow_gid']),true);
				$deny_people  = expand_acl($parent['deny_cid']);
				$deny_groups  = Group::expand($uid, expand_acl($parent['deny_gid']));

				// if our parent is a public forum (forum_mode == 1), uplink to the origional author causing
				// a delivery fork. private groups (forum_mode == 2) do not uplink

				if ((intval($parent['forum_mode']) == 1) && !$top_level && ($cmd !== Delivery::UPLINK)) {
					Worker::add($a->queue['priority'], 'Notifier', Delivery::UPLINK, $target_id);
				}

				foreach ($items as $item) {
					$recipients[] = $item['contact-id'];
					// pull out additional tagged people to notify (if public message)
					if ($public_message && strlen($item['inform'])) {
						$people = explode(',',$item['inform']);
						foreach ($people as $person) {
							if (substr($person,0,4) === 'cid:') {
								$recipients[] = intval(substr($person,4));
							} else {
								$url_recipients[] = substr($person,4);
							}
						}
					}
				}

				if (count($url_recipients)) {
					Logger::log('Deliver ' . $target_item["guid"] . ' to _recipients ' . json_encode($url_recipients));
				}

				$recipients = array_unique(array_merge($recipients, $allow_people, $allow_groups));
				$deny = array_unique(array_merge($deny_people, $deny_groups));
				$recipients = array_diff($recipients, $deny);

				// If this is a public message and pubmail is set on the parent, include all your email contacts
				if (
					function_exists('imap_open')
					&& !Config::get('system','imap_disabled')
					&& $public_message
					&& intval($target_item['pubmail'])
				) {
					$mail_contacts_stmt = DBA::select('contact', ['id'], ['uid' => $uid, 'network' => Protocol::MAIL]);
					while ($mail_contact = DBA::fetch($mail_contacts_stmt)) {
						$recipients[] = $mail_contact['id'];
					}
					DBA::close($mail_contacts_stmt);
				}
			}

			// If the thread parent is OStatus then do some magic to distribute the messages.
			// We have not only to look at the parent, since it could be a Friendica thread.
			if (($thr_parent && ($thr_parent['network'] == Protocol::OSTATUS)) || ($parent['network'] == Protocol::OSTATUS)) {
				$diaspora_delivery = false;

				Logger::log('Some parent is OStatus for '.$target_item["guid"]." - Author: ".$thr_parent['author-id']." - Owner: ".$thr_parent['owner-id'], Logger::DEBUG);

				// Send a salmon to the parent author
				$probed_contact = DBA::selectFirst('contact', ['url', 'notify'], ['id' => $thr_parent['author-id']]);
				if (DBA::isResult($probed_contact) && !empty($probed_contact["notify"])) {
					Logger::log('Notify parent author '.$probed_contact["url"].': '.$probed_contact["notify"]);
					$url_recipients[$probed_contact["notify"]] = $probed_contact["notify"];
				}

				// Send a salmon to the parent owner
				$probed_contact = DBA::selectFirst('contact', ['url', 'notify'], ['id' => $thr_parent['owner-id']]);
				if (DBA::isResult($probed_contact) && !empty($probed_contact["notify"])) {
					Logger::log('Notify parent owner '.$probed_contact["url"].': '.$probed_contact["notify"]);
					$url_recipients[$probed_contact["notify"]] = $probed_contact["notify"];
				}

				// Send a salmon notification to every person we mentioned in the post
				$arr = explode(',',$target_item['tag']);
				foreach ($arr as $x) {
					//Logger::log('Checking tag '.$x, Logger::DEBUG);
					$matches = null;
					if (preg_match('/@\[url=([^\]]*)\]/',$x,$matches)) {
							$probed_contact = Probe::uri($matches[1]);
						if ($probed_contact["notify"] != "") {
							Logger::log('Notify mentioned user '.$probed_contact["url"].': '.$probed_contact["notify"]);
							$url_recipients[$probed_contact["notify"]] = $probed_contact["notify"];
						}
					}
				}

				// It only makes sense to distribute answers to OStatus messages to Friendica and OStatus - but not Diaspora
				$networks = [Protocol::DFRN];
			} elseif ($diaspora_delivery) {
				$networks = [Protocol::DFRN, Protocol::DIASPORA, Protocol::MAIL];
			} else {
				$networks = [Protocol::DFRN, Protocol::MAIL];
			}
		} else {
			$public_message = false;
		}

		if (empty($delivery_contacts_stmt)) {
			if ($followup) {
				$recipients = $recipients_followup;
			}
			$condition = ['id' => $recipients, 'self' => false,
				'blocked' => false, 'pending' => false, 'archive' => false];
			if (!empty($networks)) {
				$condition['network'] = $networks;
			}
			$delivery_contacts_stmt = DBA::select('contact', ['id', 'url', 'network', 'protocol', 'batch'], $condition);
		}

		$conversants = [];
		$batch_delivery = false;

		if ($public_message && !in_array($cmd, [Delivery::MAIL, Delivery::SUGGESTION]) && !$followup) {
			$relay_list = [];

			if ($diaspora_delivery) {
				$batch_delivery = true;

				$relay_list_stmt = DBA::p(
					"SELECT
						`batch`,
						ANY_VALUE(`id`) AS `id`,
						ANY_VALUE(`name`) AS `name`,
						ANY_VALUE(`network`) AS `network`,
						ANY_VALUE(`protocol`) AS `protocol`
					FROM `contact`
					WHERE `network` = ?
					AND `batch` != ''
					AND `uid` = ?
					AND `rel` != ?
					AND NOT `blocked`
					AND NOT `pending`
					AND NOT `archive`
					GROUP BY `batch`",
					Protocol::DIASPORA,
					$owner['uid'],
					Contact::SHARING
				);
				$relay_list = DBA::toArray($relay_list_stmt);

				// Fetch the participation list
				// The function will ensure that there are no duplicates
				$relay_list = Diaspora::participantsForThread($target_id, $relay_list);

				// Add the relay to the list, avoid duplicates.
				// Don't send community posts to the relay. Forum posts via the Diaspora protocol are looking ugly.
				if (!$followup && !Item::isForumPost($target_item, $owner)) {
					$relay_list = Diaspora::relayList($target_id, $relay_list);
				}
			}

			$condition = ['network' => Protocol::DFRN, 'uid' => $owner['uid'], 'blocked' => false,
				'pending' => false, 'archive' => false, 'rel' => [Contact::FOLLOWER, Contact::FRIEND]];

			$r2 = DBA::toArray(DBA::select('contact', ['id', 'url', 'name', 'network', 'protocol'], $condition));

			$r = array_merge($r2, $relay_list);

			if (DBA::isResult($r)) {
				foreach ($r as $rr) {
					if (!empty($rr['id']) && Contact::isArchived($rr['id'])) {
						Logger::info('Contact is archived', $rr);
						continue;
					}

					if (self::isRemovalActivity($cmd, $owner, $rr['network'])) {
						Logger::log('Skipping dropping for ' . $rr['url'] . ' since the network supports account removal commands.', Logger::DEBUG);
						continue;
					}

					if (self::skipDFRN($rr, $target_item, $cmd)) {
						Logger::info('Contact can be delivered via AP, so skip delivery via legacy DFRN', ['url' => $rr['url']]);
						continue;
					}

					$conversants[] = $rr['id'];

					Logger::log('Public delivery of item ' . $target_item["guid"] . ' (' . $target_id . ') to ' . json_encode($rr), Logger::DEBUG);

					// Ensure that posts with our own protocol arrives before Diaspora posts arrive.
					// Situation is that sometimes Friendica servers receive Friendica posts over the Diaspora protocol first.
					// The conversion in Markdown reduces the formatting, so these posts should arrive after the Friendica posts.
					// This is only important for high and medium priority tasks and not for Low priority jobs like deletions.
					if (($rr['network'] == Protocol::DIASPORA) && in_array($a->queue['priority'], [PRIORITY_HIGH, PRIORITY_MEDIUM])) {
						$deliver_options = ['priority' => $a->queue['priority'], 'dont_fork' => true];
					} else {
						$deliver_options = ['priority' => $a->queue['priority'], 'created' => $a->queue['created'], 'dont_fork' => true];
					}

					if (Worker::add($deliver_options, 'Delivery', $cmd, $target_id, (int)$rr['id'])) {
						$delivery_queue_count++;
					}
				}
			}

			$push_notify = true;
		}

		// delivery loop
		while ($contact = DBA::fetch($delivery_contacts_stmt)) {
			if (!empty($contact['id']) && Contact::isArchived($contact['id'])) {
				Logger::info('Contact is archived', $contact);
				continue;
			}

			if (self::isRemovalActivity($cmd, $owner, $contact['network'])) {
				Logger::log('Skipping dropping for ' . $contact['url'] . ' since the network supports account removal commands.', Logger::DEBUG);
				continue;
			}

			if (self::skipDFRN($contact, $target_item, $cmd)) {
				Logger::info('Contact can be delivered via AP, so skip delivery via legacy DFRN', ['url' => $contact['url']]);
				continue;
			}

			// Don't deliver to Diaspora if it already had been done as batch delivery
			if (($contact['network'] == Protocol::DIASPORA) && $batch_delivery) {
				Logger::log('Already delivered  id ' . $target_id . ' via batch to ' . json_encode($contact), Logger::DEBUG);
				continue;
			}

			// Don't deliver to folks who have already been delivered to
			if (in_array($contact['id'], $conversants)) {
				Logger::log('Already delivered id ' . $target_id. ' to ' . json_encode($contact), Logger::DEBUG);
				continue;
			}

			Logger::log('Delivery of item ' . $target_id . ' to ' . json_encode($contact), Logger::DEBUG);

			// Ensure that posts with our own protocol arrives before Diaspora posts arrive.
			// Situation is that sometimes Friendica servers receive Friendica posts over the Diaspora protocol first.
			// The conversion in Markdown reduces the formatting, so these posts should arrive after the Friendica posts.
			if ($contact['network'] == Protocol::DIASPORA) {
				$deliver_options = ['priority' => $a->queue['priority'], 'dont_fork' => true];
			} else {
				$deliver_options = ['priority' => $a->queue['priority'], 'created' => $a->queue['created'], 'dont_fork' => true];
			}

			if (Worker::add($deliver_options, 'Delivery', $cmd, $target_id, (int)$contact['id'])) {
				$delivery_queue_count++;
			}
		}
		DBA::close($delivery_contacts_stmt);

		$url_recipients = array_filter($url_recipients);
		// send salmon slaps to mentioned remote tags (@foo@example.com) in OStatus posts
		// They are especially used for notifications to OStatus users that don't follow us.
		if (!Config::get('system', 'dfrn_only') && count($url_recipients) && ($public_message || $push_notify) && !empty($target_item)) {
			$slap = OStatus::salmon($target_item, $owner);
			foreach ($url_recipients as $url) {
				Logger::log('Salmon delivery of item ' . $target_id . ' to ' . $url);
				/// @TODO Redeliver/queue these items on failure, though there is no contact record
				$delivery_queue_count++;
				Salmon::slapper($owner, $url, $slap);
				ItemDeliveryData::incrementQueueDone($target_id, ItemDeliveryData::OSTATUS);
			}
		}

		// Notify PuSH subscribers (Used for OStatus distribution of regular posts)
		if ($push_notify) {
			Logger::log('Activating internal PuSH for item '.$target_id, Logger::DEBUG);

			// Handling the pubsubhubbub requests
			PushSubscriber::publishFeed($owner['uid'], $a->queue['priority']);
		}

		if (!empty($target_item)) {
			Logger::log('Calling hooks for ' . $cmd . ' ' . $target_id, Logger::DEBUG);

			Hook::fork($a->queue['priority'], 'notifier_normal', $target_item);

			Hook::callAll('notifier_end', $target_item);

			// Workaround for pure connector posts
			if (in_array($cmd, [Delivery::POST, Delivery::POKE])) {
				if ($delivery_queue_count == 0) {
					ItemDeliveryData::incrementQueueDone($target_item['id']);
					$delivery_queue_count = 1;
				}

				ItemDeliveryData::incrementQueueCount($target_item['id'], $delivery_queue_count);
			}
		}

		return;
	}

	/**
	 * Checks if the current delivery process needs to be transported via DFRN.
	 *
	 * @param array  $contact Receiver of the post
	 * @param array  $item    The post
	 * @param string $cmd     Notifier command
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function skipDFRN($contact, $item, $cmd)
	{
		// Use DFRN if we are on the same site
		if (!empty($contact['url']) && Contact::isLocal($contact['url'])) {
			return false;
		}

		// Don't skip when author or owner don't have AP profiles
		if ((!empty($item['author-link']) && empty(APContact::getByURL($item['author-link'], false))) || (!empty($item['owner-link']) && empty(APContact::getByURL($item['owner-link'], false)))) {
			return false;
		}

		// Don't skip DFRN delivery for these commands
		if (in_array($cmd, [Delivery::SUGGESTION, Delivery::REMOVAL, Delivery::RELOCATION, Delivery::POKE])) {
			return false;
		}

		// Skip DFRN when the item will be (forcefully) delivered via AP
		if (Config::get('debug', 'total_ap_delivery') && ($contact['network'] == Protocol::DFRN) && !empty(APContact::getByURL($contact['url'], false))) {
			return true;
		}

		// Skip DFRN delivery if the contact speaks ActivityPub
		return in_array($contact['network'], [Protocol::DFRN, Protocol::DIASPORA]) && ($contact['protocol'] == Protocol::ACTIVITYPUB);
	}

	/**
	 * Checks if the current action is a deletion command of a account removal activity
	 * For Diaspora and ActivityPub we don't need to send single item deletion calls.
	 * These protocols do have a dedicated command for deleting a whole account.
	 *
	 * @param string $cmd     Notifier command
	 * @param array  $owner   Sender of the post
	 * @param string $network Receiver network
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function isRemovalActivity($cmd, $owner, $network)
	{
		return ($cmd == Delivery::DELETION) && $owner['account_removed'] && in_array($network, [Protocol::ACTIVITYPUB, Protocol::DIASPORA]);
	}

	/**
	 * @param int    $self_user_id
	 * @param int    $priority The priority the Notifier queue item was created with
	 * @param string $created  The date the Notifier queue item was created on
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function notifySelfRemoval($self_user_id, $priority, $created)
	{
		$owner = User::getOwnerDataById($self_user_id);
		if (!$owner) {
			return false;
		}

		$contacts_stmt = DBA::select('contact', [], ['self' => false, 'uid' => $self_user_id]);
		if (!DBA::isResult($contacts_stmt)) {
			return false;
		}

		while($contact = DBA::fetch($contacts_stmt)) {
			Contact::terminateFriendship($owner, $contact, true);
		}
		DBA::close($contacts_stmt);

		$inboxes = ActivityPub\Transmitter::fetchTargetInboxesforUser(0);
		foreach ($inboxes as $inbox) {
			Logger::info('Account removal via ActivityPub', ['uid' => $self_user_id, 'inbox' => $inbox]);
			Worker::add(['priority' => PRIORITY_NEGLIGIBLE, 'created' => $created, 'dont_fork' => true],
				'APDelivery', Delivery::REMOVAL, '', $inbox, $self_user_id);
		}

		return true;
	}

	/**
	 * @param string $cmd
	 * @param array  $target_item
	 * @param array  $parent
	 * @param int    $priority The priority the Notifier queue item was created with
	 * @param string $created  The date the Notifier queue item was created on
	 * @return int The number of delivery tasks created
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function activityPubDelivery($cmd, array $target_item, array $parent, $priority, $created, $owner)
	{
		$inboxes = [];

		$uid = $target_item['contact-uid'] ?: $target_item['uid'];

		if ($target_item['origin']) {
			$inboxes = ActivityPub\Transmitter::fetchTargetInboxes($target_item, $uid);
			Logger::log('Origin item ' . $target_item['id'] . ' with URL ' . $target_item['uri'] . ' will be distributed.', Logger::DEBUG);
		} elseif (Item::isForumPost($target_item, $owner)) {
			$inboxes = ActivityPub\Transmitter::fetchTargetInboxes($target_item, $uid, false, 0, true);
			Logger::log('Forum item ' . $target_item['id'] . ' with URL ' . $target_item['uri'] . ' will be distributed.', Logger::DEBUG);
		} elseif (!DBA::exists('conversation', ['item-uri' => $target_item['uri'], 'protocol' => Conversation::PARCEL_ACTIVITYPUB])) {
			Logger::log('Remote item ' . $target_item['id'] . ' with URL ' . $target_item['uri'] . ' is no AP post. It will not be distributed.', Logger::DEBUG);
			return 0;
		} elseif ($parent['origin']) {
			// Remote items are transmitted via the personal inboxes.
			// Doing so ensures that the dedicated receiver will get the message.
			$inboxes = ActivityPub\Transmitter::fetchTargetInboxes($parent, $uid, true, $target_item['id']);
			Logger::log('Remote item ' . $target_item['id'] . ' with URL ' . $target_item['uri'] . ' will be distributed.', Logger::DEBUG);
		}

		if (empty($inboxes)) {
			Logger::log('No inboxes found for item ' . $target_item['id'] . ' with URL ' . $target_item['uri'] . '. It will not be distributed.', Logger::DEBUG);
			return 0;
		}

		// Fill the item cache
		ActivityPub\Transmitter::createCachedActivityFromItem($target_item['id'], true);

		$delivery_queue_count = 0;

		foreach ($inboxes as $inbox) {
			Logger::info('Delivery via ActivityPub', ['cmd' => $cmd, 'id' => $target_item['id'], 'inbox' => $inbox]);

			if (Worker::add(['priority' => $priority, 'created' => $created, 'dont_fork' => true],
					'APDelivery', $cmd, $target_item['id'], $inbox, $uid)) {
				$delivery_queue_count++;
			}
		}

		return $delivery_queue_count;
	}
}
