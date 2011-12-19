<?php
require_once DRUPAL_ROOT . '/includes/install.core.inc';

function unl_sites_page() {
  $page = array();
  $page[] = drupal_get_form('unl_site_create');
  $page[] = drupal_get_form('unl_site_list');
  $page[] = drupal_get_form('unl_site_updates');
  $page[] = drupal_get_form('unl_site_email_settings');

  return $page;
}

function unl_site_create($form, &$form_state) {
  $form['root'] = array(
    '#type'  => 'fieldset',
    '#title' => 'Create New Site',
  );

  $form['root']['site_path'] = array(
    '#type' => 'textfield',
    '#title' => t('New site path'),
    '#description' => t('Relative url for the new site'),
    '#default_value' => t('newsite'),
    '#required' => TRUE,
  );

  $form['root']['clean_url'] = array(
    '#type' => 'checkbox',
    '#title' => t('Use clean URLs'),
    '#description' => t('Unless you have some reason to think your site won\'t support this, leave it checked.'),
    '#default_value' => 1,
  );

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Create Site',
  );

  return $form;
}

function unl_site_create_validate($form, &$form_state) {
  $site_path = trim($form_state['values']['site_path']);

  if (substr($site_path, 0, 1) == '/') {
    $site_path = substr($site_path, 1);
  }
  if (substr($site_path, -1) != '/') {
    $site_path .= '/';
  }

  $site_path_parts = explode('/', $site_path);
  $first_directory = array_shift($site_path_parts);
  if (in_array($first_directory, array('includes', 'misc', 'modules', 'profiles', 'scripts', 'sites', 'themes'))) {
    form_set_error('site_path', t('Drupal site paths must not start with the "' . $first_directory . '" directory.'));
  }

  $form_state['values']['site_path'] = $site_path;
}

function unl_site_create_submit($form, &$form_state) {
  $site_path = $form_state['values']['site_path'];
  $clean_url = $form_state['values']['clean_url'];

  $db_prefix = unl_create_db_prefix($site_path);

  $site_path = explode('/', $site_path);
  foreach (array_keys($site_path) as $i) {
    $site_path[$i] = unl_sanitize_url_part($site_path[$i]);
  }
  $site_path = implode('/', $site_path);
  $uri = url($site_path, array('absolute' => TRUE, 'https' => FALSE));

  $clean_url = intval($clean_url);

  db_insert('unl_sites')->fields(array(
    'site_path' => $site_path,
    'uri' => $uri,
    'clean_url' => $clean_url,
    'db_prefix' => $db_prefix
  ))->execute();

  drupal_set_message(t('The site ' . $uri . ' has been started, run unl/cron.php to finish setup.'));
  $form_state['redirect'] = 'admin/sites/unl/add';
  return;
}

function unl_site_list($form, &$form_state) {
  $headers = array(
    'site_path' => array(
      'data' => 'Site Path',
      'field' => 's.site_path',
    ),
    'db_prefix' => array(
      'data' => 'Datbase Prefix',
      'field' => 's.db_prefix',
    ),
    'installed' => array(
      'data' => 'Status',
      'field' => 's.installed',
    ),
    'uri' => array(
      'data' => 'Link',
      'field' => 's.uri',
    ),
    'operations' => t('Operations'),
  );

  $sites = db_select('unl_sites', 's')
    ->fields('s', array('site_id', 'db_prefix', 'installed', 'site_path', 'uri'))
    ->extend('TableSort')
    ->orderByHeader($headers)
    ->execute()
    ->fetchAll();

  $total_no_of_sites = count($sites);

  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Existing Sites ' . '(total: ' . $total_no_of_sites . ')',
  );

  $form['root']['site_list'] = array(
    '#theme' => 'unl_table',
    '#header' => $headers,
  );

  foreach ($sites as $site) {
    unset($checkbox);
    $form['root']['site_list']['rows'][$site->site_id] = array(
      'site_path' => array('#prefix' => $site->site_path),
      'db_prefix' => array('#prefix' => $site->db_prefix . '_' . $GLOBALS['databases']['default']['default']['prefix']),
      'installed' => array('#prefix' => _unl_get_install_status_text($site->installed)),
      'uri' => array(
        '#type' => 'link',
        '#title' => $site->uri,
        '#href' => $site->uri,
      ),
      'operations' => array(
        '#type' => 'link',
        '#title' => t('delete'),
        '#href' => 'admin/sites/unl/'.$site->site_id.'/delete',
      ),
    );
  }

  return $form;
}

/**
 * Form to confirm UNL site delete operation.
 */
function unl_site_delete_confirm($form, &$form_state, $site_id) {
  $form['site_id'] = array(
    '#type' => 'value',
    '#value' => $site_id,
  );

  $site_path = db_select('unl_sites', 's')
    ->fields('s', array('site_path'))
    ->condition('site_id', $site_id)
    ->execute()
    ->fetchCol();

  return confirm_form($form, t('Are you sure you want to delete the site %site_path ?', array('%site_path' => $site_path[0])), 'admin/sites/unl', t('This action cannot be undone. DOUBLE CHECK WHICH CMS INSTANCE YOU ARE ON!'), t('Delete Site'));
}

/**
 * Form submit handler for unl_site_delete_confirm().
 */
function unl_site_delete_confirm_submit($form, &$form_state) {
  if (!isset($form_state['values']['site_id'])) {
    return;
  }
  unl_site_remove($form_state['values']['site_id']);
  drupal_set_message('The site has been scheduled for removal.');
  $form_state['redirect'] = 'admin/sites/unl';
}

function unl_site_updates($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Maintenance',
    '#description' => 'Using drush, do database updates and clear the caches of all sites.',
  );

  $form['root']['submit'] = array(
    '#type'  => 'submit',
    '#value' => 'Run Drush',
  );

  return $form;
}

function unl_site_updates_submit($form, &$form_state) {
  $sites = db_select('unl_sites', 's')
    ->fields('s', array('site_id', 'db_prefix', 'installed', 'site_path', 'uri'))
    ->execute()
    ->fetchAll();

  $operations = array();

  foreach ($sites as $site) {
    $operations[] = array('unl_site_updates_step', array($site->uri));
  }

  $batch = array(
    'operations' => $operations,
    'file' => substr(__FILE__, strlen(DRUPAL_ROOT) + 1),
  );
  batch_set($batch);
}

function unl_site_updates_step($site_uri, &$context) {
  $uri = escapeshellarg($site_uri);
  $root = escapeshellarg(DRUPAL_ROOT);
  $output = '';
  $command = "sites/all/modules/drush/drush.php -y --token=secret --root={$root} --uri={$uri} updatedb 2>&1";
  $output .= shell_exec($command);
  $command = "sites/all/modules/drush/drush.php -y --root={$root} --uri={$uri} cc all 2>&1";
  $output .= shell_exec($command);
  
  drupal_set_message('Messages from ' . $site_uri . ':<br />' . PHP_EOL . '<pre>' . $output . '</pre>', 'status');
}

function unl_site_email_settings($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Email Alert Settings',
    '#description' => 'When a new site is created, who should be emailed?',
  );

  $form['root']['unl_site_created_email_address'] = array(
    '#type' => 'textfield',
    '#title' => 'Address for Notification',
    '#description' => 'When a site has been been created and migrated, send an email to this address.',
    '#default_value' => variable_get('unl_site_created_email_address'),
  );

  $form['root']['unl_site_created_alert_admins'] = array(
    '#type' => 'checkbox',
    '#title' => 'Email Site Admins',
    '#description' => 'When a site has been created and migrated, send an email to the Site Admins.',
    '#default_value' => variable_get('unl_site_created_alert_admins'),
  );

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Update Settings',
  );

  return $form;
}

function unl_site_email_settings_submit($form, &$form_state) {
  variable_set('unl_site_created_email_address', $form_state['values']['unl_site_created_email_address']);
  variable_set('unl_site_created_alert_admins',  $form_state['values']['unl_site_created_alert_admins']);
}

function unl_site_remove($site_id) {
  $uri = db_select('unl_sites', 's')
    ->fields('s', array('uri'))
    ->condition('site_id', $site_id)
    ->execute()
    ->fetchCol();

  if (!isset($uri[0])) {
    form_set_error(NULL, 'Unfortunately, the site could not be removed.');
    return;
  }
  $uri = $uri[0];

  $sites_subdir = unl_get_sites_subdir($uri);
  $sites_subdir = DRUPAL_ROOT . '/sites/' . $sites_subdir;
  $sites_subdir = realpath($sites_subdir);

  // A couple checks to make sure we aren't deleting something we shouldn't be.
  if (substr($sites_subdir, 0, strlen(DRUPAL_ROOT . '/sites/')) != DRUPAL_ROOT . '/sites/') {
    form_set_error(NULL, 'Unfortunately, the site could not be removed.');
    return;
  }

  if (strlen($sites_subdir) <= strlen(DRUPAL_ROOT . '/sites/')) {
    form_set_error(NULL, 'Unfortunately, the site could not be removed.');
    return;
  }

  shell_exec('rm -rf ' . escapeshellarg($sites_subdir));

  db_update('unl_sites')
    ->fields(array('installed' => 3))
    ->condition('site_id', $site_id)
    ->execute();
  db_update('unl_sites_aliases')
    ->fields(array('installed' => 3))
    ->condition('site_id', $site_id)
    ->execute();

  return TRUE;
}

function unl_aliases_page() {
  $page = array();
  $page[] = drupal_get_form('unl_site_alias_create');
  $page[] = drupal_get_form('unl_site_alias_list');
  $page[] = drupal_get_form('unl_page_alias_create');
  $page[] = drupal_get_form('unl_page_alias_list');

  return $page;
}

function unl_site_alias_create($form, &$form_state) {
  $sites = db_select('unl_sites', 's')
    ->fields('s', array('site_id', 'uri'))
    ->orderBy('uri')
    ->execute()
    ->fetchAll();
  foreach ($sites as $site) {
    $site_list[$site->site_id] = $site->uri;
  }

  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Create New Site Alias',
  );

  $form['root']['site'] = array(
    '#type' => 'select',
    '#title' => 'Aliased Site',
    '#description' => 'The site the alias will point to.',
    '#options' => $site_list,
    '#required' => TRUE,
  );

  $form['root']['base_uri'] = array(
    '#type' => 'textfield',
    '#title' => t('Alias Base URL'),
    '#description' => t('The base URL for the new alias. (This should resolve to the directory containing drupal\'s .htaccess file'),
    '#default_value' => url('', array('https' => FALSE)),
    '#required' => TRUE,
  );

  $form['root']['path'] = array(
    '#type' => 'textfield',
    '#title' => t('Path'),
    '#description' => t('Path for the new alias.'),
  );

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Create Alias',
  );

  return $form;
}

function unl_site_alias_create_validate($form, &$form_state) {
  $form_state['values']['base_uri'] = trim($form_state['values']['base_uri']);
  $form_state['values']['path']     = trim($form_state['values']['path']);

  if (substr($form_state['values']['base_uri'], -1) != '/') {
    $form_state['values']['base_uri'] .= '/';
  }

  if (substr($form_state['values']['path'], -1) != '/') {
    $form_state['values']['path'] .= '/';
  }

  if (substr($form_state['values']['path'], 0, 1) == '/') {
    $form_state['values']['path'] = substr($form_state['values']['path'], 1);
  }
}

function unl_site_alias_create_submit($form, &$form_state) {
  db_insert('unl_sites_aliases')->fields(array(
    'site_id' => $form_state['values']['site'],
    'base_uri' => $form_state['values']['base_uri'],
    'path' => $form_state['values']['path'],
  ))->execute();
}

function unl_site_alias_list($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Existing Site Aliases',
  );

  $headers = array(
    'site_uri' => array(
      'data' => 'Site URI',
      'field' => 's.uri',
    ),
    'alias_uri' => array(
      'data' => 'Alias URI',
      'field' => 'a.path',
    ),
    'installed' => array(
      'data' => 'Status',
      'field' => 'a.installed',
    ),
    'remove'    => 'Remove (can not undo!)'
  );

  $form['root']['alias_list'] = array(
    '#theme' => 'unl_table',
    '#header' => $headers,
  );

  $query = db_select('unl_sites_aliases', 'a')
    ->extend('TableSort')
    ->orderByHeader($headers);
  $query->join('unl_sites', 's', 's.site_id = a.site_id');
  $query->fields('s', array('uri'));
  $query->fields('a', array('site_alias_id', 'base_uri', 'path', 'installed'));
  $sites = $query->execute()->fetchAll();

  foreach ($sites as $site) {
    $form['root']['alias_list']['rows'][$site->site_alias_id] = array(
      'site_uri' => array('#prefix'  => $site->uri),
      'alias_uri' => array('#prefix' => $site->base_uri . $site->path),
      'installed' => array('#prefix' => _unl_get_install_status_text($site->installed)),
      'remove' => array(
        '#type' => 'checkbox',
        '#parents' => array('aliases', $site->site_alias_id, 'remove'),
        '#default_value' => 0
      ),
    );
  }

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Delete Selected Aliases',
  );

  return $form;
}

function unl_site_alias_list_submit($form, &$form_state) {
  $site_alias_ids = array(-1);
  foreach ($form_state['values']['aliases'] as $site_alias_id => $alias) {
    if ($alias['remove']) {
      $site_alias_ids[] = $site_alias_id;
    }
  }

  $query = db_select('unl_sites_aliases', 'a');
  $query->join('unl_sites', 's', 'a.site_id = s.site_id');
  $data = $query
    ->fields('a', array('site_alias_id', 'base_uri', 'path'))
    ->fields('s', array('db_prefix'))
    ->condition('site_alias_id', $site_alias_ids, 'IN')
    ->execute()
    ->fetchAll();
  
  $site_alias_ids = array(-1);
  foreach ($data as $row) {
    $alias_url = $row->base_uri . $row->path;
    $primary_base_url = unl_site_variable_get($row->db_prefix, 'unl_primary_base_url', '');
    if ($primary_base_url == $alias_url) {
      drupal_set_message("Cannot delete the alias $alias_url.  It is currently the Primary Base URL for a site.", 'error');
      continue;
    }
    $site_alias_ids[] = $row->site_alias_id;
    drupal_set_message("The alias $alias_url was scheduled for removal.");
  }
  
  db_update('unl_sites_aliases')
    ->fields(array('installed' => 3))
    ->condition('site_alias_id', $site_alias_ids, 'IN')
    ->execute();
}

function unl_page_alias_create($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Create New Page Alias',
  );

  $form['root']['from_uri'] = array(
    '#type' => 'textfield',
    '#title' => t('From URL'),
    '#description' => 'The URL that users will visit.',
    '#default_value' => url('', array('https' => FALSE)),
    '#required' => TRUE,
  );

  $form['root']['to_uri'] = array(
    '#type' => 'textfield',
    '#title' => t('To URL'),
    '#description' => t('The URL users will be redirected to.'),
    '#default_value' => url('', array('https' => FALSE)),
    '#required' => TRUE,
  );

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Create Alias',
  );

  return $form;
}

function unl_page_alias_create_submit($form, &$form_state) {
  db_insert('unl_page_aliases')->fields(array(
    'from_uri' => $form_state['values']['from_uri'],
    'to_uri' => $form_state['values']['to_uri'],
  ))->execute();
}

function unl_page_alias_list($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'Existing Page Aliases',
  );

  $headers = array(
    'site_uri' => array(
      'data' => 'From URI',
      'field' => 'a.from_uri',
    ),
    'alias_uri' => array(
      'data' => 'To URI',
      'field' => 'a.to_uri',
    ),
    'installed' => array(
      'data' => 'Status',
      'field' => 'a.installed',
    ),
    'remove' => 'Remove (can not undo!)'
  );

  $form['root']['alias_list'] = array(
    '#theme' => 'unl_table',
    '#header' => $headers,
  );

  $query = db_select('unl_page_aliases', 'a')
    ->extend('TableSort')
    ->orderByHeader($headers);
  $query->fields('a', array('page_alias_id', 'from_uri', 'to_uri', 'installed'));
  $sites = $query->execute()->fetchAll();

  foreach ($sites as $site) {
    $form['root']['alias_list']['rows'][$site->page_alias_id] = array(
      'site_uri' => array('#prefix'  => $site->from_uri),
      'alias_uri' => array('#prefix' => $site->to_uri),
      'installed' => array('#prefix' => _unl_get_install_status_text($site->installed)),
      'remove' => array(
        '#type' => 'checkbox',
        '#parents' => array('aliases', $site->page_alias_id, 'remove'),
        '#default_value' => 0
      ),
    );
  }

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Delete Selected Aliases',
  );

  return $form;
}

function unl_page_alias_list_submit($form, &$form_state) {
  $page_alias_ids = array();
  foreach ($form_state['values']['aliases'] as $page_alias_id => $alias) {
    if ($alias['remove']) {
      $page_alias_ids[] = $page_alias_id;
    }
  }

  db_update('unl_page_aliases')
    ->fields(array('installed' => 3))
    ->condition('page_alias_id', $page_alias_ids, 'IN')
    ->execute();
}

function unl_wdn_registry($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'WDN Registry Database',
  );

  $form['root']['production'] = array(
    '#type' => 'checkbox',
    '#title' => 'This is production.',
    '#description' => 'If this box checked, sites imported will be marked as imported.',
    '#default_value' => variable_get('unl_wdn_registry_production'),
  );

  $form['root']['host'] = array(
    '#type' => 'textfield',
    '#title' => 'Host',
    '#description' => 'Hostname of the WDN Registry database.',
    '#default_value' => variable_get('unl_wdn_registry_host'),
    '#required' => TRUE,
  );

  $form['root']['username'] = array(
    '#type' => 'textfield',
    '#title' => 'Username',
    '#description' => 'Username for the WDN Registry database.',
    '#default_value' => variable_get('unl_wdn_registry_username'),
    '#required' => TRUE,
  );

  $form['root']['password'] = array(
    '#type' => 'password',
    '#title' => 'Password',
    '#description' => 'Password for the WDN Registry database.',
    '#required' => TRUE,
  );

  $form['root']['database'] = array(
    '#type' => 'textfield',
    '#title' => 'Database',
    '#description' => 'Database for the WDN Registry database.',
    '#default_value' => variable_get('unl_wdn_registry_database'),
    '#required' => TRUE,
  );

  $form['root']['frontier_username'] = array(
    '#type' => 'textfield',
    '#title' => 'Frontier Username',
    '#description' => 'Username to connect to frontier FTP.',
    '#default_value' => variable_get('unl_frontier_username'),
    '#required' => TRUE,
  );

  $form['root']['frontier_password'] = array(
    '#type' => 'password',
    '#title' => 'Frontier Password',
    '#description' => 'Password to connect to frontier FTP.',
    '#required' => TRUE,
  );

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Update',
  );

  return $form;
}

function unl_wdn_registry_submit($form, &$form_state) {
  variable_set('unl_wdn_registry_production', $form_state['values']['production']);
  variable_set('unl_wdn_registry_host', $form_state['values']['host']);
  variable_set('unl_wdn_registry_username', $form_state['values']['username']);
  variable_set('unl_wdn_registry_password', $form_state['values']['password']);
  variable_set('unl_wdn_registry_database', $form_state['values']['database']);
  variable_set('unl_frontier_username', $form_state['values']['frontier_username']);
  variable_set('unl_frontier_password', $form_state['values']['frontier_password']);
}

function _unl_get_install_status_text($id) {
  switch ($id) {
    case 0:
    $installed = 'Scheduled for creation.';
    break;

    case 1:
    $installed = 'Curently being created.';
    break;

    case 2:
      $installed = 'In production.';
      break;

    case 3:
      $installed = 'Scheduled for removal.';
      break;

    case 4:
      $installed = 'Currently being removed.';
      break;

    default:
      $installed = 'Unknown';
      break;
  }

  return $installed;
}

/**
 * Callback for the path admin/sites/unl/user-audit
 * Presents a form to query what roles (if any) a user has on each site.
 */

function unl_user_audit($form, &$form_state) {
  $form['root'] = array(
    '#type' => 'fieldset',
    '#title' => 'User Audit',
  );

  $form['root']['username'] = array(
    '#type' => 'textfield',
    '#title' => 'Username',
    '#required' => TRUE,
  );

  /*
  $form['root']['ignore_shared_roles'] = array(
    '#type' => 'checkbox',
    '#title' => 'Ignore Shared Roles',
  );
  */

  $form['root']['submit'] = array(
    '#type' => 'submit',
    '#value' => 'Search',
  );

  // If no user input has been received yet, return the base form.
  if (!isset($form_state['values']) || !$form_state['values']['username']) {
    return $form;
  }


  // Otherwise, since we have a username, we can query the sub-sites and return a list of roles for each.
  $username = $form_state['values']['username'];


  $form['results'] = array(
    '#type' => 'fieldset',
    '#title' => 'Results',
  );

  $form['results']['roles'] = _unl_get_user_audit_content($username);

  return $form;
}

/**
 * Returns an array that can be passed to drupal_render() of the given user's sites/roles.
 * @param string $username
 */
function _unl_get_user_audit_content($username) {
  if (user_is_anonymous()) {
    return array();
  }

  $audit_map = array();

  foreach (unl_get_site_user_map('username', $username) as $site_id => $site) {
    $audit_map[] = array(
      'data' => l($site['uri'], $site['uri']),
      'children' => $site['roles'],
    );
  }

  if (count($audit_map) > 0) {
    $content = array(
      '#theme' => 'item_list',
      '#type'  => 'ul',
      '#items' => $audit_map,
    );
    if ($username == $GLOBALS['user']->name) {
      $content['#title'] = 'You belong to the following sites as a member of the listed roles.';
    } else {
      $content['#title'] = 'The user "' . $username . '" belongs to the following sites as a member of the listed roles.';
    }
  } else {
    $content = array(
      '#type' => 'item',
    );
    if ($username == $GLOBALS['user']->name) {
      $content['#title'] = 'You do not belong to any roles on any sites.';
    } else {
      $content['#title'] = 'The user "' . $username . '" does not belong to any roles on any sites.';
    }
  }

  return $content;
}

/**
 * Submit handler for unl_user_audit form.
 * Simply tells the form to rebuild itself with the user supplied data.
 */
function unl_user_audit_submit($form, &$form_state) {
  $form_state['rebuild'] = TRUE;
}

function theme_unl_table($variables) {
  $form = $variables['form'];
  foreach (element_children($form['rows']) as $row_index) {
    foreach (element_children($form['rows'][$row_index]) as $column_index) {
      $form['#rows'][$row_index][$column_index] = drupal_render($form['rows'][$row_index][$column_index]);
    }
  }

  return theme('table', $form);
}

/**
 * Callback for URI admin/sites/unl/feed
 */
function unl_sites_feed() {
  $data = unl_get_site_user_map('role', 'Site Admin', TRUE);

  header('Content-type: application/json');
  echo json_encode($data);
}

/**
 * Returns an array of lists of either roles a user belongs to or users belonging to a role.
 * Each key is the URI of a site and the value is the list.
 * If $list_empty_sites is set to TRUE, all sites will be listed, even if they have empty lists.
 *
 * @param string $search_by (Either 'username' or 'role')
 * @param mixed $username_or_role
 * @param bool $list_empty_sites
 * @throws Exception
 */
function unl_get_site_user_map($search_by, $username_or_role, $list_empty_sites = FALSE) {
  if (!in_array($search_by, array('username', 'role'))) {
    throw new Exception('Invalid argument for $search_by');
  }

  $sites = db_select('unl_sites', 's')
    ->fields('s', array('site_id', 'db_prefix', 'installed', 'site_path', 'uri'))
    ->execute()
    ->fetchAll();

  $audit_map = array();
  foreach ($sites as $site) {
    $shared_prefix = unl_get_shared_db_prefix();
    $prefix = $site->db_prefix;

    try {
      $site_settings = unl_get_site_settings($site->uri);
      $site_db_config = $site_settings['databases']['default']['default'];
      $roles_are_shared = is_array($site_db_config['prefix']) && array_key_exists('role', $site_db_config['prefix']);

      /*
      // If the site uses shared roles, ignore it if the user wants us to.
      if ($roles_are_shared && $form_state['values']['ignore_shared_roles']) {
        continue;
      }
      */

      $bound_params = array();
      $where = array();

      if ($search_by == 'username') {
        $return_label = 'roles';
        $select = 'r.name';
        $where[] = 'u.name = :name';
        $bound_params[':name'] = $username_or_role;
      }
      else {
        $return_label = 'users';
        $select = 'u.name';
        $where[] = 'r.name = :role';
        $bound_params[':role'] = $username_or_role;
      }

      $query = "SELECT {$select} "
             . "FROM {$prefix}_{$shared_prefix}users AS u "
             . "JOIN {$prefix}_{$shared_prefix}users_roles AS m "
             . "  ON u.uid = m.uid "
             . 'JOIN ' . ($roles_are_shared ? '' : $prefix . '_') . $shared_prefix . 'role AS r '
             . "  ON m.rid = r.rid ";

      if (count($where) > 0) {
        $query .= 'WHERE ' . implode(' AND ', $where) . ' ';
      }

      $role_names = db_query($query, $bound_params)->fetchCol();

      if (count($role_names) == 0 && !$list_empty_sites) {
        continue;
      }

      $primary_base_url = unl_site_variable_get($prefix, 'unl_primary_base_url');
      if ($primary_base_url) {
        $uri = $primary_base_url;
      }
      else {
        $uri = $site->uri;
      }
      $audit_map[$site->site_id] = array(
        'uri' => $uri,
        $return_label => $role_names,
      );
    } catch (Exception $e) {
      // Either the site has no settings.php or the db_prefix is wrong.
      drupal_set_message('Error querying database for site ' . $site->uri, 'warning');
    }
  }

  return $audit_map;
}