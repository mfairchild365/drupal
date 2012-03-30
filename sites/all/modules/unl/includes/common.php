<?php

function unl_load_zend_framework() {
  static $isLoaded = FALSE;

  if ($isLoaded) {
    return;
  }

  set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/../../../libraries');
  require_once 'Zend/Loader/Autoloader.php';
  $autoloader = Zend_Loader_Autoloader::getInstance();
  $autoloader->registerNamespace('Unl_');
  $isLoaded = TRUE;
}

/**
 * Custom function to get the db prefix of the 'main' site.
 */
function unl_get_shared_db_prefix() {
  require 'sites/default/settings.php';
  $shared_prefix = $databases['default']['default']['prefix'];

  return $shared_prefix;
}

/**
 * Custom function.
 */
function unl_shared_variable_get($name, $default = NULL) {
  $shared_prefix = unl_get_shared_db_prefix();
  $data = db_query(
    "SELECT * "
    . "FROM {$shared_prefix}variable "
    . "WHERE name = :name",
    array(':name' => $name)
  )->fetchAll();

  if (count($data) == 0) {
    return $default;
  }

  return unserialize($data[0]->value);
}

function unl_site_variable_get($db_prefix, $name, $default = NULL) {
  $shared_prefix = unl_get_shared_db_prefix();
  $data = db_query(
    "SELECT * "
    . "FROM {$db_prefix}_{$shared_prefix}variable "
    . "WHERE name = :name",
    array(':name' => $name)
  )->fetchAll();

  if (count($data) == 0) {
    return $default;
  }

  return unserialize($data[0]->value);
}

/**
 * Given a URI, will return the name of the directory for that site in the sites directory.
 */
function unl_get_sites_subdir($uri, $trim_subdomain = TRUE) {
  $path_parts = parse_url($uri);
  if ($trim_subdomain && substr($path_parts['host'], -7) == 'unl.edu') {
    $path_parts['host'] = 'unl.edu';
  }
  $sites_subdir = $path_parts['host'] . $path_parts['path'];
  $sites_subdir = strtr($sites_subdir, array('/' => '.'));

  while (substr($sites_subdir, 0, 1) == '.') {
    $sites_subdir = substr($sites_subdir, 1);
  }
  while (substr($sites_subdir, -1) == '.') {
    $sites_subdir = substr($sites_subdir, 0, -1);
  }

  return $sites_subdir;
}

/**
 * Given a URI of an existing site, will return settings defined in that site's settings.php
 */
function unl_get_site_settings($uri) {
  $settings_file = DRUPAL_ROOT . '/sites/' . unl_get_sites_subdir($uri) . '/settings.php';
  if (!is_readable($settings_file)) {
    throw new Exception('No settings.php exists for site at ' . $uri);
  }

  if (is_readable(DRUPAL_ROOT . '/sites/all/settings.php')) {
    require DRUPAL_ROOT . '/sites/all/settings.php';
  }

  require $settings_file;
  unset($uri);
  unset($settings_file);

  return get_defined_vars();
}

/**
 * Custom function that returns TRUE if the given table is shared with another site.
 * @param string $table_name
 */
function unl_table_is_shared($table_name) {
  $db_config = $GLOBALS['databases']['default']['default'];
  if (is_array($db_config['prefix']) &&
      isset($db_config['prefix']['role']) &&
      $db_config['prefix']['default'] != $db_config['prefix'][$table_name]) {
    return TRUE;
  }
  return FALSE;
}

/**
 * Custom function that formats a string of HTML using Tidy
 * @param string $string
 */
function unl_tidy($string) {
  if (class_exists('Tidy') && variable_get('unl_tidy')) {
    $tidy = new Tidy();

    // Tidy options: http://tidy.sourceforge.net/docs/quickref.html
    $options = array(
      // HTML, XHTML, XML Options
      'doctype' => 'omit',
      'new-blocklevel-tags' => 'article,aside,header,footer,section,nav,hgroup,address,figure,figcaption,output',
      'new-inline-tags' => 'video,audio,canvas,ruby,rt,rp,time,code,kbd,samp,var,mark,bdi,bdo,wbr,details,datalist,source,summary',
      'output-xhtml' => true,
      'show-body-only' => true,
      // Pretty Print
      'indent' => true,
      'indent-spaces' => 2,
      'vertical-space' => false,
      'wrap' => 140,
      'wrap-attributes' => false,
      // Misc
      'force-output' => true,
      'quiet' => true,
      'tidy-mark' => false,
    );

    // Prevent Tidy from trying to move <script> to the head if it is the first thing
    if (strtolower(substr(trim($string), 0, 7)) == '<script' || substr(trim($string), 0, 4) == '<!--') {
      $string = "&nbsp; <!-- Tidy: Start field with something other than script or comment to remove this -->\n" . $string;
    }

    $tidy->parseString($string, $options, 'utf8');
    if ($tidy->cleanRepair()) {
      return $tidy;
    }
  }

  return $string;
}

/**
 * A shared-table safe method that returns TRUE if the user is a member of the super-admin role.
 */
function unl_user_is_administrator() {
  $user = $GLOBALS['user'];

  // If the role table is shared, use parent site's user_admin role, otherwise use the local value.
  if (unl_table_is_shared('role')) {
    $admin_role_id = unl_shared_variable_get('user_admin_role');
  }
  else {
    $admin_role_id = variable_get('user_admin_role');
  }

  if ($user && in_array($admin_role_id, array_keys($user->roles))) {
    return TRUE;
  }

  return FALSE;
}
