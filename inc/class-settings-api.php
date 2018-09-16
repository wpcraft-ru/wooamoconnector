<?php


class WooAmoConnector_Settings {
  function __construct(){

    add_action('admin_menu', function () {
        add_options_page(
          $page_title = 'AmoCRM',
          $menu_title = "AmoCRM",
          $capability = 'manage_options',
          $menu_slug = 'wac-settings',
          $function = array($this, 'display_settings')
        );
    });

    add_action( 'admin_init', array($this, 'settings_init'), $priority = 10, $accepted_args = 1 );
  }



  function settings_init(){
    add_settings_section(
      'wac_section_login',
      'Доступ',
      null,
      'wac-settings'
    );

    register_setting('wac-settings', 'wac_login');
    add_settings_field(
      $id = 'wac_login',
      $title = 'Логин (email администратора)',
      $callback = [$this, 'display_wac_login'],
      $page = 'wac-settings',
      $section = 'wac_section_login'
    );

    register_setting('wac-settings', 'wac_key');
    add_settings_field(
      $id = 'wac_key',
      $title = 'Ключ доступа API',
      $callback = [$this, 'display_wac_key'],
      $page = 'wac-settings',
      $section = 'wac_section_login'
    );

    register_setting('wac-settings', 'wac_subdomain');
    add_settings_field(
      $id = 'wac_subdomain',
      $title = 'Поддомен',
      $callback = [$this, 'display_wac_subdomain'],
      $page = 'wac-settings',
      $section = 'wac_section_login'
    );


    add_settings_section(
      'woomss_section_cron',
      'Расписание синхронизации',
      null,
      'wac-settings'
    );

    register_setting('wac-settings', 'wac_sync_disable');
    add_settings_field(
      $id = 'wac_sync_disable',
      $title = 'Отключить синхронизацию по расписанию',
      $callback = [$this, 'display_wac_sync_disable'],
      $page = 'wac-settings',
      $section = 'woomss_section_cron'
    );
    register_setting('wac-settings', 'wac_sync_time');
    add_settings_field(
      $id = 'wac_sync_time',
      $title = 'Таймер синхронизации в секундах',
      $callback = [$this, 'display_wac_sync_time'],
      $page = 'wac-settings',
      $section = 'woomss_section_cron'
    );
  }

  function display_wac_sync_time(){
    $name ='wac_sync_time';
    printf('<input type="number" name="%s" value="%s"/>', $name, get_option($name, 60));
  }

  function display_wac_sync_disable(){
    $name = 'wac_sync_disable';
    printf('<input type="checkbox" name="%s" %s value="1"/>', $name, checked(1, get_option($name), false));
  }
  function display_wac_key(){
    $name ='wac_key';
    printf('<input type="password" name="%s" value="%s"/>', $name, get_option($name));
    ?>
    <p><small>Получить ключ доступа можно на странице настроек API AmoCRM</small></p>
    <?php
  }

  function display_wac_login(){
    $name ='wac_login';
    printf('<input type="text" name="%s" value="%s"/>', $name, get_option($name));
  }
  function display_wac_subdomain(){
    $name ='wac_subdomain';
    printf('<input type="text" name="%s" value="%s"/>', $name, get_option($name));
    printf('<p>%s</p>', 'Нужно указать поддомен без amocrm.ru');

  }

  function display_settings(){
    ?>

    <form method="POST" action="options.php">
      <h1>Настройки интеграции AmoCRM</h1>
      <?php
        settings_fields( 'wac-settings' );
        do_settings_sections( 'wac-settings' );
        submit_button();
      ?>
    </form>


    <?php
    printf('<p><a href="%s">Управление синхронизацией</a></p>', admin_url('tools.php?page=wooamoconnector-tools'));
    printf('<p><a href="%s" target="_blank">Расширенная версия с дополнительными возможностями</a></p>', "https://wpcraft.ru/product/wooac-expert/");
    printf('<p><a href="%s" target="_blank">Помощь и техническая поддержка</a></p>', "https://wpcraft.ru/contacts/");
  }
}
new WooAmoConnector_Settings;
