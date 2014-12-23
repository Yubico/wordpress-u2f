<?php
/**
* Plugin Name: U2F Wordpress
* Plugin URI: http://developers.yubico.com/
* Description: Enables U2F authentication for Wordpress.
* Version: 0.1
* Author: Yubico
* Author URI: http://www.yubico.com
* License: GPL12
*/

require_once('vendor/autoload.php');

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

function init_u2f() {
  $options = get_option('u2f_settings');
  return new u2flib_server\U2F($options['appId']); //, $options['attestDir']);
}

$u2f = init_u2f();

/*
 * ADMIN MANAGEMENT
 */

function u2f_add_admin_menu() { 
  add_options_page('U2F', 'U2F', 'manage_options', 'u2f', 'u2f_options_page');
}

function sanitize_u2f_settings($settings) {
  return $settings;
}

function u2f_settings_init() { 
  register_setting('pluginPage', 'u2f_settings', 'sanitize_u2f_settings');

  add_settings_section(
    'u2f_pluginPage_section', 
    'U2F settings',
    'u2f_settings_section_callback', 
    'pluginPage'
  );

  add_settings_field(
    'appId', 
    'Application ID',
    'u2f_appId_render', 
    'pluginPage', 
    'u2f_pluginPage_section' 
  );

  /*
  add_settings_field(
    'attestDir', 
    'Attestation directory',
    'u2f_attestDir_render', 
    'pluginPage', 
    'u2f_pluginPage_section' 
  );
  */
}


function u2f_appId_render() { 
  $options = get_option('u2f_settings');
?>
  <input type='text' name='u2f_settings[appId]' value='<?php echo $options['appId']; ?>' class="regular-text code">
<?php
}


function u2f_val_attestDir_render() { 
  $options = get_option('u2f_settings');
?>
  <input type='text' name='u2f_settings[attestDir]' value='<?php echo $options['attestDir']; ?>' class="regular-text">
  <?php
}


function u2f_settings_section_callback() {
  // TODO: validate settings?
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

function u2f_get_registrations($user_id) {
  $regs = get_user_option('u2f_user_registrations', $user_id);
  return $regs ? $regs : [];
}

function u2f_profile_fields($user) {
  global $u2f;
  $options = get_option('u2f_settings');
  if(empty($options)) return;

  $devices = u2f_get_registrations($user->ID);
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
          <label for="u2f_unregister_<?php echo $device->keyHandle; ?>">
            <?php
            $registered = new DateTime();
            $registered->setTimestamp($device->dateRegistered);
            echo 'Registered: ' . $registered->format('Y-m-d');
            ?>
          </label>
        </td>
        <td>
          <input type="checkbox" name="u2f_unregister[]" id="u2f_unregister_<?php echo $device->keyHandle; ?>" value="<?php echo $device->keyHandle; ?>">
        </td>
        </tr>
        <?php endforeach; ?>
        </table>
        <br />
        <input type="hidden" name="u2f_register_response" id="u2f_register_response" />
        <a id="u2f_register" href="#" class="button">Register a new U2F Device</a>
        <div id="u2f_touch_notice" style="display: none;">
          Touch the flashing button on your U2F device now.
        </div>
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
          var req = resp[0];
          var auth = resp[1];
          u2f.register([req], auth, function(data) {
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
  global $u2f;
  if(!empty($_POST['u2f_register_response'])) {
    $req = get_user_option('u2f_user_regData', $user_id);
    if(empty($req)) {
      return new WP_Error('u2f_registration_failed', "There was no outstanding registration request for user $user_id");
    }
    update_user_option($user_id, 'u2f_user_regData', '');
    if($req->time < time() - 300) {
      return new WP_Error('u2f_registration_failed', "The u2f registration request for $user_id expired before reply");
    }
    unset($req->time);
    $registerResponse = $_POST['u2f_register_response'];

    $registration = $u2f->doRegister($req, json_decode(stripslashes($registerResponse)));
    $now = new DateTime();
    $registration->dateRegistered = $now->getTimeStamp();

    $regs = u2f_get_registrations($user_id);
    array_push($regs, $registration);
    update_user_option($user_id, 'u2f_user_registrations', $regs);
  } else if(isset($_POST['u2f_unregister'])) {
    $handles = $_POST['u2f_unregister'];
    $regs = u2f_get_registrations($user_id);
    //file_put_contents('php://stderr', print_r('regs:' . gettype($regs[0]) . '!!?', TRUE));

    foreach($handles as $handle) {
      foreach($regs as $key => $reg) {
        if($reg->keyHandle == $handle) {
          unset($regs[$key]);
        }
      }
    }
    update_user_option($user_id, 'u2f_user_registrations', $regs);
  }
}

function u2f_store_regData($user, $regData) {
  $regData->time = time();
  update_user_option($user->ID, 'u2f_user_regData', $regData);
}

function ajax_u2f_register_begin() {
  global $u2f;
  $user = wp_get_current_user();
  if(is_user_logged_in()) {

    try {
      $regData = $u2f->getRegisterData(u2f_get_registrations($user->ID));
    } catch( Exception $e ) {
      $errors->add('u2f_error', "<strong>ERROR</strong>: There was an error obtaining U2F registration data: " . $data->errorMessage);
    }

    u2f_store_regData($user, $regData[0]);
    echo json_encode($regData);
  }
  die();
}
add_action('wp_ajax_u2f_register', 'ajax_u2f_register_begin');

function validate_u2f_register(&$errors, $update=null, &$user=null) {
  if(isset($_POST['u2f_register_response'])) {
    $data = json_decode(stripslashes($_POST['u2f_register_response']), true);
    if(isset($data['errorCode'])) {
      $errors->add('u2f_error', "<strong>ERROR</strong>: There was an error registering your U2F device (error code ".$data['errorCode'].").");
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
  global $u2f;
  $options = get_option('u2f_settings');

  if(empty($options['appId'])) return $user;

  if(wp_check_password($_POST['pwd'], $user->data->user_pass, $user->ID) && !isset($_POST['u2f'])) {
    $authData = $u2f->getAuthenticateData(u2f_get_registrations($user->ID));
    //if(false) {
    //  return new WP_Error('u2f_error_'.$authData['errorCode'], 'The U2F validation server is unreachable.');
    //}
    global $u2f_transient;
    $u2f_transient = $authData;

    update_user_option($user->ID, 'u2f_user_reqData', $authData);

    if(has_devices($authData)) {
      return new WP_Error('authentication_failed', 'Touch your U2F device now.');
    }
  } else if(isset($_POST['u2f'])) {
    $authenticateResponse = json_decode(stripslashes($_POST['u2f']));

    file_put_contents('php://stderr', print_r('$authenticateResponse:' . stripslashes($_POST['u2f']) . '!!?', TRUE));

    if(property_exists($authenticateResponse, 'errorCode')) {
      switch($authenticateResponse->errorCode) {
        case 5:
          return new WP_Error('u2f_error', 'Authentication timed out. Please try again.');
        default:
          return new WP_Error('u2f_error', 'Client error.');
      }
    }

    //$properties = array('last-ip' => $_SERVER['REMOTE_ADDR']);
    $authRequest = get_user_option('u2f_user_reqData', $user->ID);

    $u2f->doAuthenticate($authRequest, u2f_get_registrations($user->ID), $authenticateResponse);
  }

  return $user;
}

function has_devices($authData) {
  return sizeof($authData > 0);
}

function u2f_form() {
  global $u2f_transient;
  $options = get_option('u2f_settings');

  if(empty($options['appId'])) {
    _log("No appId set!");
    ?>
<p>
<strong>WARNING:</strong> The U2F plugin has not been configured, and is therefore disabled.
</p>
    <?php
    return;
  } else if(!empty($u2f_transient)) {
    ?>
<script src="chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js"></script>
<script>
  var u2f_data = <?php echo json_encode($u2f_transient); ?>;
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

  console.log("u2f_data: " + JSON.stringify(u2f_data));

  u2f.sign(u2f_data, function(resp) {
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
add_filter('wp_authenticate_user', 'u2f_login');

?>
