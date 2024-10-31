<?php
/*
Plugin Name: Reprint My Blog
Plugin URI: http://blog.programet.org/
Description: This plugin help you to get the updates of writers' other blogs by checking feeds. When updates are detected, the writer can decide how to show the posts. It's compatible with Configure SMTP.
Version: 1.0
Author: LastLeaf
Author URI: http://blog.programet.org/lastleaf
License: GPLv3
*/

// config
define('RMB_FEED_ITEM_MAX', 25);
define('RMB_FEED_CHANGE_MAX', 1);
define('RMB_FEED_CHECK_INTERVAL', 10 * 60);
define('RMB_EMAIL_RETRY', 4);
define('RMB_MAIL_CONTENT', __rmb('A new post titled "[title]" was published on your another blog. Do you want it to be published on [blogname] ?<br />If yes, just visit<br />[yeslink]<br />Or else, visit<br />[nolink]<hr />This mail was automatically sent. Please do not reply.'));
define('RMB_MAIL_TOO_FAST_PREFIX', __rmb('Too many posts have been found at a time, so you need to publish manually.<br />To see the whole pending list, visit<br />[listlink]<hr />'));

// load languages
load_plugin_textdomain('rmb', false, dirname(plugin_basename( __FILE__ )) . '/languages/');
function __rmb($text)
{
	return __($text, 'rmb');
}

// deactivation func
register_deactivation_hook(__FILE__, 'rmb_deactivate');
function rmb_deactivate()
{
	delete_option('rmb_email_pending');
	delete_option('rmb_prev_checked');
	$users = get_users(array('meta_key'=>'rmb_prev'));
	foreach($users as $i => $u)
		rmb_writer_prev_remove($u->ID);
	$users = get_users(array('meta_key'=>'rmb_update_list'));
	foreach($users as $i => $u)
		rmb_writer_buffer_remove_all($u->ID);
	wp_clear_scheduled_hook('rmb_check_event');
}

// set reccurences
function rmb_add_interval($schedules)
{
	$schedules['rmb_check_interval'] = array('interval'=>RMB_FEED_CHECK_INTERVAL, 'display'=>RMB_FEED_CHECK_INTERVAL . __rmb(' seconds'));
	return $schedules;
}
add_filter('cron_schedules', 'rmb_add_interval');

// activation func
register_activation_hook(__FILE__, 'rmb_activate');
function rmb_activate()
{
	$useropt = array('enabled'=>false, 'feedaddr'=>'', 'autopub'=>false, 'defaultpub'=>true, 'postredir'=>true, 'authorredir'=>false, 'homeaddr'=>'');
	update_option('rmb_admin_options', $useropt);
	if(!wp_next_scheduled('rmb_check_event'))
		wp_schedule_event(time(), 'rmb_check_interval', 'rmb_check_event');
}
add_action('rmb_check_event', 'rmb_writer_check_all_updates');

// redirections
add_action('parse_query', 'rmb_redirections', 100);
function rmb_redirections($query)
{
	if(is_author())
	{
		$id=get_the_author_meta('ID');
		if(!user_can($id, 'publish_posts'))return;
		$options=get_user_option('rmb_admin_options', $id);
		if(!$options['authorredir'])return;
		if(!$options['homeaddr'])return;
		header('Location: ' . $options['homeaddr']);
		exit();
	}
	if(is_single())
	{
		$id=get_the_author_meta('ID');
		if(!user_can($id, 'publish_posts'))return;
		$options=get_user_option('rmb_admin_options', $id);
		if(!$options['postredir'])return;
		$id=get_the_ID();
		$src=get_post_meta($id, 'rmb_source_address', true);
		if(!$src)return;
		header('Location: ' . $src);
		exit();	
	}
}

// admin interface
add_action('admin_menu', 'rmb_admin_menu');
function rmb_admin_menu()
{
	add_menu_page(__rmb('Reprint My Blog'), __rmb('Reprint My Blog'), 'publish_posts', 'rmb_admin_page', 'rmb_admin_page');
}
function rmb_admin_page()
{
	if(!current_user_can('publish_posts'))
	{
		wp_die(__rmb('You do not have sufficient permissions to access this page.'));
	}
	
	echo '<div class="wrap">';
	echo '<h2>' . __rmb('Reprint My Blog Options');
	echo ' <form action="" method="post" style="display:inline;"><input type="submit" class="button add-new-h2" name="rmb-writer-update" value="' . __rmb('Get my latest updates now!') . '"></input></form>';
	echo '</h2>';
	
	// if settings are changed
	global $current_user;
	get_currentuserinfo();
	if(array_key_exists('rmb-feed-addr', $_POST))
	{
		check_admin_referer('rmb_admin_option_nonce');
		
		$useropt = get_user_option('rmb_admin_options');
		if(!$useropt)
			$useropt = get_option('rmb_admin_options');
		
		$changed = false;
		if(array_key_exists('rmb-enabled', $_POST) && !$useropt['enabled'])
		{
			$changed = true;
			$useropt['enabled'] = true;
		} else
		{
			if(array_key_exists('rmb-enabled', $_POST))
				$useropt['enabled'] = true;
			else 
				$useropt['enabled'] = false;
		}
		
		if($useropt['feedaddr'] != $_POST['rmb-feed-addr'])
		{
			$changed = true;
			$useropt['feedaddr'] = $_POST['rmb-feed-addr'];
		}
		
		if($_POST['rmb-auto-pub'] == 'true')
			$useropt['autopub'] = true;
		else
			$useropt['autopub'] = false;
		if(array_key_exists('rmb-default-pub', $_POST))
			$useropt['defaultpub'] = true;
		else
			$useropt['defaultpub'] = false;
		
		if(array_key_exists('rmb-post-redir', $_POST))
			$useropt['postredir'] = true;
		else
			$useropt['postredir'] = false;
		if(array_key_exists('rmb-author-redir', $_POST))
			$useropt['authorredir'] = true;
		else
			$useropt['authorredir'] = false;
		$useropt['homeaddr'] = $_POST['rmb-home-addr'];
		
		// check feed
		if($useropt['enabled'] && $changed)
		{
			if(!$useropt['feedaddr'])
				echo '<div class="error below-h2"><p>' . __rmb('Blog feed address has not been set.') . '</p></div>';
			else
			{
				$testfeed = rmb_fetch_feed($useropt['feedaddr']);
				if(!$testfeed)
					echo '<div class="error below-h2"><p>' . __rmb('An error occurred when fetching feeds!') . '</p></div>';
				else
				{
					$feedcount = $testfeed->get_item_quantity(0);
					if($feedcount <= 0)
						echo '<div class="updated below-h2"><p>' . __rmb('No post was found in blog feed address. If your blog is not empty, please check your feed address!') . '</p></div>';
					else
					{
						$item = $testfeed->get_item(0);
						echo '<div class="updated below-h2"><p>' . __rmb('Your blog feed was found. The title of the latest post is') . '</p><p><strong>' . $item->get_title() . '</strong></p></div>';
					}
					if(update_user_option($current_user->ID, 'rmb_admin_options', $useropt))
					{
						rmb_writer_buffer_remove_all($current_user->ID);
						rmb_writer_prev_remove($current_user->ID);
						rmb_writer_prev_update($current_user->ID, $testfeed->get_items());
					}
				}
			}
		} else
		{
			if(!$useropt['enabled'])
			{
				rmb_writer_buffer_remove_all($current_user->ID);
				rmb_writer_prev_remove($current_user->ID);
			}
			if(update_user_option($current_user->ID, 'rmb_admin_options', $useropt))
				echo '<div class="updated below-h2"><p>' . __rmb('Options saved.') . '</p></div>';
			else
				echo '<div class="error below-h2"><p>' . __rmb('An error occurred when saving options!') . '</p></div>';
		}
	}
	
	$useropt = get_user_option('rmb_admin_options');
	if(!$useropt)
		$useropt = get_option('rmb_admin_options');
	
		// show settings
	

	echo '<form action="" method="post" id="rmb-option-form">';
	if(function_exists('wp_nonce_field'))
		wp_nonce_field('rmb_admin_option_nonce');
	
		// page layout
	/*
	- [] Enable auto reprint my blog for me
	- My blog feed address ________
	- When a new post is found
	- () auto fetch and publish in this blog
	- () email me and ask me whether I want to publish in this blog or not
	- - Your email is...
	- - [] auto publish it if I have not decided after 24 hours
	- [] Redirect to my original post when entering the post page
	- [] Redirect to my blog when entering my author page
	- - My blog home page address _________
    */
	
	echo '<p><input type="checkbox" name="rmb-enabled" value="enabled"';
	if($useropt['enabled'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('Enable auto reprint my blog for me') . ' </label></p>';
	
	echo '<div style="padding-left:2em;"><p><label> ' . __rmb('My blog feed address') . ' </label>';
	echo '<input type="text" style="width:400px;" name="rmb-feed-addr" value="' . $useropt['feedaddr'] . '" />';
	if(get_user_meta($current_user->ID, 'rmb_update_list', true))
		echo '<br /><span style="padding-left:2em;color:red;"><label><strong>' . __rmb('Warning! Modify the feed address will cause the pending list cleared!') . '</strong></label></span>';
	echo '</p>';
	
	echo '<p><label> ' . __rmb('When a new post is found') . ' </label><br />';
	echo '<input type="radio" name="rmb-auto-pub" value="true"';
	if($useropt['autopub'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('auto fetch and publish in this blog') . ' </label><br />';
	echo '<input type="radio" name="rmb-auto-pub" value="false"';
	if(!$useropt['autopub'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('email me and ask me whether I want to publish in this blog or not') . ' </label><br />';
	echo '<span style="padding-left:2em;"><label>' . __rmb('Your email address is') . ' <strong>' . $current_user->user_email . '</strong> <a href="profile.php#email">' . __rmb('go to the profile options page to modify it') . '</a></label></span><br />';
	echo '<span style="padding-left:2em;"><input type="checkbox" name="rmb-default-pub" value="enabled"';
	if($useropt['defaultpub'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('auto publish it if I have not decided after 24 hours') . ' </label></span></p></div>';
	
	echo '<p><input type="checkbox" name="rmb-post-redir" value="enabled"';
	if($useropt['postredir'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('Redirect to my original post when entering the post page') . ' </label><br />';
	echo '<input type="checkbox" name="rmb-author-redir" value="enabled"';
	if($useropt['authorredir'])
		echo ' checked="checked"';
	echo ' /><label> ' . __rmb('Redirect to my blog when entering my author page') . ' </label><br />';
	echo '<span style="padding-left:2em;"><label> ' . __rmb('My blog home page address') . ' </label>';
	echo '<input type="text" style="width:400px;" name="rmb-home-addr" value="' . $useropt['homeaddr'] . '" /></span></p>';
	
	echo '<input name="submit" type="submit" value="' . __rmb('Save Options') . '" />';
	echo '</form>';
	echo '</div>';
}

// notifications
add_action('admin_notices', 'rmb_admin_notice');
function rmb_admin_notice()
{
	if(!current_user_can('publish_posts'))
		return;
	
		// if needed, start to show
	global $current_user;
	get_currentuserinfo();
	$titles = rmb_writer_buffer_titles($current_user->ID);
	if(!$titles)
		$titles = array();
	if(!array_key_exists('rmb-writer-update', $_POST) && !array_key_exists('rmb_ignore', $_GET) && !array_key_exists('rmb_publish', $_GET) && !array_key_exists('rmb_ignore_all', $_GET) && empty($titles))
		return;
	echo '<div class="wrap">';
	
	// check updates
	if(array_key_exists('rmb-writer-update', $_POST))
	{
		$c = rmb_writer_check_update($current_user->id, '');
		if($c == -1)
			echo '<div class="error below-h2"><p>' . __rmb('An error occurred when fetching feeds. No post was found.') . '</p></div>';
		else if($c >= 0)
			echo '<div class="updated below-h2"><p>' . __rmb('Successfully checked feeds.') . '</p></div>';
	}
	
	// show dealing result
	if(array_key_exists('rmb_publish', $_GET))
	{
		$itemid = rawurldecode($_GET['rmb_publish']);
		if(array_key_exists($itemid, $titles))
			$s = rmb_writer_publish_id($current_user->ID, $itemid);
		else
			$s = '';
		if($s)
			echo '<div class="error below-h2"><p>' . $s . '</p></div>';
		else if(array_key_exists($itemid, $titles))
			echo '<div class="updated below-h2"><p>' . __rmb('A post has been published:') . ' <strong>' . $titles[$itemid] . '</strong></p></div>';
		rmb_writer_buffer_remove($current_user->ID, $itemid);
	} else if(array_key_exists('rmb_ignore', $_GET))
	{
		$itemid = rawurldecode($_GET['rmb_ignore']);
		if(array_key_exists($itemid, $titles))
		{
			echo '<div class="updated below-h2"><p>' . __rmb('A post has been ignored:') . ' <strong>' . $titles[$itemid] . '</strong></p></div>';
			rmb_writer_buffer_remove($current_user->ID, $itemid);
		}
	} else if(array_key_exists('rmb_ignore_all', $_GET))
	{
		echo '<div class="updated below-h2"><p>' . __rmb('All posts have been ignored.') . '</p></div>';
		rmb_writer_buffer_remove_all($current_user->ID);
	}
	
	// show pending list
	$titles = rmb_writer_buffer_titles($current_user->ID);
	if($titles)
	{
		echo '<div class="error below-h2"><p>';
		if(count($titles) == 1)
			echo __rmb('A new post has been found on your blog. Publish it here?') . '</p>';
		else
			echo __rmb('New posts have been found on your another blog. Publish them here?') . ' <a href="' . admin_url() . '?rmb_ignore_all=true">' . __rmb('Ignore all') . '</a></p>';
		foreach($titles as $itemid => $title)
		{
			echo '<p><strong>' . $title . '</strong> ';
			echo '<a class="button add-new" href="' . admin_url() . '?rmb_publish=' . rawurlencode($itemid) . '">' . __rmb('Publish') . '</a> ';
			echo '<a href="' . admin_url() . '?rmb_ignore=' . rawurlencode($itemid) . '">' . __rmb('Ignore') . '</a></p>';
		}
		echo '</div>';
	}
	
	echo '</div>';
}

// feed selection engine funcs


// fetch feed
function rmb_fetch_feed($url)
{
	require_once (ABSPATH . WPINC . '/class-feed.php');
	
	$feed = new SimplePie();
	$feed->set_feed_url($url);
	$feed->enable_cache(false);
	$feed->init();
	
	if(!$feed->error())
		return $feed;
}

// get options
function rmb_writer_options($id)
{
	if(!user_can($id, 'publish_posts'))
	{
		wp_die(__rmb('You do not have sufficient permissions to access this page.'));
	}
	$useropt = get_user_option('rmb_admin_options', $id);
	if(!$useropt)
		$useropt['enabled'] = false;
	return $useropt;
}
// get items from feed, return -1 on error
function rmb_writer_feed_items($useropt)
{
	$feed = rmb_fetch_feed($useropt['feedaddr']);
	if($feed)
		return $feed->get_items();
	else
		return -1;
}

// write a post to a buffer
function rmb_writer_buffer_add($id, $item)
{
	$plist = get_user_meta($id, 'rmb_update_list', true);
	$itemid = $item->get_id();
	if(empty($plist) || !in_array($itemid, $plist))
	{
		if(count($plist) >= RMB_FEED_ITEM_MAX)
			delete_user_meta($id, 'rmb_update_item_' . array_shift($plist));
		$plist[] = $itemid;
		update_user_meta($id, 'rmb_update_list', $plist);
	}
	$itemarr = array('title'=>$item->get_title(), 'link'=>$item->get_permalink(), 'time'=>time());
	update_user_meta($id, 'rmb_update_item_' . $itemid, $itemarr);
}
// get all titles in buffer
function rmb_writer_buffer_titles($id, $content = 'title')
{
	$plist = get_user_meta($id, 'rmb_update_list', true);
	if(empty($plist))
		return;
	$parr = array();
	foreach($plist as $t => $itemid)
	{
		$a = get_user_meta($id, 'rmb_update_item_' . $itemid, true);
		$parr[$itemid] = $a[$content];
	}
	return $parr;
}
// delete one
function rmb_writer_buffer_remove($id, $itemid)
{
	$plist = get_user_meta($id, 'rmb_update_list', true);
	if(!in_array($itemid, $plist))
		return;
	unset($plist[array_search($itemid, $plist)]);
	update_user_meta($id, 'rmb_update_list', $plist);
	delete_user_meta($id, 'rmb_update_item_' . $itemid);
}
// remove all
function rmb_writer_buffer_remove_all($id)
{
	$plist = get_user_meta($id, 'rmb_update_list', true);
	if($plist)
		foreach($plist as $i => $itemid)
			delete_user_meta($id, 'rmb_update_item_' . $itemid);
	delete_user_meta($id, 'rmb_update_list');
}
// publish
function rmb_writer_publish($id, $item)
{
	$post = array('post_status'=>'publish', 'post_author'=>$id, 'post_title'=>$item->get_title(), 'post_content'=>$item->get_content(), 'post_date_gmt'=>$item->get_date('Y-m-d H:i:s'));
	$postid = wp_insert_post($post);
	add_post_meta($postid, 'rmb_source_address', $item->get_permalink());
}
// publish with an item id. if failed, return the error msg
function rmb_writer_publish_id($id, $itemid)
{
	$useropt = rmb_writer_options($id);
	if(!$useropt['enabled'])
	{
		rmb_writer_buffer_remove($id, $itemid);
		return __rmb('You have already disabled Reprint My Blog');
	}
	
	// get the updated post
	$itemlist = rmb_writer_feed_items($useropt);
	if(!$itemlist)
		return __rmb('Publish failed. An error occurred when fetching. You may try it later.');
	rmb_writer_buffer_remove($id, $itemid);
	$item = null;
	foreach($itemlist as $i => $curitem)
		if($itemid == $curitem->get_id())
			$item = $curitem;
	if(!$item)
		return __rmb('Publish failed. The post no longer exist on your blog feed.');
	rmb_writer_publish($id, $item);
}
// remove prev list
function rmb_writer_prev_remove($id)
{
	delete_user_meta($id, 'rmb_prev');
}
// update prev list and return
function rmb_writer_prev_update($id, $itemlist)
{
	$hasprev = get_user_meta($id, 'rmb_prev', false);
	$u = array();
	$now = array();
	if($hasprev)
		$prev = $hasprev[0];
	if(empty($prev))
		$prev = array();
	foreach($itemlist as $i => $item)
	{
		if($i >= RMB_FEED_ITEM_MAX)
			break;
		$now[] = $item->get_id();
		if($hasprev && !in_array($now[$i], $prev))
			$u[] = $item;
	}
	update_user_meta($id, 'rmb_prev', $now);
	return $u;
}
// check single writer updates
function rmb_writer_check_update($id, $email)
{
	$useropt = rmb_writer_options($id);
	if(!$useropt['enabled'])
		return -1;
	
	$itemlist = rmb_writer_feed_items($useropt);
	if($itemlist == -1)
		return -1;
	$new = rmb_writer_prev_update($id, $itemlist);
	for($i = count($new) - 1; $i >= 0; $i--)
	{
		if($useropt['autopub'] && count($new) <= RMB_FEED_CHANGE_MAX)
			rmb_writer_publish($id, $new[$i]);
		else
			rmb_writer_buffer_add($id, $new[$i]);
	}
	if($email)
	{
		if(!$useropt['autopub'] || count($new) > RMB_FEED_CHANGE_MAX)
		{
			for($i = count($new) - 1; $i >= 0; $i--)
			{
				$s['c'] = RMB_MAIL_CONTENT;
				if(count($new) > RMB_FEED_CHANGE_MAX)
				{
					if($i != count($new) - 1)
						continue;
					$s['c'] = RMB_MAIL_TOO_FAST_PREFIX . $s['c'];
				}
				add_filter('wp_mail_content_type', create_function('', 'return "text/html";'));
				$headers = array('From: ' . get_option('blogname'), 'Content-Type: text/html');
				$s['header'] = implode('\r\n', $headers) . '\r\n';
				$s['title'] = __rmb('A new post has been found - ') . get_option('blogname');
				$s['c'] = rmb_email_content($new[$i], $s['c']);
				$s['email'] = $email;
				update_option('rmb_email_pending', $s);
				$sendc = 0;
				while(!wp_mail($email, $s['title'], $s['c'], $s['header']))
				{
					$sendc++;
					if($sendc >= RMB_EMAIL_RETRY)
						break;
				}
				if($sendc < RMB_EMAIL_RETRY || $sendc == 0)
					delete_option('rmb_email_pending');
			}
		}
	}
	
	return count($new);
}
// send pending email
function rmb_email_send_pending()
{
	$s = get_option('rmb_email_pending');
	if($s)
	{
		if(!wp_mail($s['email'], $s['title'], $s['c'], $s['header']))
		{
			update_option('rmb_email_failure', $s);
		}
		delete_option('rmb_email_pending');
	}
}
// search for the plugin 'Configure SMTP', if found but not inited, init it.
function rmb_writer_check_configure_smtp()
{
	if(!did_action('init') && array_key_exists('c2c_configure_smtp', $GLOBALS))
	{
		$GLOBALS['c2c_configure_smtp']->init();
	}
}
// check posts that need to show after 24 hours
function rmb_writer_check_default_pub($id)
{
	$sts = rmb_writer_buffer_titles($id, 'time');
	foreach($sts as $itemid => $st)
	{
		if(time() - $st > 86400)
			rmb_writer_publish_id($id, $itemid);
	}
}
// check all writers updates
function rmb_writer_check_all_updates()
{
	setlocale(LC_TIME, '');
	update_option('rmb_latest_time_start', strftime('%c'));
	
	rmb_writer_check_configure_smtp();
	
	rmb_email_send_pending();
	
	$prevchecked = get_option('rmb_prev_checked');
	if($prevchecked === false)
		$start = true;
	else
		$start = false;
	$users = get_users(array());
	foreach($users as $user)
	{
		if(!user_can($user->ID, 'publish_posts'))
			continue;
		if(!$start)
		{
			if($prevchecked == $user->ID)
				$start = true;
		} else
		{
			update_option('rmb_prev_checked', $user->ID);
			rmb_writer_check_default_pub($user->ID);
			rmb_writer_check_update($user->ID, $user->user_email);
		}
	}
	delete_option('rmb_prev_checked');
	
	update_option('rmb_latest_time_end', strftime('%c'));
}

// email content
function rmb_email_content($item, $content)
{
	$s = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></meta></head><body>' . $content . '</body></html>';
	$s = str_replace('[title]', $item->get_title(), $s);
	$s = str_replace('[blogname]', get_option('blogname'), $s);
	$yesurl = admin_url() . '?rmb_publish=' . rawurlencode($item->get_id());
	$s = str_replace('[yeslink]', '<a href="' . $yesurl . '">' . $yesurl . '</a>', $s);
	$nourl = admin_url() . '?rmb_ignore=' . rawurlencode($item->get_id());
	$s = str_replace('[nolink]', '<a href="' . $nourl . '">' . $nourl . '</a>', $s);
	$listurl = admin_url();
	$s = str_replace('[listlink]', '<a href="' . $listurl . '">' . $listurl . '</a>', $s);
	return $s;
}

?>
