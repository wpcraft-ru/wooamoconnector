<?php

/**
 * AmoCRM API Wrapper
 */
class WooAC_API_Wrapper
{
  protected $curl;

  protected $url_domain;

  function __construct()
  {
    $this->url_domain = 'https://' . get_option('wac_subdomain') . '.amocrm.ru';

    $login_data = array(
      'USER_LOGIN' => get_option('wac_login'),
      'USER_HASH' => get_option('wac_key')
    );

    $auth = $this->curlRequest('/private/api/auth.php?type=json', 'POST', $login_data);
  }

  function curlRequest($ep, $method = 'GET', $parameters = null, $headers = null, $timeout = 30)
  {
    try {

      $url = $this->url_domain . $ep;

      if ($method == 'GET' && is_null($parameters) == false) {
          $url = add_query_arg($parameters, $url);
      }

      if($method == 'GET'){
        $url = add_query_arg('type', 'json', $url);
      }

      // Get curl handler or initiate it
      if ( ! $this->curl ) {
        $this->curl = curl_init();
      }

      //Set general arguments
      curl_setopt($this->curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
      curl_setopt($this->curl, CURLOPT_URL, $url);
      curl_setopt($this->curl, CURLOPT_FAILONERROR, false);
      curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 0);
      curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, 0);
      curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($this->curl, CURLOPT_TIMEOUT, $timeout);
      curl_setopt($this->curl, CURLOPT_HEADER, false);
      curl_setopt($this->curl, CURLOPT_COOKIEFILE, '-');
      curl_setopt($this->curl, CURLOPT_COOKIEJAR, '-');
      curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST,$method);

      if (is_null($headers) === false) {
          curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
      } else {
        $headers = array('Content-Type: application/json');
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
      }

      if ($method == 'POST') {
        curl_setopt($this->curl, CURLOPT_POST, true);

        //Encode parameters if them already not encoded in json
        $parameters = json_encode($parameters);
        // $parameters = http_build_query($parameters);
        // $parameters = json_encode($parameters, JSON_PRETTY_PRINT);
        // $parameters = json_encode($parameters, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT);
        // $parameters = json_encode($parameters, JSON_FORCE_OBJECT);

        // do_action('logger_u7', ['rest', $url, $parameters, curl_getinfo($this->curl, CURLINFO_COOKIELIST)]);

        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $parameters);
      }

      $response = curl_exec($this->curl);
      $statusCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

      // do_action('logger_u7', ['$response', $response]);

      $errno = curl_errno($this->curl);
      $error = curl_error($this->curl);


      if ($errno) {
        throw new Exception($error, $errno);
      }

      $result = json_decode($response, true);

      if ($statusCode >= 400) {
        if(empty($result['message']){
          throw new Exception(print_r($result, true), $statusCode);
        } else {
          throw new Exception($result['message'], $statusCode);
        }
      }

      return $result;
    } catch (Exception $e) {
      return new WP_Error( 'api_request_error', $e->getMessage() );
    }
  }

  /**
   * Do some actions when instance destroyed
   */
  function __destruct() {
    //Close curl session
    curl_close($this->curl);
  }
}

//Helper function wrapper for API
function wooac_request($ep_url = '', $method = 'GET', $args = null, $headers = null, $timeout = 30){
  $api = new WooAC_API_Wrapper();
  $response = $api->curlRequest($ep_url, $method, $args, $headers, $timeout);
  return $response;
}
