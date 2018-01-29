<?php
/*
Plugin Name: WooAmoConnector
Version: 0.7
Plugin URI: https://github.com/uptimizt/wooamoconnector
Description: AmoCRM & WooCommerce - интеграция. Создание сделок и контактов с сайта в CRM
Author: WPCraft
Author URI: http://wpcraft.ru/?utm_source=wpplugin&utm_medium=plugin-link&utm_campaign=WooAmoConnector
*/



function wooac_test(){
  $data = wooac_request('/api/v2/leads?limit_rows=33');

  echo '<pre>';
  var_dump($data);
  echo '</pre>';
}

/**
* Request wrapper for API AmoCRM
*/
function wooac_request($ep = '', $method = 'GET', $data = array())
{
  try {

    $cookies = get_transient('wooac_cookies');

    if(empty($cookies) and $ep != '/private/api/auth.php'){
      wooac_request('/private/api/auth.php', 'POST');
      $cookies = get_transient('wooac_cookies');
    }

    $url = 'https://' . get_option('wac_subdomain') . '.amocrm.ru' . $ep;

    $url = add_query_arg('type', 'json', $url);

    $login_data = array(
      'USER_LOGIN' => get_option('wac_login'),
      'USER_HASH' => get_option('wac_key')
    );

    $args = array(
      'method' => $method,
      'timeout' => '30',
    );


    if( empty($cookies)){
      $args['body'] = $login_data;
    } else {
      $args['body'] = $login_data;
      $args['cookies'] = $cookies;
    }

    do_action('logger_u7', $args);

    $response = wp_remote_request( $url, $args );

    if(wp_remote_retrieve_response_code($response) != 200){
      throw new Exception('Ошибка ответа от сервера. Код: ' . wp_remote_retrieve_response_code($response));
    }

    if(isset($response["cookies"]) and $ep == '/private/api/auth.php'){
      set_transient('wooac_cookies', $response["cookies"], MINUTE_IN_SECONDS * 14);
      // do_action('logger_u7', ['set_transient-wooac_cookies'], $response["cookies"]);
      return true;
    }

    $data = wp_remote_retrieve_body($response);
    $data = json_decode($data, true);

    return $data;

  } catch (Exception $e) {
    return new WP_Error( 'api_request_error', $e->getMessage() );
  }

}

require_once 'inc/class-settings-api.php';

class WooAC {

  private $login;
  private $key;
  private $subdomain;

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

  function __construct() {
    add_action( 'woocommerce_add_to_cart', array( $this, 'hook_on_add_product' ), 10, 6 );

    $this->login = get_option('wac_login');
    $this->key = get_option('wac_key');
    $this->subdomain = get_option('wac_subdomain');

    add_filter( 'cron_schedules', array($this, 'add_schedule') );
    add_action('init', [$this, 'init_cron']);

    add_action('wooamoconnector_cron_worker', [$this, 'send_walker']);

    add_action( 'woocommerce_order_status_changed', array( $this, 'hook_on_order_status_change' ), 10, 3 );

    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('wac_sync', [$this, 'send_walker_manual_start']);

    add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), array($this, 'plugin_add_settings_link') );

  }


  function plugin_add_settings_link( $links ) {
      $settings_link = '<a href="options-general.php?page=wac-settings">Настройки</a>';
      array_push( $links, $settings_link );
      return $links;
  }

  /**
  * Ручная передача заказов - обертка для обходчика с выводом результатов
  */
  function send_walker_manual_start(){
    $data = $this->send_walker();

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

    $data = [
      'phone' => $order->get_billing_phone(),
      'email' => $order->get_billing_email(),
      'full_name' => $this->get_full_name($order_id),
    ];

    $user_id = $order->get_user_id();
    if( empty($user_id) ){
      $check_user = $this->add_contact( $data['full_name'], $data['phone'], $data['email'] );
    } else {
      //@todo: update current user
    }

    $check_order = $this->add_lead( $data['full_name'], $order_id );
    if(isset($check_order[0]['id'])){
      update_post_meta($order_id, 'wac_id', $check_order[0]['id']);
      return true;
    }

    return false;
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
      ?>
    </div>
    <?php

    wooac_test();

  }



  function send_walker(){

    set_transient('wac_last_start', date('Y-m-d H:i:s'), DAY_IN_SECONDS);

    $args = array(
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wooamoc_send_timestamp',
      'meta_compare' => 'NOT EXISTS',
    );

    if(empty(get_option('wooac_orders_send_from'))){
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

    $lead_title         = 'Тест Заказ №' . $order_id . ' от ' . $full_name;
    $new_lead_status_id = $this->api->getLeadStatusID( $this->leads_statuses_titles['new'] );

    $request['add'][] = array(
      'name'      => $lead_title,
      'status_id' => $new_lead_status_id,
    );

    $added_leads = $this->api->setLeads( $request );


    if ( $added_leads && is_array( $added_leads ) ) {
      foreach ( $added_leads as $lead_info ) {
        // $user         = wp_get_current_user();
        $lead_details = array(
          'order_id'         => $order_id,
          'lead_id'            => $lead_info['id'],
          'lead_last_modified' => $lead_info['last_modified'],
        );
        update_post_meta( $order_id, 'amocrm_lead_details', $lead_details );
      }
    }

    return $added_leads;

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
    $lead_info = get_post_meta( $order_id, 'amocrm_lead_details', true );

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

          }
        }
      }
    }
  }

}

try {
  new WooAC();
} catch ( \Exception $exception ) {
  error_log( "Caught " . $exception );
}
