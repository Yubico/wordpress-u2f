<?php

class U2FVal {
  private $endpoint;
  private $username;
  private $password;

  public function __construct($endpoint, $clientId, $clientPassword) {
    if(substr($endpoint, -1) != '/') {
      $endpoint .= '/';
    }
    $this->endpoint = $endpoint;
    $this->username = $clientId;
    $this->password = $clientPassword;
  }

  private static function filtered($path, $filter) {
    if($filter !== NULL) {
      $path .= '?filter=' . $filter;
    }
    return $path;
  }

  private static function add_props($data, $props) {
    if(is_string($data)) {
      $data = json_decode($data, true);
    }
    if($props !== NULL) {
      $data['properties'] = $props;
    }
    return $data;
  }

  private function curl_begin($path) {
    $ch = curl_init($this->endpoint . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    return $ch;
  }

  private function curl_complete($ch) {
    $res = curl_exec($ch);
    if($res === false) {
      curl_close($ch);
      return array('errorCode' => -1, 'errorMessage' => 'Server unreachable');
    }
    $res = json_decode($res, true);

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($status >= 400) {
      $res = array('errorCode' => $status);
      if($status == 401) {
        $res['errorMessage'] = 'Invalid credentials';
      } else if($status == 404) {
        $res['errorMessage'] = 'Resource not found';
      } else {
        $res['errorMessage'] = 'Server responded: ' . $status;
      }
    }
    return $res;
  }

  private function curl_send($path, $data=null) {
    if(!function_exists('curl_init')) {
      return array('errorCode' => -1, 'errorMessage' => 'cURL not installed');
    }

    $ch = $this->curl_begin($path);
    if($data) {
      if(!is_string($data)) {
        $data = json_encode($data);
      }
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data))
      );
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    return $this->curl_complete($ch);
  }

  private function curl_delete($path) {
    if(!function_exists('curl_init')) {
      return array('errorCode' => -1, 'errorMessage' => 'cURL not installed');
    }

    $ch = $this->curl_begin($path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    return $this->curl_complete($ch);
  }

  public function test_connection() {
    $resp = $this->curl_send('');
    if(!is_error($resp) && ($resp === NULL || !isset($resp['trustedFacets']))) {
      $resp = array(
        'errorCode' => -1,
        'errorMessage' => 'Invalid response from server'
      );
    }
    if(is_error($resp)) {
      return $resp;
    }

    return true;
  }

  public function list_devices($username, $filter=NULL) {
    return $this->curl_send(self::filtered($username . '/', $filter));
  }

  public function register_begin($username) {
    $resp = $this->curl_send($username . '/register');
    if(!is_error($resp)) {
      return json_encode($resp);
    }
    return $resp;
  }

  public function register_complete($username, $registerResponse, $properties=NULL, $filter=NULL) {
    $path = self::filtered($username . '/register', $filter);
    $registerData = array('registerResponse' => json_decode($registerResponse, true));
    return $this->curl_send($path, self::add_props($registerData, $properties));
  }

  public function unregister($username, $handle) {
    return $this->curl_delete($username . "/" . $handle);
  }

  public function auth_begin($username) {
    $resp = $this->curl_send($username . '/authenticate');
    if(!is_error($resp)) {
      return json_encode($resp);
    }
    return $resp;
  }

  public function auth_complete($username, $authenticateResponse, $properties=NULL, $filter=NULL) {
    $path = self::filtered($username . '/authenticate', $filter);
    $authData = array('authenticateResponse' => json_decode($authenticateResponse, true));
    return $this->curl_send($path, self::add_props($authData, $properties));
  }
}

function has_devices($authData) {
  if(is_string($authData)) {
    $authData = json_decode($authData, true);
  }
  return sizeof($authData['authenticateRequests']) > 0;
}

function is_error($data) {
  if(is_string($data)) {
    $data = json_decode($data, true);
  }
  return isset($data['errorCode']);
}

?>
