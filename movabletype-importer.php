<?php
/*
Plugin Name: Movable Type and TypePad Importer
Plugin URI: https://wordpress.org/extend/plugins/movabletype-importer/
Description: Import posts and comments from a Movable Type or TypePad blog.
Author: wordpressdotorg
Author URI: https://wordpress.org/
Version: 0.6.3
License: GPL version 2 or later - https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// define this to false to disallow duplicated comments. Warning: very slow on large imports
if ( !defined('WP_MT_IMPORT_ALLOW_DUPE_COMMENTS') ) {
	define( 'WP_MT_IMPORT_ALLOW_DUPE_COMMENTS', true );
}

// define this to true to force autocommit during import. Warning: extremely slow on large imports.
if ( !defined('WP_MT_IMPORT_FORCE_AUTOCOMMIT') ) {
	define( 'WP_MT_IMPORT_FORCE_AUTOCOMMIT', false );
}

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Movable Type and TypePad Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class MT_Import extends WP_Importer {

	var $posts = array ();
	var $file;
	var $id;
	var $mtnames = array ();
	var $newauthornames = array ();
	var $j = -1;

	function header() {
		echo '<div class="wrap">';

		if ( version_compare( get_bloginfo( 'version' ), '3.8.0', '<' ) ) {
			screen_icon();
		}

		echo '<h2>'.__('Import Movable Type or TypePad', 'movabletype-importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		$this->header();
?>
<div class="narrow">
<p><?php _e( 'Howdy! We are about to begin importing all of your Movable Type or TypePad entries into WordPress. To begin, either choose a file to upload and click &#8220;Upload file and import&#8221;, or use FTP to upload your MT export file as <code>mt-export.txt</code> in your <code>/wp-content/</code> directory and then click &#8220;Import mt-export.txt&#8221;.' , 'movabletype-importer'); ?></p>

<?php wp_import_upload_form( add_query_arg('step', 1) ); ?>
<form method="post" action="<?php echo esc_attr(add_query_arg('step', 1)); ?>" class="import-upload-form">

<?php wp_nonce_field('import-upload'); ?>
<p>
	<input type="hidden" name="upload_type" value="ftp" />
<?php _e('Or use <code>mt-export.txt</code> in your <code>/wp-content/</code> directory', 'movabletype-importer'); ?></p>
<p class="submit">
<input type="submit" class="button" value="<?php esc_attr_e('Import mt-export.txt', 'movabletype-importer'); ?>" />
</p>
</form>
<p><?php _e('The importer is smart enough not to import duplicates, so you can run this multiple times without worry if&#8212;for whatever reason&#8212;it doesn&#8217;t finish. If you get an <strong>out of memory</strong> error try splitting up the import file into pieces.', 'movabletype-importer'); ?> </p>
</div>
<?php
		$this->footer();
	}

	function users_form($n) {
		$users = version_compare( get_bloginfo( 'version' ), '3.1.0', '<' ) ? get_users_of_blog() : get_users();
?><select name="userselect[<?php echo $n; ?>]">
	<option value="#NONE#"><?php _e('&mdash; Select &mdash;', 'movabletype-importer') ?></option>
	<?php
		foreach ( $users as $user )
			echo '<option value="' . $user->user_login . '">' . $user->user_login . '</option>';
	?>
	</select>
	<?php
	}

	function has_gzip() {
		return is_callable('gzopen');
	}

	function fopen($filename, $mode='r') {
		if ( $this->has_gzip() )
			return gzopen($filename, $mode);
		return fopen($filename, $mode);
	}

	function feof($fp) {
		if ( $this->has_gzip() )
			return gzeof($fp);
		return feof($fp);
	}

	function fgets($fp, $len=8192) {
		if ( $this->has_gzip() )
			return gzgets($fp, $len);
		return fgets($fp, $len);
	}

	function fclose($fp) {
		if ( $this->has_gzip() )
			return gzclose($fp);
		return fclose($fp);
 	}

	//function to check the authorname and do the mapping
	function checkauthor($author) {
		//mtnames is an array with the names in the mt import file
		$pass = wp_generate_password();
		if (!(in_array($author, $this->mtnames))) { //a new mt author name is found
			++ $this->j;
			$this->mtnames[$this->j] = $author; //add that new mt author name to an array
			$user_id = username_exists($this->newauthornames[$this->j]); //check if the new author name defined by the user is a pre-existing wp user
			if (!$user_id) { //banging my head against the desk now.
				if ($this->newauthornames[$this->j] == 'left_blank') { //check if the user does not want to change the authorname
					$user_id = wp_create_user($author, $pass);
					$this->newauthornames[$this->j] = $author; //now we have a name, in the place of left_blank.
				} else {
					$user_id = wp_create_user($this->newauthornames[$this->j], $pass);
				}
			} else {
				return $user_id; // return pre-existing wp username if it exists
			}
		} else {
			$key = array_search($author, $this->mtnames); //find the array key for $author in the $mtnames array
			$user_id = username_exists($this->newauthornames[$key]); //use that key to get the value of the author's name from $newauthornames
		}

		return $user_id;
	}

	function get_mt_authors() {
		$temp = array();
		$authors = array();

		$handle = $this->fopen($this->file, 'r');
		if ( $handle == null )
			return false;

		$in_comment = false;
		while ( $line = $this->fgets($handle) ) {
			$line = trim($line);

			if ( 'COMMENT:' == $line )
				$in_comment = true;
			else if ( '-----' == $line )
				$in_comment = false;

			if ( $in_comment || 0 !== strpos($line,"AUTHOR:") )
				continue;

			$temp[] = trim( substr($line, strlen("AUTHOR:")) );
		}

		//we need to find unique values of author names, while preserving the order, so this function emulates the unique_value(); php function, without the sorting.
		$authors[0] = array_shift($temp);
		$y = count($temp) + 1;
		for ($x = 1; $x < $y; $x ++) {
			$next = array_shift($temp);
			if (!(in_array($next, $authors)))
				array_push($authors, $next);
		}

		$this->fclose($handle);

		return $authors;
	}

	function get_authors_from_post() {
		$formnames = array ();
		$selectnames = array ();

		foreach ($_POST['user'] as $key => $line) {
			$newname = trim(stripslashes($line));
			if ($newname == '')
				$newname = 'left_blank'; //passing author names from step 1 to step 2 is accomplished by using POST. left_blank denotes an empty entry in the form.
			array_push($formnames, $newname);
		} // $formnames is the array with the form entered names

		foreach ($_POST['userselect'] as $user => $key) {
			$selected = trim(stripslashes($key));
			array_push($selectnames, $selected);
		}

		$count = count($formnames);
		for ($i = 0; $i < $count; $i ++) {
			if ($selectnames[$i] != '#NONE#') { //if no name was selected from the select menu, use the name entered in the form
				array_push($this->newauthornames, "$selectnames[$i]");
			} else {
				array_push($this->newauthornames, "$formnames[$i]");
			}
		}
	}

	function mt_authors_form() {
?>
<div class="wrap">
<?php
if ( version_compare( get_bloginfo( 'version' ), '3.8.0', '<' ) ) {
	screen_icon();
}
?>
<h2><?php _e('Assign Authors', 'movabletype-importer'); ?></h2>
<p><?php _e('To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as admin&#8217;s entries.', 'movabletype-importer'); ?></p>
<p><?php _e('Below, you can see the names of the authors of the Movable Type posts in <em>italics</em>. For each of these names, you can either pick an author in your WordPress installation from the menu, or enter a name for the author in the textbox.', 'movabletype-importer'); ?></p>
<p><?php _e('If a new user is created by WordPress, a password will be randomly generated. Manually change the user&#8217;s details if necessary.', 'movabletype-importer'); ?></p>
	<?php


		$authors = $this->get_mt_authors();
		echo '<ol id="authors">';
		echo '<form action="?import=mt&amp;step=2&amp;id=' . $this->id . '" method="post">';
		wp_nonce_field('import-mt');
		$j = -1;
		foreach ($authors as $author) {
			++ $j;
			echo '<li><label>'.__('Current author:', 'movabletype-importer').' <strong>'.$author.'</strong><br />'.sprintf(__('Create user %1$s or map to existing', 'movabletype-importer'), ' <input type="text" value="'. esc_attr($author) .'" name="'.'user[]'.'" maxlength="30"> <br />');
			$this->users_form($j);
			echo '</label></li>';
		}

		echo '<p class="submit"><input type="submit" class="button" value="'.esc_attr__('Submit', 'movabletype-importer').'"></p>'.'<br />';
		echo '</form>';
		echo '</ol></div>';

	}

	function select_authors() {
		if ( isset( $_POST['upload_type'] ) && $_POST['upload_type'] === 'ftp' ) {
			$file['file'] = WP_CONTENT_DIR . '/mt-export.txt';
			if ( !file_exists($file['file']) )
				$file['error'] = __('<code>mt-export.txt</code> does not exist', 'movabletype-importer');
		} else {
			$file = wp_import_handle_upload();
		}
		if ( isset($file['error']) ) {
			$this->header();
			echo '<p>'.__('Sorry, there has been an error', 'movabletype-importer').'.</p>';
			echo '<p><strong>' . $file['error'] . '</strong></p>';
			$this->footer();
			return;
		}
		$this->file = $file['file'];
		$this->id = array_key_exists( 'id', $file ) ? (int) $file['id'] : 0;

		$this->mt_authors_form();
	}

	function save_post(&$post, &$comments, &$pings) {
		$post = get_object_vars($post);
		$post = add_magic_quotes($post);
		$post = (object) $post;

		if ( $post_id = post_exists($post->post_title, '', $post->post_date) ) {
			echo '<li>';
			printf(__('Post <em>%s</em> already exists.', 'movabletype-importer'), stripslashes($post->post_title));
		} else {
			echo '<li>';
			printf(__('Importing post <em>%s</em>...', 'movabletype-importer'), stripslashes($post->post_title));

			if ( '' != trim( $post->extended ) )
					$post->post_content .= "\n<!--more-->\n$post->extended";

			$post->post_author = $this->checkauthor($post->post_author); //just so that if a post already exists, new users are not created by checkauthor
			$post_id = wp_insert_post($post);
			if ( is_wp_error( $post_id ) )
				return $post_id;

			// Add categories.
			if ( 0 != count($post->categories) ) {
				wp_create_categories($post->categories, $post_id);
			}

			 // Add tags or keywords
			if ( 1 < strlen($post->post_keywords) ) {
			 	// Keywords exist.
				printf('<br />'.__('Adding tags <em>%s</em>...', 'movabletype-importer'), stripslashes($post->post_keywords));
				wp_add_post_tags($post_id, $post->post_keywords);
			}
		}

		$num_comments = 0;
		foreach ( $comments as $comment ) {
			$comment = get_object_vars($comment);
			$comment = add_magic_quotes($comment);

			if ( WP_MT_IMPORT_ALLOW_DUPE_COMMENTS || !comment_exists($comment['comment_author'], $comment['comment_date'])) {
				$comment['comment_post_ID'] = $post_id;
				$comment = wp_filter_comment($comment);
				wp_insert_comment($comment);
				$num_comments++;
			}
		}

		if ( $num_comments )
			printf(' '._n('(%s comment)', '(%s comments)', $num_comments, 'movabletype-importer'), $num_comments);

		$num_pings = 0;
		foreach ( $pings as $ping ) {
			$ping = get_object_vars($ping);
			$ping = add_magic_quotes($ping);

			if ( WP_MT_IMPORT_ALLOW_DUPE_COMMENTS || !comment_exists($ping['comment_author'], $ping['comment_date'])) {
				$ping['comment_content'] = "<strong>{$ping['title']}</strong>\n\n{$ping['comment_content']}";
				$ping['comment_post_ID'] = $post_id;
				$ping = wp_filter_comment($ping);
				wp_insert_comment($ping);
				$num_pings++;
			}
		}

		if ( $num_pings )
			printf(' '._n('(%s ping)', '(%s pings)', $num_pings, 'movabletype-importer'), $num_pings);

		echo "</li>";
		//ob_flush();flush();
	}

	private function create_post() {
		$post = new StdClass();

		$post->post_content = '';
		$post->extended = '';
		$post->post_excerpt = '';
		$post->post_keywords = '';
		$post->categories = array();

		return $post;
	}

	private function create_comment() {
		$comment = new StdClass();

		$comment->comment_content = '';
		$comment->comment_author = '';
		$comment->comment_author_url = '';
		$comment->comment_author_email = '';
		$comment->comment_author_IP = '';
		$comment->comment_date = null;
		$comment->comment_post_ID = null;

		return $comment;
	}

	function process_posts() {
		global $wpdb;

		$handle = $this->fopen($this->file, 'r');
		if ( $handle == null )
			return false;

		$context = '';
		$post = $this->create_post();
		$comment = $this->create_comment();
		$comments = array();
		$ping = $this->create_comment();
		$pings = array();

		echo "<div class='wrap'><ol>";

		// disable some slowdown points, turn them back on later
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// turn off autocommit, for speed
		if ( !WP_MT_IMPORT_FORCE_AUTOCOMMIT ) {
			$wpdb->query('SET autocommit = 0');
		}

		$count = 0;
		while ( $line = $this->fgets($handle) ) {

			// commit once every 500 posts
			$count++;
			if ( !WP_MT_IMPORT_FORCE_AUTOCOMMIT && $count % 500 === 0 ) {
				$wpdb->query('COMMIT');
			}

			$line = trim($line);

			if ( '-----' == $line ) {
				// Finishing a multi-line field
				if ( 'comment' == $context ) {
					$comments[] = $comment;
					$comment = $this->create_comment();
				} else if ( 'ping' == $context ) {
					$pings[] = $ping;
					$ping = $this->create_comment();
				}
				$context = '';
			} else if ( '--------' == $line ) {
				// Finishing a post.
				$context = '';
				$result = $this->save_post( $post, $comments, $pings );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$post = $this->create_post();
				$comment = $this->create_comment();
				$comments = array();
				$ping = $this->create_comment();
				$pings = array();
			} else if ( 'BODY:' == $line ) {
				$context = 'body';
			} else if ( 'EXTENDED BODY:' == $line ) {
				$context = 'extended';
			} else if ( 'EXCERPT:' == $line ) {
				$context = 'excerpt';
			} else if ( 'KEYWORDS:' == $line ) {
				$context = 'keywords';
			} else if ( 'COMMENT:' == $line ) {
				$context = 'comment';
			} else if ( 'PING:' == $line ) {
				$context = 'ping';
			} else if ( 0 === strpos($line, 'AUTHOR:') ) {
				$author = trim( substr($line, strlen('AUTHOR:')) );
				if ( '' == $context )
					$post->post_author = $author;
				else if ( 'comment' == $context )
					 $comment->comment_author = $author;
			} else if ( 0 === strpos($line, 'TITLE:') ) {
				$title = trim( substr($line, strlen('TITLE:')) );
				if ( '' == $context )
					$post->post_title = $title;
				else if ( 'ping' == $context )
					$ping->title = $title;
			} else if ( 0 === strpos($line, 'BASENAME:') ) {
				$slug = trim( substr($line, strlen('BASENAME:')) );
				if ( !empty( $slug ) )
					$post->post_name = $slug;
			} else if ( 0 === strpos($line, 'STATUS:') ) {
				$status = trim( strtolower( substr($line, strlen('STATUS:')) ) );
				if ( empty($status) )
					$status = 'publish';
				$post->post_status = $status;
			} else if ( 0 === strpos($line, 'ALLOW COMMENTS:') ) {
				$allow = trim( substr($line, strlen('ALLOW COMMENTS:')) );
				if ( $allow == 1 )
					$post->comment_status = 'open';
				else
					$post->comment_status = 'closed';
			} else if ( 0 === strpos($line, 'ALLOW PINGS:') ) {
				$allow = trim( substr($line, strlen('ALLOW PINGS:')) );
				if ( $allow == 1 )
					$post->ping_status = 'open';
				else
					$post->ping_status = 'closed';
			} else if ( 0 === strpos($line, 'CATEGORY:') ) {
				$category = trim( substr($line, strlen('CATEGORY:')) );
				if ( '' != $category )
					$post->categories[] = $category;
			} else if ( 0 === strpos($line, 'PRIMARY CATEGORY:') ) {
				$category = trim( substr($line, strlen('PRIMARY CATEGORY:')) );
				if ( '' != $category )
					$post->categories[] = $category;
			} else if ( 0 === strpos($line, 'DATE:') ) {
				$date = trim( substr($line, strlen('DATE:')) );
				$date = strtotime($date);
				$date = date('Y-m-d H:i:s', $date);
				$date_gmt = get_gmt_from_date($date);
				if ( '' == $context ) {
					$post->post_modified = $date;
					$post->post_modified_gmt = $date_gmt;
					$post->post_date = $date;
					$post->post_date_gmt = $date_gmt;
				} else if ( 'comment' == $context ) {
					$comment->comment_date = $date;
				} else if ( 'ping' == $context ) {
					$ping->comment_date = $date;
				}
			} else if ( 0 === strpos($line, 'EMAIL:') ) {
				$email = trim( substr($line, strlen('EMAIL:')) );
				if ( 'comment' == $context )
					$comment->comment_author_email = $email;
				else
					$ping->comment_author_email = '';
			} else if ( 0 === strpos($line, 'IP:') ) {
				$ip = trim( substr($line, strlen('IP:')) );
				if ( 'comment' == $context )
					$comment->comment_author_IP = $ip;
				else
					$ping->comment_author_IP = $ip;
			} else if ( 0 === strpos($line, 'URL:') ) {
				$url = trim( substr($line, strlen('URL:')) );
				if ( 'comment' == $context )
					$comment->comment_author_url = $url;
				else
					$ping->comment_author_url = $url;
			} else if ( 0 === strpos($line, 'BLOG NAME:') ) {
				$blog = trim( substr($line, strlen('BLOG NAME:')) );
				$ping->comment_author = $blog;
			} else {
				// Processing multi-line field, check context.

				if( !empty($line) )
					$line .= "\n";

				if ( 'body' == $context ) {
					$post->post_content .= $line;
				} else if ( 'extended' ==  $context ) {
					$post->extended .= $line;
				} else if ( 'excerpt' == $context ) {
					$post->post_excerpt .= $line;
				} else if ( 'keywords' == $context ) {
					$post->post_keywords .= $line;
				} else if ( 'comment' == $context ) {
					$comment->comment_content .= $line;
				} else if ( 'ping' == $context ) {
					$ping->comment_content .= $line;
				}
			}
		}

		$this->fclose($handle);

		echo '</ol>';

		// commit the changes, turn autocommit back on
		if ( !WP_MT_IMPORT_FORCE_AUTOCOMMIT ) {
			$wpdb->query('COMMIT');
			$wpdb->query('SET autocommit = 1');
		}

		// turn basic caching and counting back on, flush the cache. This will also cause a full count to be performed for terms and comments
		wp_suspend_cache_invalidation( false );
		wp_cache_flush();
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		wp_import_cleanup($this->id);
		do_action('import_done', 'mt');

		echo '<h3>'.sprintf(__('All done. <a href="%s">Have fun!</a>', 'movabletype-importer'), get_option('home')).'</h3></div>';
	}

	function import() {
		$this->id = (int) $_GET['id'];
		if ( $this->id == 0 )
			$this->file = WP_CONTENT_DIR . '/mt-export.txt';
		else
			$this->file = get_attached_file($this->id);
		$this->get_authors_from_post();
		$result = $this->process_posts();
		if ( is_wp_error( $result ) )
			return $result;
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$this->select_authors();
				break;
			case 2:
				check_admin_referer('import-mt');
				set_time_limit(0);
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}
	}
}

$mt_import = new MT_Import();

register_importer('mt', __('Movable Type and TypePad', 'movabletype-importer'), __('Import posts and comments from a Movable Type or TypePad blog.', 'movabletype-importer'), array ($mt_import, 'dispatch'));

} // class_exists( 'WP_Importer' )

function movabletype_importer_init() {
    load_plugin_textdomain( 'movabletype-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'movabletype_importer_init' );
