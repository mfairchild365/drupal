<?php

/**
 * @file
 * Supports file operations including View, Edit, and Delete.
 */

/**
 * Menu callback; view a single file entity.
 */
function file_entity_view_page($file) {
  // @todo Implement granular editorial access: http://drupal.org/node/696970.
  //   In the meantime, protect information about private files from being
  //   discovered by unprivileged users. File IDs are autoincrement, so one can
  //   attempt discovery by trying to access different media/ID paths. See also
  //   media_browser_list(). This logic potentially belongs within
  //   media_access(), but that would require extending that function's
  //   signature to accept a $file paramter, and this is temporary code anyway.
  if (!user_access('administer files') && (file_uri_scheme($file->uri) === 'private')) {
    return MENU_ACCESS_DENIED;
  }

  drupal_set_title($file->filename);

  $uri = entity_uri('file', $file);
  // Set the file path as the canonical URL to prevent duplicate content.
  drupal_add_html_head_link(array('rel' => 'canonical', 'href' => url($uri['path'], $uri['options'])), TRUE);
  // Set the non-aliased path as a default shortlink.
  drupal_add_html_head_link(array('rel' => 'shortlink', 'href' => url($uri['path'], array_merge($uri['options'], array('alias' => TRUE)))), TRUE);

  return file_view($file, 'full');
}

/**
 * Form callback for adding a file via an upload form.
 *
 * This is a multi step form which has 1-3 pages:
 * - Upload file
 * - Choose filetype
 *   If there is only one candidate (based on mimetype) we will skip this step.
 * - Edit fields
 *   Skip this step if there are no fields on this entity type.
 */
function file_entity_add_upload($form, &$form_state, array $options = array()) {
  $step = (isset($form_state['step']) && in_array($form_state['step'], array(1, 2, 3))) ? $form_state['step'] : 1;
  $form['#step'] = $step;
  switch ($step) {
    case 1:
      return file_entity_add_upload_step_upload($form, $form_state, $options);
    case 2:
      return file_entity_add_upload_step_filetype($form, $form_state, $options);
    case 3:
      return file_entity_add_upload_step_fields($form, $form_state, $options);
  }
}

/**
 * Generate form fields for the first step in the add file wizard.
 */
function file_entity_add_upload_step_upload($form, &$form_state, array $options = array()) {
  $form['upload'] = array(
    '#type' => 'managed_file',
    '#title' => t('Upload a new file'),
    '#upload_location' => file_entity_upload_destination_uri($options),
    '#upload_validators' => file_entity_get_upload_validators($options),
    '#progress_indicator' => 'bar',
    '#required' => TRUE,
    '#pre_render' => array('file_managed_file_pre_render', 'file_entity_upload_validators_pre_render'),
    '#default_value' => isset($form_state['storage']['upload']) ? $form_state['storage']['upload'] : NULL,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['next'] = array(
    '#type' => 'submit',
    '#value' => t('Next'),
  );

  form_load_include($form_state, 'inc', 'file_entity', 'file_entity.pages');

  return $form;
}

/**
 * Generate form fields for the second step in the add file wizard.
 */
function file_entity_add_upload_step_filetype($form, &$form_state, array $options = array()) {
  $file = file_load($form_state['storage']['upload']);

  $form['type'] = array(
    '#type' => 'radios',
    '#title' => t('File type'),
    '#options' => file_entity_get_filetype_candidates($file),
    '#default_value' => isset($form_state['storage']['type']) ? $form_state['storage']['type'] : NULL,
    '#required' => TRUE,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['previous'] = array(
    '#type' => 'submit',
    '#value' => t('Previous'),
  );
  $form['actions']['next'] = array(
    '#type' => 'submit',
    '#value' => t('Next'),
  );

  return $form;
}

/**
 * Generate form fields for the third step in the add file wizard.
 */
function file_entity_add_upload_step_fields($form, &$form_state, array $options = array()) {
  // Load the file and overwrite the filetype set on the previous screen.
  $file = file_load($form_state['storage']['upload']);
  $file->type = $form_state['storage']['type'];

  // Add fields.
  field_attach_form('file', $file, $form, $form_state);

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['previous'] = array(
    '#type' => 'submit',
    '#value' => t('Previous'),
  );
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
  );

  return $form;
}

/**
 * Page callback to show file usage information.
 */
function file_entity_usage_page($file) {
  $rows = array();
  $occured_entities = array();

  foreach (file_usage_list($file) as $module => $usage) {
    $info = system_get_info('module', $module);

    // There are cases, where actual entitiy doesen't exist. We have to handle this.
    foreach ($usage as $entity_type => $entity_ids) {
      $entity_info = entity_get_info($entity_type);
      $entities = empty($entity_info) ? NULL : entity_load($entity_type, array_keys($entity_ids));

      foreach ($entity_ids as $entity_id => $count) {
        // If some other module already added this entity just sum all counts.
        if (isset($occured_entities[$entity_type][$entity_id])) {
          $rows[$occured_entities[$entity_type][$entity_id]][2] += $count;
          continue;
        }

        $label = empty($entities[$entity_id]) ? $module : entity_label($entity_type, $entities[$entity_id]);
        $entity_uri = empty($entities[$entity_id]) ? NULL : entity_uri($entity_type, $entities[$entity_id]);

        // Some entities do not have URL.
        if (empty($entity_uri)) {
          $rows[] = array($entity_type, check_plain($label), $count);
        }
        else {
          $uri = $entity_uri['path'];
          $rows[] = array($entity_type, l($label, $uri), $count);
        }

        $occured_entities[$entity_type][$entity_id] = count($rows) - 1;
      }
    }
  }
  $header[] = array(
    'data' => t('Type'),
  );
  $header[] = array(
    'data' => t('Title'),
  );
  $header[] = array(
    'data' => t('Count'),
  );
  $build['usage_table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#caption' => t('This table lists all of the places where @filename is used.',
    array('@filename' => $file->filename)),
    '#empty' => t('This file is not currently used.'),
  );
  return $build;
}

/**
 * Get the candidate filetypes for a given file.
 *
 * Only filetypes for which the user has access to create entities are returned.
 *
 * @param array $file
 *   An upload file array from form_state.
 *
 * @return array
 *   An array of file type bundles that support the file's mime type.
 */
function file_entity_get_filetype_candidates($file) {
  $types = module_invoke_all('file_type', $file);
  drupal_alter('file_type', $types, $file);
  $candidates = array();
  foreach ($types as $type) {
    $file->type = $type;
    if (file_entity_access('create', $file)) {
      $candidates[$type] = file_entity_type_get_name($file);
    }
  }
  return $candidates;
}

/**
 * Submit handler for the add file form.
 */
function file_entity_add_upload_submit($form, &$form_state) {
  $form_state['storage'] = isset($form_state['storage']) ? $form_state['storage'] : array();
  $form_state['storage'] = array_merge($form_state['storage'], $form_state['values']);

  // This var is set to TRUE when we are ready to save the file.
  $save = FALSE;
  $trigger = $form_state['triggering_element']['#id'];

  // We have the file, check if we can skip step 2.
  // The next step is step 2 when we are on step 1 and clicking "next" or
  // when we are on step 3 and clicking "previous".
  if (($form['#step'] == 1 && $trigger == 'edit-next') || ($form['#step'] == 3 && $trigger == 'edit-previous')) {
    $file = file_load($form_state['storage']['upload']);
    $candidates = file_entity_get_filetype_candidates($file);
    if (count($candidates) == 1) {
      $candidates_keys = array_keys($candidates);
      // There is only one possible filetype for this file. Skip the second page.
      $form['#step'] += ($trigger == 'edit-previous') ? -1 : 1;
      $form_state['storage']['type'] = reset($candidates_keys);
    }
  }

  // We have the filetype, check if we can skip step 3.
  if (($form['#step'] == 2 && $trigger == 'edit-next')) {
    $file = file_load($form_state['storage']['upload']);
    if (!field_info_instances('file', $form_state['storage']['type'])) {
      // This filetype doesn't have fields, save the file.
      $save = TRUE;
    }
  }

  switch ($trigger) {
    case 'edit-next':
      $form_state['step'] = $form['#step'] + 1;
      break;
    case 'edit-previous':
      $form_state['step'] = $form['#step'] - 1;
      break;
    case 'edit-submit':
      $save = TRUE;
      break;
  }

  if ($save) {
    $file = file_load($form_state['storage']['upload']);
    if ($file) {
      $file->type = $form_state['storage']['type'];
      $file->display = TRUE;

      // Change the file from temporary to permanent.
      $file->status = FILE_STATUS_PERMANENT;

      // Save the form fields.
      // Keep in mind that the values for the Field API fields must be in
      // $form_state['values'] and not in ['storage']. This is true as long as
      // the fields are on the last page of the multi step form.
      entity_form_submit_build_entity('file', $file, $form, $form_state);

      file_save($file);
      $form_state['file'] = $file;
      drupal_set_message(t('The file @name was uploaded.', array('@name' => $file->filename)));
    }
    else {
      drupal_set_message(t('An error occurred and no file was uploaded.'), 'error');
      return;
    }

    // Figure out destination.
    if (isset($_GET['destination'])) {
      $destination = drupal_get_destination();
      unset($_GET['destination']);
    }
    elseif (user_access('administer files')) {
      $destination = array('destination' => 'admin/content/file');
    }
    else {
      $destination = array('destination' => 'file/' . $file->fid);
    }
    $form_state['redirect'] = $destination['destination'];
  }
  else {
    $form_state['rebuild'] = TRUE;
  }
}

/**
 * Determines the upload location for the file add upload form.
 *
 * @param array $params
 *   An array of parameters from the media browser.
 * @param array $data
 *   (optional) An array of token objects to pass to token_replace().
 *
 * @return
 *   A file directory URI with tokens replaced.
 *
 * @see token_replace()
 */
function file_entity_upload_destination_uri(array $params, array $data = array()) {
  $params += array(
    'uri_scheme' => file_default_scheme(),
    'file_directory' => '',
  );

  $destination = trim($params['file_directory'], '/');

  // Replace tokens.
  $destination = token_replace($destination, $data);

  return $params['uri_scheme'] . '://' . $destination;
}

function file_entity_add_upload_multiple($form, &$form_state, $params = array()) {
  $form = file_entity_add_upload($form, $form_state, $params);
  unset($form['upload']['#title']);
  // The validators will be set from plupload anyway.  This isn't pretty, but don't
  // it to show up twice.
  unset($form['upload']['#description']);

  $form['upload']['#type'] = 'plupload';
  $form['submit']['#value'] = t('Start upload');
  return $form;
}

function file_entity_add_upload_multiple_submit($form, &$form_state) {
  $upload_location = !empty($form['upload']['#upload_location']) ?
    $form['upload']['#upload_location'] . '/' :
    variable_get('file_default_scheme', 'public') . '://';

  // We can't use file_save_upload() because of http://www.jacobsingh.name/content/tight-coupling-no-not
  foreach ($form_state['values']['upload'] as $uploaded_file) {
    if ($uploaded_file['status'] == 'done') {
      $source = $uploaded_file['tmppath'];
      $destination = file_stream_wrapper_uri_normalize($upload_location . $uploaded_file['name']);
      // Rename it to its original name, and put it in its final home.
      // Note - not using file_move here because if we call file_get_mime
      // (in file_uri_to_object) while it has a .tmp extension, it horks.

      $destination = file_unmanaged_move($source, $destination, FILE_EXISTS_RENAME);

      $file = file_uri_to_object($destination);
      $file->status = FILE_STATUS_PERMANENT;
      file_save($file);

      $saved_files[] = $file;
      $form_state['files'][$file->fid] = $file;
    }
    else {
      // @todo: move this to element validate or something.
      form_set_error('pud', t('The specified file %name could not be uploaded.', array('%name' => $uploaded_file['name'])));
    }
  }

  // Redirect to the file edit page.
  if (file_entity_access('update', $file) && module_exists('multiform')) {
    $destination = array('destination' => 'admin/content/file');
    if (isset($_GET['destination'])) {
      $destination = drupal_get_destination();
      unset($_GET['destination']);
    }
    $form_state['redirect'] = array('admin/content/file/edit-multiple/' . implode(' ', array_keys($form_state['files'])), array('query' => $destination));
  }
  else {
    $form_state['redirect'] = 'admin/content/file';
  }
}

/**
 * Page callback: Form constructor for the file edit form.
 *
 * Path: file/%file/edit
 *
 * @param object $file
 *   A file object from file_load().
 *
 * @see file_entity_menu()
 *
 * @todo Rename this form to file_edit_form to ease into core.
 */
function file_entity_edit($form, &$form_state, $file) {
  drupal_set_title(t('<em>Edit @type</em> @title', array('@type' => $file->type, '@title' => $file->filename)), PASS_THROUGH);

  $form_state['file'] = $file;

  $form['#attributes']['class'][] = 'file-form';
  if (!empty($file->type)) {
    $form['#attributes']['class'][] = 'file-' . $file->type . '-form';
  }

  // Basic file information.
  // These elements are just values so they are not even sent to the client.
  foreach (array('fid', 'type', 'uid', 'timestamp') as $key) {
    $form[$key] = array(
      '#type' => 'value',
      '#value' => isset($file->$key) ? $file->$key : NULL,
    );
  }

  $form['filename'] = array(
    '#type' => 'textfield',
    '#title' => t('Name'),
    '#default_value' => $file->filename,
    '#required' => TRUE,
    '#maxlength' => 255,
    '#weight' => -10,
  );

  // Add a 'replace this file' upload field if the file is a local file only.
  if (file_entity_file_is_local($file)) {
    // Set up replacement file validation.
    $replacement_options = array();
    // The replacement file must have the same extension as the original file.
    $replacement_options['file_extensions'] = pathinfo($file->uri, PATHINFO_EXTENSION);

    $form['replace_upload'] = array(
      '#type' => 'file',
      '#title' => t('Replace file'),
      '#description' => t('This file will replace the existing file. This action cannot be undone.'),
      '#upload_validators' => file_entity_get_upload_validators($replacement_options),
      '#pre_render' => array('file_entity_upload_validators_pre_render'),
    );
  }

  $form['preview'] = file_view_file($file, 'preview');

  // Add the buttons.
  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
    '#weight' => 5,
    '#submit' => array('file_entity_edit_submit'),
  );
  $form['actions']['delete'] = array(
    '#type' => 'submit',
    '#value' => t('Delete'),
    '#weight' => 10,
    '#submit' => array('file_entity_edit_delete_submit'),
  );
  $form['actions']['cancel'] = array(
    '#type' => 'link',
    '#title' => t('Cancel'),
    '#href' => isset($_GET['destination']) ? $_GET['destination'] : 'file/' . $file->fid,
    '#weight' => 15,
  );

  $langcode = function_exists('entity_language') ? entity_language('file', $file) : NULL;
  field_attach_form('file', $file, $form, $form_state, $langcode);

  return $form;
}

/**
 * Form validation handler for file_entity_edit().
 */
function file_entity_edit_validate($form, &$form_state) {
  // Handle the replacement file if uploaded.
  if (isset($form_state['values']['replace_upload'])) {
    // Save the file as a temporary file.
    $file = file_save_upload('replace_upload', $form['replace_upload']['#upload_validators']);
    if (!empty($file)) {
      // Put the temporary file in form_values so we can save it on submit.
      $form_state['values']['replace_upload'] = $file;
    }
    elseif ($file === FALSE) {
      // File uploaded failed.
      form_set_error('replace_upload', t('The replacement file could not be uploaded.'));
    }
  }

  // Run entity form validation.
  entity_form_field_validate('file', $form, $form_state);
}

/**
 * Form submission handler for the 'Save' button for file_entity_edit().
 */
function file_entity_edit_submit($form, &$form_state) {
  $file = $form_state['file'];

  // Check if a replacement file has been uploaded.
  if (!empty($form_state['values']['replace_upload'])) {
    $replacement = $form_state['values']['replace_upload'];
    // Move file from temp to permanent home.
    file_unmanaged_copy($replacement->uri, $file->uri, FILE_EXISTS_REPLACE);
  }

  // Run entity form submit handling and save the file.
  entity_form_submit_build_entity('file', $file, $form, $form_state);
  file_save($file);

  $args = array(
    '@type' => file_entity_type_get_name($file),
    '%title' => entity_label('file', $file),
  );
  watchdog('file', '@type: updated %title.', $args);
  drupal_set_message(t('@type %title has been updated.', $args));

  $form_state['redirect'] = 'file/' . $file->fid;
}

/**
 * Form submission handler for the 'Delete' button for file_entity_edit().
 */
function file_entity_edit_delete_submit($form, &$form_state) {
  $fid = $form_state['values']['fid'];
  $destination = array();
  if (isset($_GET['destination'])) {
    $destination = drupal_get_destination();
    unset($_GET['destination']);
  }
  $form_state['redirect'] = array('file/' . $fid . '/delete', array('query' => $destination));
}

/**
 * Page callback: Form constructor for the file deletion confirmation form.
 *
 * Path: file/%file/delete
 *
 * @param object $file
 *   A file object from file_load().
 *
 * @see file_entity_menu()
 */
function file_entity_delete_form($form, &$form_state, $file) {
  $form_state['file'] = $file;

  $form['fid'] = array(
    '#type' => 'value',
    '#value' => $file->fid,
  );

  $description = t('This action cannot be undone.');
  if ($references = file_usage_list($file)) {
    $description .= ' ' . t('This file is currently in use and may cause problems if deleted.');
  }

  return confirm_form($form,
    t('Are you sure you want to delete the file %title?', array(
      '%title' => entity_label('file', $file),
    )),
    'file/' . $file->fid,
    $description,
    t('Delete')
  );
}

/**
 * Form submission handler for file_entity_delete_form().
 */
function file_entity_delete_form_submit($form, &$form_state) {
  if ($form_state['values']['confirm'] && $file = file_load($form_state['values']['fid'])) {
    // Use file_delete_multiple() rather than file_delete() since we want to
    // avoid unwanted validation and usage checking.
    file_delete_multiple(array($file->fid));

    $args = array(
      '@type' => file_entity_type_get_name($file),
      '%title' => entity_label('file', $file),
    );
    watchdog('file', '@type: deleted %title.', $args);
    drupal_set_message(t('@type %title has been deleted.', $args));
  }

  $form_state['redirect'] = '<front>';
}

/**
 * Form constructor for file deletion confirmation form.
 *
 * @param array $files
 *   An array of file objects.
 */
function file_entity_multiple_delete_form($form, &$form_state, array $files) {
  $form['files'] = array(
    '#prefix' => '<ul>',
    '#suffix' => '</ul>',
    '#tree' => TRUE,
  );

  $files_have_usage = FALSE;
  foreach ($files as $fid => $file) {
    $title = entity_label('file', $file);
    $usage = file_usage_list($file);
    if (!empty($usage)) {
      $files_have_usage = TRUE;
      $title = t('@title (in use)', array('@title' => $title));
    }
    else {
      $title = check_plain($title);
    }
    $form['files'][$fid] = array(
      '#type' => 'hidden',
      '#value' => $fid,
      '#prefix' => '<li>',
      '#suffix' => $title . "</li>\n",
    );
  }

  $form['operation'] = array(
    '#type' => 'hidden',
    '#value' => 'delete',
  );

  $description = t('This action cannot be undone.');
  if ($files_have_usage) {
    $description .= ' ' . t('Some of the files are currently in use and may cause problems if deleted.');
  }

  return confirm_form(
    $form,
    format_plural(count($files), 'Are you sure you want to delete this file?', 'Are you sure you want to delete these files?'),
    'admin/content/file',
    $description,
    t('Delete')
  );
}

/**
 * Form submission handler for file_entity_multiple_delete_form().
 */
function file_entity_multiple_delete_form_submit($form, &$form_state) {
  if ($form_state['values']['confirm'] && $fids = array_keys($form_state['values']['files'])) {
    file_delete_multiple($fids);
    $count = count($fids);
    watchdog('file', 'Deleted @count files.', array('@count' => $count));
    drupal_set_message(format_plural($count, 'Deleted one file.', 'Deleted @count files.'));
  }
  $form_state['redirect'] = 'admin/content/file';
}

/**
 * Page callback for the file edit form.
 *
 * @deprecated
 *   Use drupal_get_form('file_entity_edit')
 */
function file_entity_page_edit($file) {
  return drupal_get_form('file_entity_edit', $file);
}

/**
 * Page callback for the file deletion confirmation form.
 *
 * @deprecated
 *   Use drupal_get_form('file_entity_delete_form')
 */
function file_entity_page_delete($file) {
  return drupal_get_form('file_entity_delete_form');
}

/**
 * Retrieves the upload validators for a file.
 *
 * @param array $options
 *   (optional) An array of options for file validation.
 *
 * @return array
 *   An array suitable for passing to file_save_upload() or for a managed_file
 *   or upload element's '#upload_validators' property.
 */
function file_entity_get_upload_validators(array $options = array()) {
  // Set up file upload validators.
  $validators = array();

  // Validate file extensions. If there are no file extensions in $params and
  // there are no Media defaults, there is no file extension validation.
  if (!empty($options['file_extensions'])) {
    $validators['file_validate_extensions'] = array($options['file_extensions']);
  }
  else {
    $validators['file_validate_extensions'] = array(variable_get('file_entity_default_allowed_extensions', 'jpg jpeg gif png txt doc docx xls xlsx pdf ppt pptx pps ppsx odt ods odp'));
  }

  // Validate file size but do not allow anything higher than file_upload_max_size().
  $max_filesize = file_upload_max_size();
  if (!empty($options['max_filesize']) && $options['max_filesize'] < $max_filesize) {
    $validators['file_validate_size'] = array(parse_size($options['max_filesize']));
  }
  else {
    $validators['file_validate_size'] = array($max_filesize);
  }

  // Add image validators.
  $options += array('min_resolution' => 0, 'max_resolution' => 0);
  if ($options['min_resolution'] || $options['max_resolution']) {
    $validators['file_validate_image_resolution'] = array($options['max_resolution'], $options['min_resolution']);
  }

  return $validators;
}
