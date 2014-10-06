<?php
/**
* Plugin Name: U2F Wordpress
* Plugin URI: http://mypluginuri.com/
* Description: Enables U2F authentication for Wordpress.
* Version: 0.1
* Author: Yubico
* Author URI: http://www.yubico.com
* License: GPL12
*/

function curl_begin($url) {
  $options = get_option('u2f_settings');

  $ch = curl_init($options['endpoint'].$url);
  //curl_setopt($ch, CURLOPT_FAILONERROR, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERPWD, $options['username'].':'.$options['password']);
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  return $ch;
}

function curl_send($url, $data=null) {
  $ch = curl_begin($url);
  if($data) {
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Content-Length: '.strlen($data))
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  }
  $res = curl_exec($ch);
  curl_close($ch);
  return $res;
}

function curl_delete($url) {
  $ch = curl_begin($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
  $res = curl_exec($ch);
  curl_close($ch);
  return $res;
}

function list_devices($username) {
  return curl_send($username."/");
}

function register_begin($username) {
  return curl_send($username."/register");
}

function register_complete($username, $registerData) {
  return curl_send($username."/register", $registerData);
}

function unregister($username, $handle) {
  return curl_delete($username."/".$handle);
}

function auth_begin($username) {
  return curl_send($username."/authenticate");
}

function auth_complete($username, $authData) {
  return curl_send($username."/authenticate", $authData);
}

function has_devices($authData) {
  return sizeof(json_decode($authData)->{'authenticateRequests'}) > 0;
}


if(!function_exists('_log')){
  function _log($message ) {
    if(WP_DEBUG === true ){
      if(is_array($message ) || is_object($message ) ){
        error_log(print_r($message, true ) );
      } else {
        error_log($message );
      }
    }
  }
}

/*
 * ADMIN MANAGEMENT
 */

function u2f_add_admin_menu() { 
  add_options_page('U2F', 'U2F', 'manage_options', 'u2f', 'u2f_options_page');
}

function sanitize_u2f_settings($settings) {
  if(substr($settings['endpoint'], -1) != '/') {
    $settings['endpoint'] .= '/';
  }

  return $settings;
}

function u2f_settings_init() { 
  register_setting('pluginPage', 'u2f_settings', 'sanitize_u2f_settings');

  add_settings_section(
    'u2f_pluginPage_section', 
    'U2F Validation Server API settings',
    'u2f_settings_section_callback', 
    'pluginPage'
  );

  add_settings_field(
    'endpoint', 
    'Endpoint',
    'u2f_val_endpoint_render', 
    'pluginPage', 
    'u2f_pluginPage_section' 
  );

  add_settings_field(
    'username', 
    'Client ID',
    'u2f_val_username_render', 
    'pluginPage', 
    'u2f_pluginPage_section' 
  );

  add_settings_field(
    'password', 
    'Client password',
    'u2f_val_password_render', 
    'pluginPage', 
    'u2f_pluginPage_section' 
  );
}


function u2f_val_endpoint_render() { 
  $options = get_option('u2f_settings');
?>
  <input type='text' name='u2f_settings[endpoint]' value='<?php echo $options['endpoint']; ?>' class="regular-text code">
<?php
}


function u2f_val_username_render() { 
  $options = get_option('u2f_settings');
?>
  <input type='text' name='u2f_settings[username]' value='<?php echo $options['username']; ?>' class="regular-text">
  <?php
}


function u2f_val_password_render() { 
  $options = get_option('u2f_settings');
  ?>
  <input type='password' name='u2f_settings[password]' value='<?php echo $options['password']; ?>' class="regular-text">
  <?php
}


function u2f_settings_section_callback() { 
  echo 'The settings below are used to connect to and authenticate against the U2F validation server.';
}


function u2f_options_page() { 
  ?>
  <div class="wrap">
  <h2>U2F Settings</h2>
  <form action='options.php' method='post'>
  
  <?php
  settings_fields('pluginPage');
  do_settings_sections('pluginPage');
  submit_button();
  ?>
  
  </form>
  </div>
  <?php
}

add_action('admin_menu', 'u2f_add_admin_menu');
add_action('admin_init', 'u2f_settings_init');

/*
 * USER MANAGEMENT
 */

function u2f_profile_fields($user) {
  $options = get_option('u2f_settings');
  if(empty($options)) return;

  $devices = json_decode(list_devices($user->ID));
  ?>
  <h3>U2F Devices</h3>
  <table class="form-table">
    <tr>
      <th>Registered Devices</th>
      <td>
        <table>
        <tr><th>Device</th><th>Delete</th></tr>
        <?php foreach($devices as $device): ?>
        <tr>
        <td>
          <label for="u2f_unregister_<?php echo $device->{'handle'}; ?>">
            <?php echo $device->{'handle'}; ?>
          </label>
        </td>
        <td>
          <input type="checkbox" name="u2f_unregister[]" id="u2f_unregister_<?php echo $device->{'handle'}; ?>" value="<?php echo $device->{'handle'}; ?>">
        </td>
        </tr>
        <?php endforeach; ?>
        </table>
        <br />
        <input type="hidden" name="u2f_register_response" id="u2f_register_response" />
        <a id="u2f_register" href="#" class="button">Register a new U2F Device</a>
        <div id="u2f_touch_notice" style="display: none;">
          Touch the flashing button on your U2F device now.
        </p>
      </td>
    </tr>
  </table>
  <script src="chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js"></script>
  <script>
    jQuery(document).ready(function($) {
      $('#u2f_register').click(function() {
        $('#u2f_register').hide();
        $('#u2f_touch_notice').addClass('updated').show();
        $.post(ajaxurl, {
          'action': 'u2f_register'
        }, function(resp) {
          resp = JSON.parse(resp);
          u2f.register(resp.registerRequests, resp.authenticateRequests, function(data) {
            $('#u2f_touch_notice').hide();
            $('#u2f_register_response').val(JSON.stringify(data));
            $('#submit').click();
          });
        });
        return false;
      });
    });
  </script>
  <?php
}

function u2f_profile_save($user_id) {
  if(!empty($_POST['u2f_register_response'])) {
    $clientResponse = json_decode(stripslashes($_POST['u2f_register_response']));
    $registerData = array(
      'registerResponse' => $clientResponse
    );

    $res = register_complete($user_id, json_encode($registerData));
    if(!isset($res->{'handle'})) {
      return new WP_Error('u2f_registration_failed', 'There was an error registering the U2F device!');
    }
  } else if(isset($_POST['u2f_unregister'])) {
    $handles = $_POST['u2f_unregister'];
    foreach($handles as $handle) {
      unregister($user_id, $handle);
    }
  }
}

function ajax_u2f_register_begin() {
  $user = wp_get_current_user();
  if(is_user_logged_in()) {
    echo register_begin($user->ID);
  }
  die();
}
add_action('wp_ajax_u2f_register', 'ajax_u2f_register_begin');

function validate_u2f_register(&$errors, $update=null, &$user=null) {
  if(isset($_POST['u2f_register_response'])) {
    $data = json_decode(stripslashes($_POST['u2f_register_response']));
    if(isset($data->{'errorCode'})) {
      $errors->add('u2f_error', "<strong>ERROR</strong>: There was an error registering your U2F device (error code ".$data->{'errorCode'}.").");
    }
  }   
}
add_action('user_profile_update_errors', 'validate_u2f_register');

add_action('profile_personal_options', 'u2f_profile_fields');
add_action('personal_options_update', 'u2f_profile_save');

/*
 * AUTHENTICATION
 */

$u2f_transient = null;

function u2f_login($user) {
  $options = get_option('u2f_settings');
  if(empty($options)) return $user;

  if(wp_check_password($_POST['pwd'], $user->data->user_pass, $user->ID) && !isset($_POST['u2f'])) {
    $authData = auth_begin($user->ID);
    global $u2f_transient;
    $u2f_transient = $authData;
    if(has_devices($authData)) {
      return new WP_Error('authentication_failed', 'Touch your U2F device now.');
    }
  } else if(isset($_POST['u2f'])) {
    $u2f = stripslashes($_POST['u2f']); //WordPress adds slashes, because it is insane.
    $clientResponse = json_decode($u2f);
    //TODO: Check for errors in clientResponse
    $authData = array(
      'authenticateResponse' => $clientResponse
    );

    $res = json_decode(auth_complete($user->ID, json_encode($authData)));
    if(!isset($res->{'handle'})) {
      _log($res);
      return new WP_Error('authentication_failed', 'U2F authentication failed');
    }
  }

  return $user;
}

function u2f_form() {
  global $u2f_transient;
  if(!empty($u2f_transient)) {
    ?>
<script src="chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js"></script>
<script>
  var u2f_data = <?php echo $u2f_transient; ?>;
  var form = document.getElementById('loginform');
  form.style.display = 'none';

  //Run this after the entire form has been drawn.
  setTimeout(function() {
    // Re-populate any form fields.
    <?php foreach($_POST as $name => $value): ?>
    var fields = document.getElementsByName("<?php echo $name; ?>");
    if(fields.length == 1) {
      fields[0].value = "<?php echo $value; ?>";
    }
    <?php endforeach; ?>
  }, 0);

  u2f.sign(u2f_data.authenticateRequests, function(resp) {
    var u2f_f = document.createElement('input');
    u2f_f.name = 'u2f';
    u2f_f.type = 'hidden';
    u2f_f.value = JSON.stringify(resp);
    form.appendChild(u2f_f);
    form.submit();
  });
</script>
    <?php
  }
}

add_action('login_form', 'u2f_form');
add_filter('wp_authenticate_user', 'u2f_login')
?>
