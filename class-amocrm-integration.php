<?php
/*
Plugin Name: WooAmoConnector
Version: 0.1
Plugin URI: https://wpcraft.ru/product/wooamoconnector/
Description: AmoCRM & WooCommerce - интеграция. Создание сделок и контактов с сайта в CRM
Author: WPCraft
Author URI: http://wpcraft.ru/?utm_source=wpplugin&utm_medium=plugin-link&utm_campaign=WooAmoConnector
*/



/**
 * Class AmoCRM_Integration
 * Do integration of WooCommerce with AmoCRM. Create lead and contact during adding product to the cart, and
 * marking lead as completed when product purchased.
 * @package   Project_Name\Core\Includes
 * @author    Serj Bielanovskiy <kievswebdev@gmail.com>
 * @author    deco.agency <https://deco.agency/>
 * @copyright 2015 deco.agency <https://deco.agency/>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link      https://github.com/sprightly/woocommerce-amocrm
 */
class WooAmoConnector {

	private $login = 'm@wpcraft.ru';
	private $key = '6eb9445b11679523b09c1d67b7f15ca7';
	private $subdomain = 'wpcraft';
	public $api = null;

	/**
	 * Needed in order to get status id from AmoCRM
	 * @var array
	 */
	private $leads_statuses_titles = array(
		'new'       => 'Может быть купят',
		'completed' => 'Успешно реализовано',
	);

	/**
	 * Names of the $_POST variables in that will be passed information about buyer.
	 * @var array
	 */
	private $buyer_info_vars = array(
		'full_name' => 'FULL_NAME',
		'phone' => 'PHONE',
		'email' => 'EMAIL',
	);

	/**
	 * Class instance.
	 */
	protected static $_instance = null;

	/**
	 * Get class instance
	 */
	final public static function instance() {
		$class = get_called_class();

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new $class();
		}

		return self::$_instance;
	}

	function __construct() {
		// add_action( 'woocommerce_add_to_cart', array( $this, 'hook_on_add_product' ), 10, 6 );

		add_filter( 'cron_schedules', array($this, 'add_schedule') );
		add_action('init', [$this, 'init_cron']);

		add_action('wooamoconnector_cron_worker', [$this, 'send_walker']);

		add_action( 'woocommerce_order_status_changed', array( $this, 'hook_on_order_status_change' ), 10, 3 );

		add_action('admin_menu', [$this, 'add_menu_tools']);
		add_action('wac_sync', [$this, 'send_walker']);
	}


	function send_order($order_id){
		$order = wc_get_order($order_id);

		$full_name = 'test';
		$phone = 'test';
		$email = 'test@test.test';
		$product_id = 15551;
		$check1 = $this->add_contact( $full_name, $phone, $email );
		$check2 = $this->add_lead( $full_name, $product_id );

		var_dump($check1);
		var_dump($check2);
		exit;

		return false;
	}






	function add_menu_tools(){
		add_submenu_page(
			'tools.php',
			'AmoCRM - инструменты',
			'AmoCRM',
			'manage_options',
			'wooamoconnector-tools',
			[$this, 'dysplay_tools']
		);

	}

	function dysplay_tools(){
		$url1 = admin_url('tools.php?page=wooamoconnector-tools');
		$url2 = add_query_arg('a', 'wac-sync', $url1);
		?>
		<div class="wooamoconnector-tools-wrapper">
			<h1>AmoCRM</h1>
			<?php
				if(empty($_GET['a'])){
					echo '<p>Ручная отправка заказов в AmoCRM</p>';
					printf('<a href="%s" class="btn button">Выполнить</a>', $url2);
				} else {
					echo '<hr>';
					printf('<a href="%s" class="btn button">Вернуться</a>', $url1);
					echo '<hr>';
					do_action('wac_sync');
				}
			?>

			<?php  ?>
		</div>
		<?php
	}



	function send_walker(){
		$args = array(
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wooamoc_send_timestamp',
      'meta_compare' => 'NOT EXISTS',
    );
    $orders = get_posts($args);

		$result_list = [];
		foreach ($orders as $key => $order) {
			$check = $this->send_order($order->ID);

      if($check){
        update_post_meta($order->ID, 'wooamoc_send_timestamp', date("Y-m-d H:i:s"));
				$result_list[] = $order->ID;
      }
		}

		var_dump($result_list);

	}

	function add_schedule( $schedules ) {
    $schedules['wooamoconnector_cron'] = array( 'interval' => 60, 'display' => 'WooAmoConnector Cron Worker' );
    return $schedules;
  }

	function init_cron(){
		if ( ! wp_next_scheduled( 'wooamoconnector_cron_worker' ) ) {
      wp_schedule_event( time(), 'wooamoconnector_cron', 'wooamoconnector_cron_worker' );
    }
	}

	function hook_on_add_product( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		//Get user's details
		$full_name = $_POST[$this->buyer_info_vars['full_name']];
		$phone     = $_POST[$this->buyer_info_vars['phone']];
		$email     = $_POST[$this->buyer_info_vars['email']];

		//We can proceed only with info
		if ( $full_name && $phone && $email && $product_id ) {
			$this->add_contact( $full_name, $phone, $email );
			$this->add_lead( $full_name, $product_id );
		}
	}

	function hook_on_order_status_change( $order_id, $old_status, $new_status ) {
		$this->update_lead( $order_id, $old_status, $new_status );
	}

	/**
	 * Initialize connection with AmoCRM api, if not done already
	 */
	function maybe_api_init() {
		if ( $this->api ) {
			return;
		}

		require_once 'amocrm-api/AmoRestApi.php';
		$this->api = new \AmoRestApi( $this->subdomain, $this->login, $this->key );
	}

	/**
	 * Add contact based on the passed info to the AmoCRM
	 *
	 * @param $full_name
	 * @param $phone
	 * @param $email
	 */
	private function add_contact( $full_name, $phone, $email ) {
		$this->maybe_api_init();

		//Get fields id in AmoCRM system
		$email_custom_field_id = $this->api->getCustomFieldID( 'EMAIL' );
		$phone_custom_field_id = $this->api->getCustomFieldID( 'PHONE' );

		$contacts['add'][] = array(
			'name'          => $full_name,
			'custom_fields' => array(
				array(
					'id'     => $email_custom_field_id,
					'values' => array(
						array(
							'value' => $email,
							'enum'  => 'WORK'
						)
					)
				),
				array(
					'id'     => $phone_custom_field_id,
					'values' => array(
						array(
							'value' => $phone,
							'enum'  => 'OTHER'
						)
					)
				),
			)
		);

		return $this->api->setContacts( $contacts );
	}

	/**
	 * Add new lead in CRM
	 * todo ability to attach contact to lead, that was created earlier
	 * todo passing in lead more information, as lead's custom fields, product price and so
	 *
	 * @param $full_name
	 * @param $product_id
	 */
	private function add_lead( $full_name, $order_id ) {
		$this->maybe_api_init();

		$lead_title         = 'Заказ №' . $order_id . ' от ' . $full_name;
		$new_lead_status_id = $this->api->getLeadStatusID( $this->leads_statuses_titles['new'] );

		$request['add'][] = array(
			'name'      => $lead_title,
			'status_id' => $new_lead_status_id,
		);

		$added_leads = $this->api->setLeads( $request );

		return $added_leads;

		if ( $added_leads && is_array( $added_leads ) ) {
			foreach ( $added_leads as $lead_info ) {
				$user         = wp_get_current_user();
				$lead_details = array(
					'order_id'         => $order_id,
					'lead_id'            => $lead_info['id'],
					'lead_last_modified' => $lead_info['last_modified'],
				);
				update_user_meta( $user->ID, 'amocrm_lead_details', $lead_details );
			}
		}
	}

	/**
	 * Update lead status if it meet requirements
	 *
	 * @param $order_id
	 * @param $old_status
	 * @param $new_status
	 */
	private function update_lead( $order_id, $old_status, $new_status ) {
		$order     = wc_get_order( $order_id );
		$lead_info = get_user_meta( $order->get_user_id(), 'amocrm_lead_details', true );

		if ( 'completed' == $new_status && $lead_info ) {
			$order_items = $order->get_items();
			if ( $order_items && is_array( $order_items ) ) {
				foreach ( $order_items as $item ) {
					if ( $item['product_id'] == $lead_info['product_id'] ) {
						$amo_integration = \Project_Name\Core\Includes\AmoCRM_Integration::instance();
						$amo_integration->maybe_api_init();

						$lead_id                  = $lead_info['lead_id'];
						$completed_lead_status_id = $amo_integration->api->getLeadStatusID( $this->leads_statuses_titles['completed'] );

						// 10 added in order to have a bit bigger timestamp, that needed for success update
						// todo use here GMT +3h timestamp, value will be bigger than last_modified, and more realistic
						$lead_last_modified = $lead_info['lead_last_modified'] + 10;


						$request['update'][] = array(
							'id'            => $lead_id,
							'last_modified' => $lead_last_modified,
							'status_id'     => $completed_lead_status_id,
						);
						$amo_integration->api->setLeads( $request );

						delete_user_meta( $order->get_user_id(), 'amocrm_lead_details' );
					}
				}
			}
		}
	}

}

try {
	new WooAmoConnector();
} catch ( \Exception $exception ) {
	error_log( "Caught " . $exception );
}
