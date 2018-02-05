<?php


//Test remote request
function wooac_test(){
  $args['add'] = array(
    array(
      'name' => "Test 1 WooAC - Test lead",

    )
  );

  $response = wooac_request('/api/v2/leads');
  // $response = wooac_request('/api/v2/leads', 'POST', $args);

  echo '<pre>';
  var_dump($response);
  echo '</pre>';
}






























/**
* Request wrapper for API AmoCRM
*
* @param $ep_url - endpoint url
*/
function wooac_request_v4($ep_url = '', $method = 'GET', $data = array())
{
  try {
    $url = 'https://' . get_option('wac_subdomain') . '.amocrm.ru' . $ep_url;

    $url = add_query_arg('type', 'json', $url);

    do_action('logger_u7', ['t4',$curl] );


    curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
    curl_setopt($curl, CURLOPT_USERAGENT,'amoCRM-API-client/1.0');
    curl_setopt($curl, CURLOPT_URL,$url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST,$method);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($curl, CURLOPT_HEADER,false);
    curl_setopt($curl, CURLOPT_COOKIEFILE, '-');
    curl_setopt($curl, CURLOPT_COOKIEJAR, '-');
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($curl, CURLOPT_POST, false);

    if($method == 'POST'){
      curl_setopt($curl, CURLOPT_POSTFIELDS,json_encode($login_data));
      curl_setopt($curl, CURLOPT_POST, true);
    }


    $code = curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $out = curl_exec($curl);

    curl_close($curl); #Завершаем сеанс cURL


    if($code != 200 and ! empty($code)){
      throw new Exception('Ошибка ответа от сервера. Код: ' . $code);
    }

    $data = $out;
    $data = json_decode($data, true);

    return $data;

  } catch (Exception $e) {
    return new WP_Error( 'api_request_error', $e->getMessage() );
  }

}

function wooac_request_v1($ep = '', $method = 'GET', $data = array())
{
  try {

    $cookies = get_transient('wooac_cookies');

    if(empty($cookies) and $ep != '/private/api/auth.php'){
      wooac_request('/private/api/auth.php', 'POST');
      $cookies = get_transient('wooac_cookies');
    }

    // delete_transient('wooac_cookies');
    do_action('logger_u7', ['t1', $cookies]);

    $url = 'https://' . get_option('wac_subdomain') . '.amocrm.ru' . $ep;

    $url = add_query_arg('type', 'json', $url);

    $login_data = array(
      'USER_LOGIN' => get_option('wac_login'),
      'USER_HASH' => get_option('wac_key')
    );

    $args = array(
      'method' => $method,
      'timeout' => '30',
      'sslverify' => false,
      'httpversion' => '1.1',
    );


    if( empty($cookies)){
      $args['body'] = $login_data;
      $args['cookies'] = $cookies;

    } else {
      $args['cookies'] = $cookies;
    }

    do_action('logger_u7', ['t2', $url, $args]);

    $response = wp_remote_request( $url, $args );


    if(wp_remote_retrieve_response_code($response) != 200){
      throw new Exception('Ошибка ответа от сервера. Код: ' . wp_remote_retrieve_response_code($response));
    }

    do_action('logger_u7', ['t4',$out] );

    if(isset($response["cookies"])){
      $cookies = array();
      foreach($response["cookies"] as $key => $value){
        if($value->name == "session_id"){
          $cookies[] = $value;
        }
        if($value->name == "user_lang"){
          $cookies[] = $value;
        }
      }
      set_transient('wooac_cookies', $cookies, MINUTE_IN_SECONDS * 14);

      // return true;
    }

    $data = wp_remote_retrieve_body($response);
    $data = json_decode($data, true);
    // $data = '';

    return $data;

  } catch (Exception $e) {
    return new WP_Error( 'api_request_error', $e->getMessage() );
  }

}

function wooac_request_v2($ep, $method = 'GET', $parameters = null, $headers = null, $timeout = 30)
{

  $url = 'https://' . get_option('wac_subdomain') . '.amocrm.ru' . $ep;

  $url = add_query_arg('type', 'json', $url);

    // if ($method == 'GET' && is_null($parameters) == false) {
    //     $url .= "?$parameters";
    // }

  // Get curl handler or initiate it
  $curl = curl_init();

  //Set general arguments
  curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
  curl_setopt($curl, CURLOPT_URL, $url);
  curl_setopt($curl, CURLOPT_FAILONERROR, false);
  curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($curl, CURLOPT_HEADER, false);
  curl_setopt($curl, CURLOPT_COOKIEFILE, '-');
  curl_setopt($curl, CURLOPT_COOKIEJAR, '-');

  // Reset some arguments, in order to avoid use some from previous request
  curl_setopt($curl, CURLOPT_POST, false);
  curl_setopt($curl, CURLOPT_HTTPHEADER, false);

  if (is_null($headers) === false) {
      curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  }

  if ($method == 'POST' && is_null($parameters) === false) {
    curl_setopt($curl, CURLOPT_POST, true);

      //Encode parameters if them already not encoded in json
      // if ( ! $this->isJson( $parameters ) ) {
      //   $parameters = http_build_query( $parameters );
      // }

      curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
  }

  $response = curl_exec($curl);
  $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

  $errno = curl_errno($curl);
  $error = curl_error($curl);

  if ($errno) {
      throw new Exception($error, $errno);
  }

  $result = json_decode($response, true);

  if ($statusCode >= 400) {
      throw new Exception($result['message'], $statusCode);
  }

  return isset($result['response']) && count($result['response']) == 0 ? true : $result['response'];
}



/**
* Request wrapper for API AmoCRM
*
* @param $ep_url - endpoint url
*/
function wooac_request_v3($ep_url = '', $method = 'GET', $data = array())
{
  require_once '../amocrm-api/AmoRestApi.php';
  $api = new \AmoRestApi( get_option('wac_subdomain'), get_option('wac_login'), get_option('wac_key') );

  $url = 'https://' . get_option('wac_subdomain') . '.amocrm.ru' . $ep_url;

  $headers = '';
  return $api->curlRequest($url, $method);

}
