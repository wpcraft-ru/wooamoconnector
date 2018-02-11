<?php
/**
* Main class worker for sync data AmoCRM & WooCommerce
*/

class WooAC
{

  function __construct()
  {
    add_action( 'woocommerce_add_to_cart', array( $this, 'hook_on_add_product' ), 10, 6 );

    add_filter( 'cron_schedules', array($this, 'add_schedule') );
    add_action( 'init', [$this, 'init_cron']);

    add_action( 'wooamoconnector_cron_worker', [$this, 'walker']);

    add_action( 'woocommerce_order_status_changed', array( $this, 'hook_on_order_status_change' ), 10, 3 );

    add_action( 'admin_menu', [$this, 'add_admin_menu']);
    add_action( 'wac_sync', [$this, 'send_walker_manual_start']);
  }

  //Main walker
  function walker(){

    set_transient('wac_last_start', date('Y-m-d H:i:s'), DAY_IN_SECONDS);

    $args = array(
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wooamoc_send_timestamp',
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
      $check = $this->send_order($order->ID);

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
  function send_walker_manual_start(){
    $data = $this->walker();

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
  function send_order($order_id){
    $order = wc_get_order($order_id);

    $args['add'] = array(
      array(
        'name' => "Заказ - " . $order_id,
        'sale' => $order->get_total(),
      )
    );

    $args = apply_filters('wooac_add_lead', $args, $order_id);

    $response = wooac_request('/api/v2/leads', 'POST', $args);

    if(empty($response["_embedded"]["items"][0]["id"])){
      return false;
    } else {
      $lead_id = $response["_embedded"]["items"][0]["id"];
      update_post_meta($order_id, 'wac_id', $lead_id);

      do_action('wooac_added_lead', $lead_id, $order_id);

      return true;
    }
  }

  function get_full_name($order_id){
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

  function add_admin_menu(){
    add_submenu_page(
      'tools.php',
      'AmoCRM - инструменты',
      'AmoCRM',
      'manage_options',
      'wooamoconnector-tools',
      [$this, 'display_tools']
    );
  }

  function display_tools(){
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


  function add_schedule( $schedules ) {
    $time = get_option('wac_sync_time', 60);
    $schedules['wooamoconnector_cron'] = array( 'interval' => $time, 'display' => 'WooAmoConnector Cron Worker' );
    return $schedules;
  }

  function init_cron(){
    if ( ! wp_next_scheduled( 'wooamoconnector_cron_worker' ) ) {
      wp_schedule_event( time(), 'wooamoconnector_cron', 'wooamoconnector_cron_worker' );
    }
  }

  function hook_on_order_status_change( $order_id, $old_status, $new_status ) {
    //@TODO: Update status
  }

}

new WooAC;
