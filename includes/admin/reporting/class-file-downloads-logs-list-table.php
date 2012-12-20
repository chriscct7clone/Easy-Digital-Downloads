<?php

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class EDD_File_Downloads_Log_Table extends WP_List_Table {

	var $per_page = 30;

	function __construct(){
		global $status, $page;

		//Set parent defaults
		parent::__construct( array(
			'singular'  => edd_get_label_singular(),    // singular name of the listed records
			'plural'    => edd_get_label_plural(),    	// plural name of the listed records
			'ajax'      => false             			// does this table support ajax?
		) );

		add_action( 'edd_log_view_actions', array( $this, 'downloads_filter' ) );

	}


	/**
	 * Show the search field
	 *
	 * @access      private
	 * @since       1.4
	 * @return      void
	 */

	function search_box( $text, $input_id ) {
		if ( empty( $_REQUEST['s'] ) && !$this->has_items() )
			return;

		$input_id = $input_id . '-search-input';

		if ( ! empty( $_REQUEST['orderby'] ) )
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $_REQUEST['orderby'] ) . '" />';
		if ( ! empty( $_REQUEST['order'] ) )
			echo '<input type="hidden" name="order" value="' . esc_attr( $_REQUEST['order'] ) . '" />';
?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $text; ?>:</label>
			<input type="search" id="<?php echo $input_id ?>" name="s" value="<?php _admin_search_query(); ?>" />
			<?php submit_button( $text, 'button', false, false, array('ID' => 'search-submit') ); ?>
		</p>
<?php

	}


	function column_default( $item, $column_name ) {
		switch( $column_name ){
			case 'download' :
				return '<a href="' . add_query_arg( 'download', $item[ $column_name ] ) . '" >' . get_the_title( $item[ $column_name ] ) . '</a>';
			case 'user_id' :
				return '<a href="' . add_query_arg( 'user', $item[ $column_name ] ) . '">' . $item[ 'user_name' ] . '</a>';
			default:
				return $item[ $column_name ];
		}
	}


	function get_columns() {
		$columns = array(
			'ID'		=> __( 'Log ID', 'edd' ),
			'download'	=> edd_get_label_singular(),
			'user_id'  	=> __( 'User', 'edd' ),
			'payment_id'=> __( 'Payment ID', 'edd' ),
			'file'  	=> __( 'File', 'edd' ),
			'ip'  		=> __( 'IP Address', 'edd' ),
			'date'  	=> __( 'Date', 'edd' )
		);
		return $columns;
	}

	function get_filtered_user() {
		return isset( $_GET['user'] ) ? absint( $_GET['user'] ) : false;
	}

	function get_filtered_download() {
		return !empty( $_GET['download'] ) ? absint( $_GET['download'] ) : false;
	}

	function get_paged() {
		return isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
	}

	function bulk_actions() {
		// these aren't really bulk actions but this outputs the markup in the right place
		edd_log_views();
	}

	function downloads_filter() {
		$downloads = get_posts( array(
			'post_type'      => 'download',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC'
		) );
		if( $downloads ) {
			echo '<select name="download" id="edd-log-download-filter">';
				echo '<option value="0">' . __( 'All', 'edd' ) . '</option>';
				foreach( $downloads as $download ) {
					echo '<option value="' . $download->ID . '"' . selected( $download->ID, $this->get_filtered_download() ) . '>' . esc_html( $download->post_title ) . '</option>';
				}
			echo '</select>';
		}
	}

	function logs_data() {

		global $edd_logs;

		$logs_data = array();

		$paged    = $this->get_paged();
		$user     = $this->get_filtered_user();
		$download = $this->get_filtered_download();

		$log_query = array(
			'post_parent' => $download,
			'log_type'    => 'file_download',
			'paged'       => $paged,
			'meta_query'  => array()
		);

		if( $user ) {

			// show only logs from a specific user

			$log_query['meta_query'][] = array(
				'key'   => '_edd_log_user_id',
				'value' => $user
			);
		}

		if( isset( $_GET['s'] ) ) {
			$search = urldecode( $_GET['s'] );

			if( filter_var( $search, FILTER_VALIDATE_IP ) ) {
				// this is an IP address search
				$key     = '_edd_log_ip';
				$compare = '=';

			} else if( is_email( $search ) ) {
				// this is an email search
				$key     = '_edd_log_user_info';
				$compare = 'LIKE';

			} else {
				$key     = '';
				$compare = '';
			}

			$log_query['meta_query'][] = array(
				'key'     => $key,
				'value'   => $search,
				'compare' => $compare
			);

		}

		$logs = $edd_logs->get_connected_logs( $log_query );

		if( $logs ) {

			foreach( $logs as $log ) {

				$user_info 	= get_post_meta( $log->ID, '_edd_log_user_info', true );
				$payment_id = get_post_meta( $log->ID, '_edd_log_payment_id', true );
				$ip 		= get_post_meta( $log->ID, '_edd_log_ip', true );
				$user_id 	= isset( $user_info['id']) ? $user_info['id'] : 0;
				$user_data 	= get_userdata( $user_id );
				$files 		= edd_get_download_files( $log->post_parent );
				$file_id 	= (int) get_post_meta( $log->ID, '_edd_log_file_id', true );
				$file_id 	= $file_id !== false ? $file_id : 0;
				$file_name 	= isset( $files[ $file_id ]['name'] ) ? $files[ $file_id ]['name'] : null;

				$logs_data[] = array(
					'ID' 		=> $log->ID,
					'download'	=> $log->post_parent,
					'payment_id'=> $payment_id,
					'user_id'	=> $user_data ? $user_data->ID : $user_info['email'],
					'user_name'	=> $user_data ? $user_data->display_name : $user_info['email'],
					'file'		=> $file_name,
					'ip'		=> $ip,
					'date'		=> $log->post_date
				);
			}
		}

		return $logs_data;
	}


	/** ************************************************************************
	 * @uses $this->_column_headers
	 * @uses $this->items
	 * @uses $this->get_columns()
	 * @uses $this->get_sortable_columns()
	 * @uses $this->get_pagenum()
	 * @uses $this->set_pagination_args()
	 **************************************************************************/

	function prepare_items() {

		global $edd_logs;

		/**
		 * First, lets decide how many records per page to show
		 */
		$per_page = $this->per_page;

		$columns = $this->get_columns();

		$hidden = array(); // no hidden columns

		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$current_page = $this->get_pagenum();

		$this->items = $this->logs_data();

		$user     = $this->get_filtered_user();
		$download = $this->get_filtered_download();

		if( $user ) {
			$meta_query = array(
				array(
					'key'   => '_edd_log_user_id',
					'value' => $user
				)
			);
		} else {
			$meta_query = false;
		}
		$total_items = $edd_logs->get_log_count( $download, 'file_download', $meta_query );

		$this->set_pagination_args( array(
				'total_items' => $total_items,                  	// WE have to calculate the total number of items
				'per_page'    => $per_page,                     	// WE have to determine how many items to show on a page
				'total_pages' => ceil( $total_items / $per_page )   // WE have to calculate the total number of pages
			)
		);
	}

}