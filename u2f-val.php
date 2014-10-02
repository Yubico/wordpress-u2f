<?php
/**
* Plugin Name: U2F Wordpress
* Plugin URI: http://mypluginuri.com/
* Description: A brief description about your plugin.
* Version: 1.0 or whatever version of the plugin (pretty self explanatory)
* Author: Plugin Author's Name
* Author URI: Author's website
* License: A "Slug" license name e.g. GPL12
*/

define("BASE", "http://localhost/wsapi/u2fval/");
define("CLIENT", "wp-u2f");
define("PASS", "password");

function curl_begin($url) {
  $ch = curl_init($url);
  //curl_setopt($ch, CURLOPT_FAILONERROR, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERPWD, CLIENT.":".PASS);
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
  return curl_send(BASE.$username."/");
}

function register_begin($username) {
  return curl_send(BASE.$username."/register");
}

function register_complete($username, $registerData) {
  return curl_send(BASE.$username."/register", $registerData);
}

function unregister($username, $handle) {
  return curl_delete(BASE.$username."/".$handle);
}

function auth_begin($username) {
  return curl_send(BASE.$username."/authenticate");
}

function auth_complete($username, $authData) {
  return curl_send(BASE.$username."/authenticate", $authData);
}

function has_devices($authData) {
  return sizeof(json_decode($authData)->{'authenticateRequests'}) > 0;
}


if(!function_exists('_log')){
  function _log( $message ) {
    if( WP_DEBUG === true ){
      if( is_array( $message ) || is_object( $message ) ){
        error_log( print_r( $message, true ) );
      } else {
        error_log( $message );
      }
    }
  }
}

/*
 * MANAGEMENT
 */

function u2f_profile_fields($user) {
  $devices = json_decode(list_Devices($user->ID));
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
    _log("Register U2F!");
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
  die(register_begin($user->ID));
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

function u2f_login($user) {
  if(wp_check_password($_POST['pwd'], $user->data->user_pass, $user->ID) && !isset($_POST['u2f'])) {
    $authData = auth_begin($user->ID);
    if(has_devices($authData)) {
      return new WP_Error('authentication_failed', $authData);
    }
  } else if(isset($_POST['u2f'])) {
    $_POST = array_map('stripslashes_deep', $_POST); //WordPress adds slashes, because it is insane.
    $u2f = $_POST['u2f'];
    $clientResponse = json_decode($u2f);
    //TODO: Check for errors
    $authData = array(
      'authenticateResponse' => $clientResponse
    );

    $res = json_decode(auth_complete($user->ID, json_encode($authData)));
    if(!isset($res->{'handle'})) {
      _log("U2F error");
      _log($res);
      return new WP_Error('authentication_failed', 'U2F authentication failed');
    }
  }

  return $user;
}

function u2f_form() {
  if(isset($_POST['log']) && isset($_POST['pwd'])) {
    $username = $_POST['log'];
    $password = $_POST['pwd'];
    $user = get_user_by( 'login', $username );
    if($user && wp_check_password($password, $user->data->user_pass, $user->ID)) {
      _log("Submitted ok pass!");
      ?>
<script src="chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js"></script>
<script>
  var u2f_data_f = document.getElementById("login_error");
  var u2f_data = JSON.parse(u2f_data_f.textContent);
  console.log(u2f_data);
  u2f_data_f.remove();
  var form = document.getElementById('loginform');

  //Run this after the entire form has been drawn.
  setTimeout(function() {
    // Re-populate any form fields.
    <?php foreach($_POST as $name => $value): ?>
    var fields = document.getElementsByName("<?php echo $name; ?>");
    if(fields.length == 1) {
      fields[0].value = "<?php echo $value; ?>";
    }
    <?php endforeach; ?>

    // Hide all form fields.
    for(var i=0; i<form.childElementCount; i++) {
      form.children[i].style.display = 'none';
    }

    var text = document.createElement('p');
    text.textContent = "Touch your U2F device now.";
    form.appendChild(text);
  }, 1);

  console.log(u2f_data.authenticateRequests);
  u2f.sign(u2f_data.authenticateRequests, function(resp) {
    var u2f_f = document.createElement('input');
    u2f_f.name = 'u2f';
    u2f_f.type = 'hidden';
    u2f_f.value = JSON.stringify(resp);
    form.appendChild(u2f_f);
    console.log(resp);
    form.submit();
  });
</script>
<?php
    }
  }
}

add_action('login_form', 'u2f_form');
add_filter('wp_authenticate_user', 'u2f_login')
?>
