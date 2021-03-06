<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Internal library functions for bongo
 *
 * The interface between the Moodle core and the plugin is defined here for the most plugin types.
 * The expected contents of the file depends on the particular plugin type.
 *
 * Moodle core often (but not always) loads all the lib.php files of the given plugin types. For the performance
 * reasons, it is strongly recommended to keep this file as small as possible and have just required code implemented
 * in it. All the plugin's internal logic should be implemented in the auto-loaded classes or in the locallib.php file.
 *
 * Call the Bongo REST API to create new institution and set up LTI consumer
 *
 *
 *
 * File         locallib.php
 * Encoding     UTF-8
 *
 * @copyright   Bongo
 * @package     local_bongo
 * @author      Brian Kelly <brian.kelly@bongolearn.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/local/bongo/classes/localbongoconstants.php');
require_once($CFG->libdir.'/filelib.php');


/**
 * Creates an array of Bongo regions to show to the user on the configuration page
 *
 * @return array
 */
function local_bongo_regions() {
    $naregion = new stdClass();
    $naregion->translated_name = get_string('bongona', 'local_bongo');
    $naregion->value = localbongoconstants::LOCAL_BONGO_REGION_NA;
    $naregion->is_default = 1;

    $saregion = new stdClass();
    $saregion->translated_name = get_string('bongosa', 'local_bongo');
    $saregion->value = localbongoconstants::LOCAL_BONGO_REGION_SA;
    $saregion->is_default = 0;

    $caregion = new stdClass();
    $caregion->translated_name = get_string('bongoca', 'local_bongo');
    $caregion->value = localbongoconstants::LOCAL_BONGO_REGION_CA;
    $caregion->is_default = 0;

    $euregion = new stdClass();
    $euregion->translated_name = get_string('bongoeu', 'local_bongo');
    $euregion->value = localbongoconstants::LOCAL_BONGO_REGION_EU;
    $euregion->is_default = 0;

    $auregion = new stdClass();
    $auregion->translated_name = get_string('bongoau', 'local_bongo');
    $auregion->value = localbongoconstants::LOCAL_BONGO_REGION_AU;
    $auregion->is_default = 0;
    return array(
        $naregion,
        $saregion,
        $caregion,
        $euregion,
        $auregion
    );
}

/**
 * Set up everything necessary to connect to Bongo
 * - Create Course
 * - Create LTI Type (Connector on Moodle side)
 * - Create activity in Course
 * - Register with Bongo
 * - Create Activity in Course and attach Activity to LTI Type
 *
 * @param stdClass $requestobject
 * @return stdClass
 */
function local_bongo_set_up_bongo($requestobject) {
    $courseid = local_bongo_create_mod_course();
    $coursesection = local_bongo_get_course_section_id($courseid);
    $ltimoduleid = local_bongo_get_lti_module_id();

    // Bongo will need the ID of the course that was created for linking.
    $requestobject->course_id = $courseid;

    // Format and execute rest call to Bongo to register.
    $parsedresponse = local_bongo_register_with_bongo($requestobject);
    if ($parsedresponse->errorexists == false && !is_null($parsedresponse->url)) {
        $ltitypeid = local_bongo_create_lti_tool($parsedresponse->secret, $parsedresponse->ltikey, $parsedresponse->url);
        $coursemoduleid = local_bongo_create_course_module($courseid, $coursesection, $ltitypeid, $ltimoduleid);
        $parsedresponse->lti_type_id = $ltitypeid;
        $parsedresponse->module_id = $coursemoduleid;
        $parsedresponse->course_id = $courseid;
    }

    return $parsedresponse;
}

/**
 * Format a rest request and send to Bongo for registration
 *
 * @param stdClass $requestobject
 * @return stdClass Bongo's response, parsed to extract errors, key, secret, url and any other messages for the Bongo plugin
 */
function local_bongo_register_with_bongo($requestobject) {
    $bongoconfig = get_config('local_bongo');
    $siteconfig = get_config('');

    $array = array(
        localbongoconstants::LOCAL_BONGO_NAME => $requestobject->name,
        localbongoconstants::LOCAL_BONGO_REGION => $requestobject->region,
        localbongoconstants::LOCAL_BONGO_ACCESS_CODE => $requestobject->access_code,
        localbongoconstants::LOCAL_BONGO_CUSTOMER_EMAIL => $requestobject->customer_email,
        localbongoconstants::LOCAL_BONGO_LMS_CODE => $requestobject->course_id,
        localbongoconstants::LOCAL_BONGO_VERSION => $bongoconfig->version,
        // We collect site information so we can troubleshoot more easily without bothering the customer.
        // For details on their system.
        localbongoconstants::LOCAL_BONGO_MOODLE_VERSION => $siteconfig->version,
        localbongoconstants::LOCAL_BONGO_MOODLE_DB_TYPE => $siteconfig->dbtype,
        localbongoconstants::LOCAL_BONGO_MOODLE_DIR_ROOT => $siteconfig->dirroot,
        localbongoconstants::LOCAL_BONGO_REST_CALL_TYPE => localbongoconstants::LOCAL_BONGO_REST_CALL_TYPE_INSTALL
    );

    $resultresponse = local_bongo_execute_rest_call(localbongoconstants::LOCAL_BONGO_MOODLE_LAMBDA_ADDRESS, json_encode($array));
    $parsedresponse = local_bongo_parse_response($resultresponse);

    $errorresponse = local_bongo_handle_rest_errors($parsedresponse);
    $parsedresponse->errorexists = $errorresponse->errorexists;
    $parsedresponse->errormessage = $errorresponse->errormessage;

    return $parsedresponse;
}

/**
 * Curl call to supplied address using the supplied post fields
 *
 * @param string $urladdress
 * @param string $postfields
 * @return stdClass
 */
function local_bongo_execute_rest_call($urladdress, $postfields) {
    $curl = new curl();

    $headers = array();
    $headers[] = 'Content-Type: text/plain';
    $headers[] = 'Accept-Content: application/json';

    $curl->setHeader($headers);
    $curlresponse = $curl->post($urladdress, $postfields);
    if ($curl->get_errno() != 0) {
        $message = array('errors' => get_string('bongoresterror', 'local_bongo'));
        $curlresponse = json_encode($message);
    }

    return $curlresponse;
}

/**
 * Parse the JSON response that came from the curl call
 *
 * @param string $jsonresult
 * @return stdClass
 */
function local_bongo_parse_response($jsonresult) {
    $jsonresponse = json_decode($jsonresult, true, 512);
    $errorsexist = array_key_exists('errors', $jsonresponse);
    $dataexists = array_key_exists('data', $jsonresponse);

    $secret = null;
    $ltikey = null;
    $url = null;
    $region = null;
    $code = null;
    $message = null;

    if ($dataexists) {
        $body = $jsonresponse['data'];
        $code = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_CODE, $body) ? $body[localbongoconstants::LOCAL_BONGO_CODE] : null);
        $message = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_MESSAGE, $body) ? $body[localbongoconstants::LOCAL_BONGO_MESSAGE] : null);
        $secret = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_SECRET, $body) ? $body[localbongoconstants::LOCAL_BONGO_SECRET] : null);
        $ltikey = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_KEY, $body) ? $body[localbongoconstants::LOCAL_BONGO_KEY] : null);
        $url = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_URL, $body) ? $body[localbongoconstants::LOCAL_BONGO_URL] : null);
        $region = (array_key_exists(
            localbongoconstants::LOCAL_BONGO_REGION, $body) ? $body[localbongoconstants::LOCAL_BONGO_REGION] : null);
    }
    if ($errorsexist) {
        $message = $jsonresponse['errors'];
    }

    $parsedresponse = new stdClass();
    $parsedresponse->secret = $secret;
    $parsedresponse->ltikey = $ltikey;
    $parsedresponse->url = $url;
    $parsedresponse->region = $region;
    $parsedresponse->message = $message;
    $parsedresponse->code = $code;

    return $parsedresponse;
}

/**
 * Creates the Moodle LTI Type (External Tool)
 *
 * @param string $secret
 * @param string $key
 * @param string $url
 * @return int id
 */
function local_bongo_create_lti_tool($secret, $key, $url) {
    global $DB;

    //If the tool exists in bongo config, use it.
    $bongo_config = get_config('lti_type_id', 'local_bongo');
    if($bongo_config != NULL){
        return $bongo_config;
    }

    // If the lti tool has already been inserted, use the previous one.
    $where = $DB->sql_compare_text('icon') ." = ?";
    $ltitype = $DB->get_record_select('lti_types', $where, array(localbongoconstants::LOCAL_BONGO_FAVICON_URL));
    if (!empty($ltitype)) {
        $ltitype->lti_resourcekey = $key;
        $ltitype->lti_password = $secret;
        $id = $DB->update_record('lti_types', $ltitype);
        return $id;
    }

    $config = local_bongo_create_lti_type_config($url, $key, $secret);

    $type = new \stdClass();
    $type->state = LTI_TOOL_STATE_CONFIGURED;

    lti_add_type($type, $config);
    $ltitype = $DB->get_record_select('lti_types', $where, array(localbongoconstants::LOCAL_BONGO_FAVICON_URL));
    $id = $ltitype->id;

    return $id;
}

/**
 * Build the object necessary to insert the Bongo LTI type into the database
 *
 * @param String $url URL of the Bongo LTI endpoint
 * @param String $key unique key to identify Moodle installation
 * @param String $secret unique secret to authenticate to Bongo
 * @return stdClass database-ready object containing necessary fields for persisting
 */
function local_bongo_create_lti_type_config($url, $key, $secret) {
    // Create built in LTI tool.
    $config = new \stdClass();
    $config->lti_toolurl = $url;
    $config->lti_typename = get_string('pluginname', 'local_bongo');
    $config->lti_description = get_string('plugindescription', 'local_bongo');
    $config->lti_coursevisible = 2;
    $config->lti_icon = localbongoconstants::LOCAL_BONGO_FAVICON_URL;
    $config->lti_secureicon = localbongoconstants::LOCAL_BONGO_FAVICON_URL;
    $config->lti_state = 1;
    $config->lti_resourcekey = $key;
    $config->lti_password = $secret;

    // LTI Types Config.
    $config->lti_sendname = 1;
    $config->lti_sendemailaddr = 1;
    $config->lti_acceptgrades = 1;
    $config->lti_launchcontainer = 3;

    return $config;
}

/**
 * Gets the internal id of "lti" from the modules table
 *
 * @return int id
 */
function local_bongo_get_lti_module_id() {
    global $DB;
    $module = $DB->get_record('modules', array('name' => 'lti'));

    return $module->id;
}

/**
 * Creates a course to be used as an example of using Bongo
 *
 * @return int
 */
function local_bongo_create_mod_course() {
    global $DB;

    // If the course has already been inserted, use the previous one.
    $courseid = local_bongo_get_bongo_course();
    if (!is_null($courseid)) {
        return $courseid;
    }

    $categoryid = local_bongo_find_or_create_course_category();
    $config = local_bongo_create_course_object($categoryid);

    create_course($config);
    $courseid = local_bongo_get_bongo_course();

    return $courseid;
}

/**
 * Finds the Bongo course if it has already been inserted, otherwise nothing.
 *
 * @return int|null
 */
function local_bongo_get_bongo_course() {
    global $DB;

    $where = $DB->sql_compare_text('summary') ." = ?";
    $course = $DB->get_record_select('course', $where, array(localbongoconstants::LOCAL_BONGO_MAIN_URL));
    if (!empty($course)) {
        return $course->id;
    }

    return null;
}

/**
 * Search for the default Miscellaneous course category.
 *
 * If it is there, use it.
 * If it's not there, create it and use it.
 * If you can't create it, use the first category available.
 *
 * @return mixed
 */
function local_bongo_find_or_create_course_category() {
    global $DB;

    // Use the Miscellaneous category by default.
    $category = $DB->get_record('course_categories', array('name' => get_string('miscellaneous')));
    if ($category) {
        return $category->id;
    }

    // If there were no categories, create the Miscellaneous category.
    $category = coursecat::create(array('name' => get_string('miscellaneous')));
    if ($category) {
        return $category->id;
    }

    // If Miscellaneous was not there, use the first category.
    // This is the "catch all" case and should realistically never be hit.
    $categories = $DB->get_records('course_categories');
    foreach ($categories as $category) {
        return $category->id;
    }

    // This should never be hit.
    return -1;
}

/**
 * Create a simple object containing default information for creating a new course.
 *
 * @param integer $categoryid
 * @return stdClass
 */
function local_bongo_create_course_object($categoryid) {
    $config = new stdClass();
    $config->fullname = get_string('bongoexamplecourse', 'local_bongo');
    $config->shortname = get_string('bongoexamplecourse', 'local_bongo');
    $config->summary = localbongoconstants::LOCAL_BONGO_MAIN_URL;
    $config->category = $categoryid;
    $config->startdate = time();
    $config->timecreated = time();
    $config->timemodified = time();

    return $config;
}

/**
 * Go through all of the sections of the default Bongo example course and use the last section id.
 *
 * @param int $courseid
 * @return int section id
 */
function local_bongo_get_course_section_id($courseid) {
    global $DB;

    $id = $DB->get_field_select('course_sections', 'MAX(id) as id', 'course= ?', [$courseid]);
    if (!$id) {
        $id = -1;
    }

    return $id;
}

/**
 * Create Activity inside of new course so we can launch directly into Bongo in the example
 *
 * @param int $courseid
 * @param int $sectionid
 * @param int $ltitypeid
 * @param int $ltimoduleid
 * @return int coursemodule id
 */
function local_bongo_create_course_module($courseid, $sectionid, $ltitypeid, $ltimoduleid) {
    $moduleinfo = local_bongo_create_course_module_object($ltitypeid, $courseid, $sectionid, $ltimoduleid);

    $course = new stdClass();
    $course->id = $courseid;
    $coursemodule = add_moduleinfo($moduleinfo, $course);

    return $coursemodule->coursemodule;
}

/**
 * Create a database persist-ready object to be passed into
 *
 * @param int $ltitypeid database id of the lti type that was created
 * @param int $courseid database id of the course that was created
 * @param int $sectionid database id of the course section that was created
 * @param int $ltimoduleid database id of the lti module that was created
 * @return stdClass database persist-ready object
 */
function local_bongo_create_course_module_object($ltitypeid, $courseid, $sectionid, $ltimoduleid) {
    // Module test values.
    $moduleinfo = new stdClass();

    // Always mandatory generic values to any module.
    $moduleinfo->name = get_string('bongoactivity', 'local_bongo');;
    $moduleinfo->showdescription = 0;
    $moduleinfo->showtitlelaunch = 1;
    $moduleinfo->typeid = $ltitypeid;
    $moduleinfo->urlmatchedtypeid = $ltitypeid;
    $moduleinfo->launchcontainer = 1;
    $moduleinfo->instructorchoicesendname = 1;
    $moduleinfo->instructorchoicesendemailaddr = 1;
    $moduleinfo->instructorchoiceacceptgrades = 1;
    $moduleinfo->grade = 100;
    $moduleinfo->visible = true;
    $moduleinfo->visibleoncoursepage = true;
    $moduleinfo->course = $courseid;
    $moduleinfo->section = $sectionid; // This is the section number in the course. Not the section id in the database.
    $moduleinfo->module = $ltimoduleid;
    $moduleinfo->modulename = localbongoconstants::LOCAL_BONGO_LTI;
    $moduleinfo->instance = $ltitypeid;
    $moduleinfo->add = localbongoconstants::LOCAL_BONGO_LTI;
    $moduleinfo->update = 0;
    $moduleinfo->return = 0;

    return $moduleinfo;
}

/**
 * Notify Bongo that plugin was uninstalled.
 *
 * This allows Bongo to cleanup unwanted installations and de-provision them.
 */
function local_bongo_unregister_bongo_integration() {
    $bongoconfig = get_config('local_bongo');
    $siteconfig = get_config('');

    if ($bongoconfig == NULL || is_null($bongoconfig) || is_null($bongoconfig->name)) {
        return;
    }

    $array = array(
        localbongoconstants::LOCAL_BONGO_NAME => $bongoconfig->name,
        localbongoconstants::LOCAL_BONGO_KEY => $bongoconfig->ltikey,
        localbongoconstants::LOCAL_BONGO_SECRET => $bongoconfig->secret,
        localbongoconstants::LOCAL_BONGO_REGION_NA => $bongoconfig->region,
        localbongoconstants::LOCAL_BONGO_VERSION => $bongoconfig->version,
        // We collect site information so we can troubleshoot more easily without bothering the customer.
        // For details on their system.
        localbongoconstants::LOCAL_BONGO_MOODLE_VERSION => $siteconfig->version,
        localbongoconstants::LOCAL_BONGO_MOODLE_DB_TYPE => $siteconfig->dbtype,
        localbongoconstants::LOCAL_BONGO_MOODLE_DIR_ROOT => $siteconfig->dirroot,
        localbongoconstants::LOCAL_BONGO_REST_CALL_TYPE => localbongoconstants::LOCAL_BONGO_REST_CALL_TYPE_UNINSTALL
    );

    // If the plugin was not configured, don't bother with a rest call.
    if (isset($bongoconfig->key)) {
        $resultresponse = local_bongo_execute_rest_call(
            localbongoconstants::LOCAL_BONGO_MOODLE_LAMBDA_ADDRESS,
            json_encode($array)
        );
    }
}

/**
 * Takes a parsed response object and handles the errors that came back from the REST call
 * @param stdClass $parsedresponse
 * @return stdClass if there was an error in the rest response
 */
function local_bongo_handle_rest_errors($parsedresponse) {
    $moodleerror = new stdClass();
    $errorexists = false;
    $errormessage = false;
    if (!is_null($parsedresponse->message)) {
        $message = $parsedresponse->message;
        switch ($message) {
            case 'Internal server error':
            case 'POST body missing accessCode':
            case 'POST body missing region':
            case 'POST body missing Institution Name':
            case 'POST body missing Class lms code':
            case 'POST body missing timezone':
            case 'No Token': // For contacting Bongo, not the GSS.
            case 'Institution not created':
            case 'Invalid backend':
                $errorexists = true;
                $errormessage = get_string('bongoresterror', 'local_bongo');
                break;
            case 'Invalid accessCode':
                $errorexists = true;
                $errormessage = get_string('bongoresterrorinvalidtoken', 'local_bongo');
                break;
            case 'Expired accessCode':
                $errorexists = true;
                $errormessage = get_string('bongoresterrorexpiredtoken', 'local_bongo');
                break;
            default:
                // No known errors were found! Give a generic error.
                $errorexists = true;
                $errormessage = get_string('bongoresterror', 'local_bongo');
                break;
        }
    } else if (is_null($parsedresponse->url)) {
        $errorexists = true;
        $errormessage = get_string('bongoresterror', 'local_bongo');
    }
    $moodleerror->errorexists = $errorexists;
    $moodleerror->errormessage = $errormessage;

    return $moodleerror;
}

/**
 * Failure case where the plugin failed to log its config data but correctly set up the lti information.
 *
 * @param int $courseid
 */
function local_bongo_insert_dummy_data($courseid) {
    global $DB;
    $dbobject = new stdClass();
    $dbobject->name = 'Customer Name';
    $dbobject->customer_email = 'customer@example.com';
    $dbobject->access_code = 'bongoaccesscode';
    $dbobject->timezone = date_default_timezone_get();
    $dbobject->region = localbongoconstants::LOCAL_BONGO_REGION_NA;

    // We could probably search the repository for the config that was set before but that is unreliable.
    $dbobject->hostname = '';
    $dbobject->ltikey = '';
    $dbobject->secret = '';
    $dbobject->lti_type_id = 0;
    $dbobject->course = $courseid;

    $DB->insert_record('local_bongo', $dbobject);
}

/**
 * Check whether the Bongo config has been viewed at least once.
 *
 * @return int
 */
function local_bongo_get_bongo_config_viewed() {
    $bongoconfig = get_config('local_bongo');

    if ($bongoconfig == NULL) {
        return FALSE;
    }

    $value = $bongoconfig->config_viewed;

    return $value == 1;
}

/**
 * Check whether the Bongo config has been viewed at least once.
 */
function local_bongo_set_bongo_config_viewed() {
    // Save plugin config.
    set_config('config_viewed', 1, 'local_bongo');
}
