<?php
/*
Plugin Name: WooAmoConnector
Plugin URI: https://github.com/uptimizt/wooamoconnector
Description: AmoCRM & WooCommerce - интеграция. Создание сделок и контактов с сайта в CRM
Author: WPCraft
Author URI: http://wpcraft.ru/?utm_source=wpplugin&utm_medium=plugin-link&utm_campaign=WooAmoConnector
Text Domain: wooac
Version: 1.4
*/

require_once 'inc/class-settings-api.php';
require_once 'inc/class-amocrm-api.php';
require_once 'inc/class-root-wooac.php';

class WooAmoConnector {

  public static function init(){

    add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), array(__CLASS__, 'add_settings_link') );

    add_action('plugins_loaded', array(__CLASS__, 'plugin_init'));

  }

  public static function plugin_init() {
      $plugin_rel_path = basename( dirname( __FILE__ ) ) . '/languages';
      load_plugin_textdomain( 'wooac', false, $plugin_rel_path );
  }


  //Add settings link
  public static function add_settings_link( $links ) {
      $settings_link = '<a href="options-general.php?page=wac-settings">Настройки</a>';
      $xt_link = '<a href="https://wpcraft.ru/product/wooamoconnector-xt/" target="_blank">Расширенная версия</a>';
      array_unshift($links, $xt_link);
      array_unshift($links, $settings_link);
      return $links;
  }

}

WooAmoConnector::init();
