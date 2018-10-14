<?php
/**
* Main class worker for sync data AmoCRM & WooCommerce
*/

class WooAmoConnector_Walker
{

  public static function init()
  {
    add_filter( 'cron_schedules', array(__CLASS__, 'add_schedule') );
    add_action( 'init', array(__CLASS__, 'init_cron'));

    add_action( 'wooamoconnector_cron_worker', array(__CLASS__, 'walker'));

    add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'hook_on_order_status_change' ), 10, 3 );

    add_action( 'admin_menu', array(__CLASS__, 'add_admin_menu'));
    add_action( 'wac_sync', array(__CLASS__, 'send_walker_manual_start'));

    add_action( 'wooac_added_lead', array(__CLASS__, 'add_comment'), 10, 2);

    add_filter('wooac_notes_add', array(__CLASS__, 'add_products'), 10, 2);
  }

  /**
  * Добавляем позиции заказа в комментарий к лиду
  */
  public static function add_products($args, $order_id){

    $order = wc_get_order($order_id);

    $note_products_title = PHP_EOL . '#' . PHP_EOL . '# Продукты' . PHP_EOL;
    $note_products = '';

    foreach( $order->get_items() as $item_id => $item_product ){

        $text_row = sprintf('- %s, кол-во: %s, сумма: %s',
                      $item_product->get_name(),
                      $item_product->get_quantity(),
                      $item_product->get_total()
                    );

        $note_products .= PHP_EOL . $text_row;

        //Get the WC_Product object
        $product = $item_product->get_product();

        //Get the product SKU (using WC_Product method)
        if($sku = $product->get_sku()){
          $note_products .= sprintf(' (арт. %s)', $sku);
        }
    }

    $args['add'][0]['text'] .= $note_products_title . $note_products;

    return $args;
  }

  /**
   * Add comment to lead
   */
  public static function add_comment($lead_id, $order_id){

    $order = wc_get_order($order_id);

    if(method_exists('WC_Order', 'get_edit_order_url')){
      $order_url = $order->get_edit_order_url();
    } else {
      $order_url = get_edit_post_link($order_id, '');
    }

    $text = sprintf(
      'Заказ №%s, ссылка: %s',
      $order_id,
      $order_url
    );

    if( $order->get_formatted_billing_full_name() ){
      $text .= PHP_EOL . 'Клиент: ' . $order->get_formatted_billing_full_name();
    }

    if($order->get_billing_company()){
      $text .= PHP_EOL . 'Компания: ' . $order->get_billing_company();
    }

    if($order->get_billing_phone()){
      $text .= PHP_EOL . 'Телефон: ' . $order->get_billing_phone();
    }

    if($order->get_billing_email()){
      $text .= PHP_EOL . 'email: ' . $order->get_billing_email();
    }

    if($order->get_formatted_billing_address()){
      $text .= PHP_EOL . 'Адрес клиента: ' . $order->get_formatted_billing_address();
    }

    if($order->get_payment_method()){
      $text .= PHP_EOL . 'Метод оплаты: ' . $order->get_payment_method();
    }

    if($order->get_customer_note()){
      $text .= PHP_EOL . '#' . PHP_EOL . '# Примечание клиента:' . PHP_EOL . $order->get_customer_note();
    }

    if($order->has_shipping_address()){
      $text .= PHP_EOL . '# Указаны данные доставки...';

      if($order->get_formatted_shipping_address()){
        $text .= PHP_EOL . 'Адрес доставки: ' . $order->get_formatted_shipping_address();
      }

      if($order->get_formatted_shipping_full_name()){
        $text .= PHP_EOL . 'Имя клиента для доставки: ' . $order->get_formatted_shipping_full_name();
      }

    }

    $args['add'] = array(
      array(
        'element_id' => $lead_id,
        'element_type' => 2,
        'note_type' => 'COMMON',
        'text' => $text,
      )
    );

    $args = apply_filters('wooac_notes_add', $args, $order_id);

    $response = wooac_request('/api/v2/notes', 'POST', $args);

  }

  /**
   * Get client's name by order_id
   */
  public static function get_data_order_name( $order_id ) {
		$order = wc_get_order( $order_id );
		$name  = $order->get_billing_company();

		if ( empty( $name ) ) {
			$name = $order->get_billing_last_name();
			if ( ! empty( $order->get_billing_first_name() ) ) {
				$name .= ' ' . $order->get_billing_first_name();
			}
		}

		return $name;
	}


  //Main walker
  public static function walker(){

    set_transient('wac_last_start', date('Y-m-d H:i:s'), DAY_IN_SECONDS);

    $args = array(
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wac_id',
      'meta_compare' => 'NOT EXISTS',
    );

    $date_from = get_option('wooac_orders_send_from');
    if(empty($date_from)){
      $date_from = '2 day ago';
    } else {
      $date_from = get_option('wooms_orders_send_from');
    }

    $args['date_query'] = array(
      'after' => $date_from
    );

    $orders = get_posts($args);

    $result_list = [];
    foreach ($orders as $key => $order) {
      $check = self::send_order($order->ID);

      if($check){
        update_post_meta($order->ID, 'wooamoc_send_timestamp', date("Y-m-d H:i:s"));
        $result_list[] = $order->ID;
      }
    }

    return $result_list;
  }

  /**
  * Ручная передача заказов - обертка для обходчика с выводом результатов
  */
  public static function send_walker_manual_start(){
    $data = self::walker();

    if(is_array($data)){
      foreach ($data as $key => $value) {
        printf('<p>передан заказ №%s</p>', $value);
      }
    } else {
      echo "<p>Нет заказов для передачи</p>";
    }
  }

  /*
  * Отправка данных через API AmoCRM
  */
  public static function send_order($order_id){
    $order = wc_get_order($order_id);

    $args = array();

    $args['add'] = array(
      array(
        'name' => "Заказ - " . $order_id,
        'sale' => $order->get_total(),
      )
    );

    $args = apply_filters('wooac_add_lead', $args, $order_id);

    $response = wooac_request('/api/v2/leads', 'POST', $args);


    if(is_wp_error($response)){

      echo '<pre>' . $response->get_error_message() . '</pre>';

    } elseif(empty($response["_embedded"]["items"][0]["id"])){
      return false;
    } else {
      $lead_id = $response["_embedded"]["items"][0]["id"];
      update_post_meta($order_id, 'wac_id', $lead_id);

      do_action('wooac_added_lead', $lead_id, $order_id);

      return true;
    }
  }

  public static function get_full_name($order_id){
    $order = wc_get_order($order_id);

    $full_name = '';
    $full_name_data = [
      'first_name' => $order->get_billing_first_name(),
      'last_name' => $order->get_billing_last_name(),
      'company' => $order->get_billing_company(),
    ];

    if( ! empty($full_name_data['first_name']) and ! empty($full_name_data['last_name']) ){
      $full_name .= $full_name_data['first_name'] . ' ' . $full_name_data['last_name'];
    } elseif( ! empty($full_name_data['first_name']) ){
      $full_name .= $full_name_data['first_name'];
    } elseif (! empty($full_name_data['last_name'])) {
      $full_name .= $full_name_data['last_name'];
    } else {
      $full_name .= 'Клиент';
    }

    if( ! empty($full_name_data['company']) ){
      $full_name .= ', ' . $full_name_data['company'];
    }

    return $full_name;
  }

  public static function add_admin_menu(){
    add_submenu_page(
      'tools.php',
      'AmoCRM - инструменты',
      'AmoCRM',
      'manage_options',
      'wooamoconnector-tools',
      array(__CLASS__, 'display_tools')
    );
  }

  public static function display_tools(){
    $url1 = admin_url('tools.php?page=wooamoconnector-tools');
    $url2 = add_query_arg('a', 'wac-sync', $url1);
    ?>
    <div class="wooamoconnector-tools-wrapper">
      <h1>AmoCRM</h1>
      <?php
        if(empty($_GET['a'])){
          echo '<p>Ручная отправка заказов в AmoCRM</p>';

          $time_stamp = get_transient('wac_last_start');
          printf('<p>Отметка о последней синхронизации: %s</p>', empty($time_stamp) ? 'отсутствует' : $time_stamp);
          printf('<a href="%s" class="btn button">Выполнить</a>', $url2);
        } else {
          echo '<hr>';
          printf('<a href="%s" class="btn button">Вернуться</a>', $url1);
          echo '<hr>';
          do_action('wac_sync');
        }
        do_action('wooac_test');
      ?>
    </div>
    <?php
  }


  public static function add_schedule( $schedules ) {
    $time = get_option('wac_sync_time', 60);
    $schedules['wooamoconnector_cron'] = array( 'interval' => $time, 'display' => 'WooAmoConnector Cron Worker' );
    return $schedules;
  }

  public static function init_cron(){
    if ( ! wp_next_scheduled( 'wooamoconnector_cron_worker' ) ) {
      wp_schedule_event( time(), 'wooamoconnector_cron', 'wooamoconnector_cron_worker' );
    }
  }

  public static function hook_on_order_status_change( $order_id, $old_status, $new_status ) {
    //@TODO: Update status
  }

}

WooAmoConnector_Walker::init();
