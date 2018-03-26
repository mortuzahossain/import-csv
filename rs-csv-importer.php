<?php
/*
Plugin Name: Importe CSV
Plugin URI: http://wordpress.org/plugins/importe-csv/
Description: Import posts, categories, tags, custom fields from simple csv file.
Author: Md Mortuza Hossain
Author URI: https://facebook.com/mdmortuza.hossain/
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Version: 1.3
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

// Load Helpers
require dirname( __FILE__ ) . '/class-mor_csv_helper.php';
require dirname( __FILE__ ) . '/class-mor_csv_import_post_helper.php';

if ( class_exists( 'WP_Importer' ) ) {
class RS_CSV_Importer extends WP_Importer {
	
	public $column_indexes = array();
	public $column_keys = array();

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.('Import CSV').'</h2>';
	}

	function footer() {
		echo '</div>';
	}
	
	function greet() {
		echo '<p>'.( 'Choose a CSV (.csv) file to upload, then click Upload file and import.' ).'</p>';
		echo '<p>'.( 'Excel-style CSV file is unconventional and not recommended. LibreOffice has enough export options and recommended for most users.' ).'</p>';
		echo '<p>'.( 'Requirements:' ).'</p>';
		echo '<ol>';
		echo '<li>'.( 'Select UTF-8 as charset.' ).'</li>';
		echo '<li>'.sprintf( ( 'You must use field delimiter as "%s"'), RS_CSV_Helper::DELIMITER ).'</li>';
		echo '<li>'.( 'You must quote all text cells.' ).'</li>';
		echo '</ol>';
		echo '<p>'.( 'Download example CSV files:' );
		echo ' <a href="'.plugin_dir_url( __FILE__ ).'sample/sample.csv">'.( 'csv' ).'</a>,';
		echo ' <a href="'.plugin_dir_url( __FILE__ ).'sample/sample.ods">'.( 'ods' ).'</a>';
		echo ' '.('(OpenDocument Spreadsheet file format for LibreOffice. Please export as csv before import)' );
		echo '</p>';
		?>
		<div id="really-simple-csv-importer-form-options" style="display: none;">
			<h2><?php _e( 'Import Options' ); ?></h2>
			<p><?php _e( 'Replace by post title' ); ?></p>
			<label>
				<input type="radio" name="replace-by-title" value="0" checked="checked" /><?php _e( 'Disable' ); ?>
			</label>
			<label>
				<input type="radio" name="replace-by-title" value="1" /><?php _e( 'Enable' ); ?>
			</label>
		</div>
		<?php
		wp_import_upload_form( add_query_arg('step', 1) );
	}

	function import() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . ( 'Sorry, there has been an error.' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . ( 'Sorry, there has been an error.' ) . '</strong><br />';
			printf( ( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}
		
		$this->id = (int) $file['id'];
		$this->file = get_attached_file($this->id);
		$result = $this->process_posts();
		if ( is_wp_error( $result ) )
			return $result;
	}
	
	public function save_post($post,$meta,$terms,$thumbnail,$is_update) {
		
		if (isset($post['post_tags']) && !empty($post['post_tags'])) {
			$post_tags = $post['post_tags'];
			unset($post['post_tags']);
		}

		if (!empty($thumbnail) && $post['post_type'] == 'attachment') {
			$post['media_file'] = $thumbnail;
			$thumbnail = null;
		}

		if ($is_update) {
			$h = RSCSV_Import_Post_Helper::getByID($post['ID']);
			$h->update($post);
		} else {
			$h = RSCSV_Import_Post_Helper::add($post);
		}
		
		if (isset($post_tags)) {
			$h->setPostTags($post_tags);
		}
		
		$h->setMeta($meta);
		
		foreach ($terms as $key => $value) {
			$h->setObjectTerms($key, $value);
		}
		
		if ($thumbnail) {
			$h->addThumbnail($thumbnail);
		}
		
		return $h;
	}

	function process_posts() {
		$h = new RS_CSV_Helper;

		$handle = $h->fopen($this->file, 'r');
		if ( $handle == false ) {
			echo '<p><strong>'.( 'Failed to open file.' ).'</strong></p>';
			wp_import_cleanup($this->id);
			return false;
		}
		
		$is_first = true;
		$post_statuses = get_post_stati();
		
		echo '<ol>';
		
		while (($data = $h->fgetcsv($handle)) !== FALSE) {
			if ($is_first) {
				$h->parse_columns( $this, $data );
				$is_first = false;
			} else {
				echo '<li>';
				
				$post = array();
				$is_update = false;
				$error = new WP_Error();
				
				$post_type = $h->get_data($this,$data,'post_type');
				if ($post_type) {
					if (post_type_exists($post_type)) {
						$post['post_type'] = $post_type;
					} else {
						$error->add( 'post_type_exists', sprintf(('Invalid post type "%s".'), $post_type) );
					}
				} else {
					echo ('Note: Please include post_type value if that is possible.').'<br>';
				}
				
				$post_id = $h->get_data($this,$data,'ID');
				$post_id = ($post_id) ? $post_id : $h->get_data($this,$data,'post_id');
				if ($post_id) {
					$post_exist = get_post($post_id);
					if ( is_null( $post_exist ) ) { // if the post id is not exists
						$post['import_id'] = $post_id;
					} else {
						if ( !$post_type || $post_exist->post_type == $post_type ) {
							$post['ID'] = $post_id;
							$is_update = true;
						} else {
							$error->add( 'post_type_check', sprintf(('The post type value from your csv file does not match the existing data in your database. post_id: %d, post_type(csv): %s, post_type(db): %s'), $post_id, $post_type, $post_exist->post_type) );
						}
					}
				}

				$post_title = $h->get_data($this,$data,'post_title');
				if ($post_title) {

					if ( ! $is_update && $_POST['replace-by-title'] == 1 ) {
						if ( ! $post_type ) {
							$post_type = 'post';
						}
						$post_id = get_page_by_title($post_title, OBJECT, $post_type);

						if ( ! is_null($post_id) ) {
							$post['ID'] = $post_id;
							$is_update = true;
						}
					}

					$post['post_title'] = $post_title;
				}

				$post_name = $h->get_data($this,$data,'post_name');
				if ($post_name) {
					$post['post_name'] = $post_name;
				}
				
				$post_author = $h->get_data($this,$data,'post_author');
				if ($post_author) {
					if (is_numeric($post_author)) {
						$user = get_user_by('id',$post_author);
					} else {
						$user = get_user_by('login',$post_author);
					}
					if (isset($user) && is_object($user)) {
						$post['post_author'] = $user->ID;
						unset($user);
					}
				}


				$user_login = $h->get_data($this,$data,'post_author_login');
				if ($user_login) {
					$user = get_user_by('login',$user_login);
					if (isset($user) && is_object($user)) {
						$post['post_author'] = $user->ID;
						unset($user);
					}
				}
				
				$post_date = $h->get_data($this,$data,'post_date');
				if ($post_date) {
					$post['post_date'] = date("Y-m-d H:i:s", strtotime($post_date));
				}
				$post_date_gmt = $h->get_data($this,$data,'post_date_gmt');
				if ($post_date_gmt) {
					$post['post_date_gmt'] = date("Y-m-d H:i:s", strtotime($post_date_gmt));
				}
				
				$post_status = $h->get_data($this,$data,'post_status');
				if ($post_status) {
    				if (in_array($post_status, $post_statuses)) {
    					$post['post_status'] = $post_status;
    				}
				}
				
				$post_password = $h->get_data($this,$data,'post_password');
				if ($post_password) {
    				$post['post_password'] = $post_password;
				}


				$post_content = $h->get_data($this,$data,'post_content');
				if ($post_content) {
					$post['post_content'] = $post_content;
				}
				

				$post_excerpt = $h->get_data($this,$data,'post_excerpt');
				if ($post_excerpt) {
					$post['post_excerpt'] = $post_excerpt;
				}
				

				$post_parent = $h->get_data($this,$data,'post_parent');
				if ($post_parent) {
					$post['post_parent'] = $post_parent;
				}
				

				$menu_order = $h->get_data($this,$data,'menu_order');
				if ($menu_order) {
					$post['menu_order'] = $menu_order;
				}
				

				$comment_status = $h->get_data($this,$data,'comment_status');
				if ($comment_status) {
					$post['comment_status'] = $comment_status;
				}
				

				$post_category = $h->get_data($this,$data,'post_category');
				if ($post_category) {
					$categories = preg_split("/,+/", $post_category);
					if ($categories) {
						$post['post_category'] = wp_create_categories($categories);
					}
				}
				

				$post_tags = $h->get_data($this,$data,'post_tags');
				if ($post_tags) {
					$post['post_tags'] = $post_tags;
				}
				

				$post_thumbnail = $h->get_data($this,$data,'post_thumbnail');
				
				$meta = array();
				$tax = array();

				foreach ($data as $key => $value) {
					if ($value !== false && isset($this->column_keys[$key])) {
						if (substr($this->column_keys[$key], 0, 4) == 'tax_') {
							$customtaxes = preg_split("/,+/", $value);
							$taxname = substr($this->column_keys[$key], 4);
							$tax[$taxname] = array();
							foreach($customtaxes as $key => $value ) {
								$tax[$taxname][] = $value;
							}
						}
						else {
							$meta[$this->column_keys[$key]] = $value;
						}
					}
				}
				
				$post = apply_filters( 'really_simple_csv_importer_save_post', $post, $is_update );
				$meta = apply_filters( 'really_simple_csv_importer_save_meta', $meta, $post, $is_update );
				$tax = apply_filters( 'really_simple_csv_importer_save_tax', $tax, $post, $is_update );
				$post_thumbnail = apply_filters( 'really_simple_csv_importer_save_thumbnail', $post_thumbnail, $post, $is_update );
				$dry_run = apply_filters( 'really_simple_csv_importer_dry_run', false );
				
				if (!$error->get_error_codes() && $dry_run == false) {
					$class = apply_filters( 'really_simple_csv_importer_class', null );
					if ($class && class_exists($class,false)) {
						$importer = new $class;
						$result = $importer->save_post($post,$meta,$tax,$post_thumbnail,$is_update);
					} else {
						$result = $this->save_post($post,$meta,$tax,$post_thumbnail,$is_update);
					}
					
					if ($result->isError()) {
						$error = $result->getError();
					} else {
						$post_object = $result->getPost();
						
						if (is_object($post_object)) {
							do_action( 'really_simple_csv_importer_post_saved', $post_object );
						}
						
						echo esc_html(sprintf(('Processing "%s" done.'), $post_title));
					}
				}
				
				foreach ($error->get_error_messages() as $message) {
					echo esc_html($message).'<br>';
				}
				
				echo '</li>';

				wp_cache_flush();
			}
		}
		
		echo '</ol>';

		$h->fclose($handle);
		
		wp_import_cleanup($this->id);
		
		echo '<h3>'.('All Done.').'</h3>';
	}

	// dispatcher
	function dispatch() {
		$this->header();
		
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
				set_time_limit(0);
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}
		
		$this->footer();
	}
	
}

// Initialize
function really_simple_csv_importer() {
	load_plugin_textdomain( 'really-simple-csv-importer', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
	
    $rs_csv_importer = new RS_CSV_Importer();
    register_importer('csv', ('CSV'), ('Import posts, categories, tags, custom fields from simple csv file.'), array ($rs_csv_importer, 'dispatch'));
}
add_action( 'plugins_loaded', 'really_simple_csv_importer' );

function really_simple_csv_importer_enqueue($hook) {
	if ( 'admin.php' != $hook ) {
		return;
	}

	wp_enqueue_script( 'really_simple_csv_importer_admin_script', plugin_dir_url( __FILE__ ) . 'auto.js', array(), false, true );
}
add_action( 'admin_enqueue_scripts', 'really_simple_csv_importer_enqueue' );

}
