<?php
/*
Plugin Name: CF Re-Save All Posts
Plugin URI:  
Description: Calls either the save_post or update_post action on all posts across an entire network of sites. 
Version: 0.1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

load_plugin_textdomain('cf_resave');

function cf_resave_request_handler() {
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'cf_resave_posts':
				if (!is_admin() || !is_super_admin()) {
					wp_die('This can only be accessed by super admins');
				}
				
				// Clean our passed vars
				$how_many = (int) $_POST['how_many']; // 0 for all, 1 for 1
				$blog_id = (int) $_POST['cur_blog'];
				$wp_action = strip_tags(stripslashes($_POST['wp_action']));

				// Figure out what the next blog ID is
				if ($how_many == 1) {
					$next_blog = false;
				}
				else {
					$blogs = cf_resave_get_all_admin_blogs();
					$next_key = (int) array_search($blog_id, $blogs) + 1;
					$next_blog = (isset($blogs[$next_key])) ? $blogs[$next_key] : false;
				}

				// Get posts args
				$args = array(
					'nopaging' => true, // Get all the posts
					'suppress_filters' => true // Get the raw db info
				);
				
				// Switch to the right blog
				$s = switch_to_blog($blog_id);
				
				// Grab our posts 
				$posts = get_posts($args);

				
				$i = 0;
				if (!empty($posts)) {
					foreach ($posts as $post) {
						if (apply_filters('cf_resave_do_post_action_tf', true, $post)) {
							// Call the Action
							do_action($wp_action, $post->ID, $post);
							$i++;
						}
					}
				}
				
				// Restore our current blog
				restore_current_blog();
				
				$r = array(
					'more' 		=> ($how_many == 0 && $next_blog),
					'nextId' 	=> $next_blog,
					'postCount' => $i,
					'blogId' 	=> $blog_id,
				);
				echo json_encode($r);
				exit;
				break;
		}
	}
}
add_action('init', 'cf_resave_request_handler');


function cf_resave_resources() {
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'cf_resave_admin_js':
				cf_resave_admin_js();
				exit;
			case 'cf_resave_admin_css':
				cf_resave_admin_css();
				exit;
		}
	}
}
add_action('init', 'cf_resave_resources', 1);


wp_enqueue_script('jquery');


function cf_resave_admin_js() {
	header('Content-type: text/javascript');
	?>
	jQuery(function($) {
		$("#cf_resave_settings_form").submit(function() {
			process_blog_posts($("#single_blog_id").val());
			return false;
		});
		
		function process_blog_posts(blogId) {
			$.post(
				'/wp-admin/index.php',
				{
					cf_action : 'cf_resave_posts',
					how_many : ($("#single_blog_id").val() == 0) ? 0 : 1,
					cur_blog : (blogId == 0) ? 1 : blogId,
					wp_action : $("#cf_resave_wp_action").val()
				},
				function (r) {
					report_results(r);
					if (r.more == true) {
						process_blog_posts(r.nextId);
					}
				},
				'json'
			);
		}
		function report_results(r) {
			$("#cf-resave-results").append('<tr><td>'+r.blogId+'</td><td>'+r.postCount+'</td></tr>');
		}
	});
	<?php
	die();
}

if (is_admin() && $_GET['page'] == basename(__FILE__)) {
	wp_enqueue_script('cf_resave_admin_js', admin_url('?cf_action=cf_resave_admin_js'), array('jquery'));
}


function cf_resave_admin_css() {
	header('Content-type: text/css');
?>
fieldset.options div.option {
	background: #EAF3FA;
	margin-bottom: 8px;
	padding: 10px;
}
fieldset.options div.option label {
	display: block;
	float: left;
	font-weight: bold;
	margin-right: 10px;
	width: 150px;
}
fieldset.options div.option span.help {
	color: #666;
	font-size: 11px;
	margin-left: 8px;
}
<?php
	die();
}

if (is_admin() && $_GET['page'] == basename(__FILE__)) {
	wp_enqueue_style('cf_resave_admin_css', site_url('?cf_action=cf_resave_admin_css'), array(), '1.0','screen');
}

function cf_resave_admin_menu() {
	if (current_user_can('manage_options')) {
		add_options_page(
			__('CF Re-Save Posts', 'cf_resave')
			, __('CF Re-Save', 'cf_resave')
			, 10
			, basename(__FILE__)
			, 'cf_resave_settings_form'
		);
	}
}
add_action('admin_menu', 'cf_resave_admin_menu');

function cf_resave_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Script', 'cf_resave').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'cf_resave_plugin_action_links', 10, 2);

/**
 * Get a list of all the blog IDs
 *
 * @return array
 */
function cf_resave_get_all_admin_blogs() {
	if (!is_super_admin()) { 
		wp_die('You are not a super-admin, you cannot call this function');
	}
	if (!is_multisite()) {
		wp_die('This site must be a network of sites to be utilized.');
	}
	
	global $wpdb;
	$sql = '
		SELECT blog_id
		FROM '.$wpdb->prefix.'blogs
	';
	$blogs = $wpdb->get_col($sql);
	return $blogs;
}
function cf_resave_settings_form() {
	global $cf_resave_settings;
	
    if ( !is_multisite() || !is_super_admin() ) {
		echo '
<div class="wrap">
	<p>You must be the site admin to utilize this plugin</p>
</div>
		';
	}
	
	$blogs = cf_resave_get_all_admin_blogs();
	if (empty($blogs)) {
		wp_die("Couldn't get blogs.");
	}
	
	$options = '<option>'.implode('</option><option>', $blogs).'</option>';
	print('
<div class="wrap">
	<h2>'.__('CF Re-Save Posts', 'cf_resave').'</h2>
	<form id="cf_resave_settings_form" name="cf_resave_settings_form" action="'.admin_url('options-general.php').'" method="post">
		<fieldset class="options">
			<p>
				<label for="cf_resave_wp_action">WordPress Action</label>
				<select name="cf_resave_wp_action" id="cf_resave_wp_action">
					<option value="save_post">Save Post</option>
					<option value="update_post">Update Post</option>
				</select>
			</p>

			<p>
				<label for="single_blog_id">Single Blog ID: </label>
				<select id="single_blog_id" name="single_blog_id">
					<option value="0">All Blogs</option>
					'.$options.'
				</select>
			</p>
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Run Selected Action', 'cf_resave').'" class="button-primary" />
		</p>
		<table id="cf-resave-results" class="widefat" style="width: 300px;">
			<tr>
				<th>Blog</th>
				<th>Posts</th>
			</tr>
		</table>
	</form>
</div>
	');
}
?>