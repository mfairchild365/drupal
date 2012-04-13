/*****************************************************
 * Install Issues:
 */

Can't create a new site with Drush/UNL Cron if pdo_pgsql is enabled
 * http://gforge.unl.edu/gf/project/wdn_thm_drupal/tracker/?action=TrackerItemEdit&tracker_item_id=987
 * If pdo_pgsql is enabled on the php install that is running drush/unl cron then it will fail without modification.
 * Adding the following junk values for pgsql solves the problem at line 414 (D7.10) of install_run_task inside install.core.inc
      $form_state['values']['pgsql']['username'] = 'xxxx'; //add this
      $form_state['values']['pgsql']['database'] = 'xxxx'; //add this
      drupal_form_submit($function, $form_state); //existing code
      $errors = form_get_errors(); //existing code

/*****************************************************
 * Hacks of Core:
 */

includes/bootstrap.inc
function drupal_settings_initialize()
 * UNL change: include a "global" settings file that applies to all sites.

function conf_path()
 * UNL change: Add $default_domains array support for sites.php to list which domains are ok to use with 'unl.edu.*' site_dirs.
               If no $default_domains array is defined in sites.php, this code will do nothing.

------------------------------------
includes/bootstrap.inc
 * Fix so that drupal_serve_page_from_cache() won't override a cached Vary header.
 * http://drupal.org/node/1321086

------------------------------------
rewrite.php
 * Used to allow public files to be accessed without the sites/<site_dir>/files prefix

------------------------------------
sites/sites.php
 * Added support for $default_domains array. See includes/bootstrap.inc conf_path().

-------------------------------------
sites/example.sites.php
 * Added an example of the $default_domains array.
 * Added the stub record needed for creating site aliases.

------------------------------------
modules/image/image.field.inc
 * theme_image_formatter ignores attributes so classes can't be added to an image in a theme (needed for photo frame)
 * http://drupal.org/node/1025796#comment-4298698
 * http://drupal.org/files/issues/1025796.patch


/*****************************************************
 * Hacks of Contrib modules:
 */

drush/commands/core/drupal/site_install.inc
function drush_core_site_install_version()
 * UNL change! Setting this to FALSE because we don't want them and they're hard coded.

------------------------------------
drush/commands/core/site_install.drush.inc
function drush_core_pre_site_install()
 * UNL change: Inserted a return before code that would otherwise drop the entire database.

------------------------------------
drush/includes/environment.inc
 * Fix so that drush pulls in the correct uri parameter.
 * http://drupal.org/node/1331106

-------------------------------------
form_builder/modules/webform/form_builder_webform.module
 * In form_builder_webform_components_page() load jquery.ui.datepicker.min.js so the Date element will work on a new form that does not have ui.datepicker loaded
 * http://drupal.org/node/1307838

------------------------------------
Add Trigger Support Patch to Workbench Moderation
 * Trigger support not in 7.x-1.1
 * http://drupal.org/files/issues/trigger_support_for_wb_moderation-1079134-23.patch
 *   from http://drupal.org/node/1079134
 * Don't upgrade WB Moderation without first applying this patch unless the new version supports Triggers

Fix broken books in Workbench Moderation
 * mode in workbench_moderation.module in workbench_moderation_node_presave()
 * http://drupal.org/node/1505060

------------------------------------
wysiwyg/editors/js/tinymce-3.js
 * Comment out the part that switches wrappers from table-based to div. We need the original TinyMCE code for the PDW toggle plugin to work

