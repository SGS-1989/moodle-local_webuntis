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
 * @package    local_webuntis
 * @copyright  2021 Zentrum für Lernmanagement (www.lernmanagement.at)
 * @author     Robert Schrenk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$confirmed = optional_param('confirmed', 0, PARAM_INT);

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new \moodle_url('/local/webuntis/landinguser.php', array('confirmed' => $confirmed)));
$PAGE->set_title(get_string('landinguser:pagetitle', 'local_webuntis'));
$PAGE->set_heading(get_string('landinguser:pagetitle', 'local_webuntis'));
$PAGE->set_pagelayout('standard');

$PAGE->navbar->add(get_string('landinguser:pagetitle', 'local_webuntis'), $PAGE->url);
$PAGE->requires->css('/local/webuntis/style/main.css');

if (\local_webuntis\locallib::in_iframe() AND get_config('local_webuntis', 'landingexternal')) {
    $url = new \moodle_url('/local/webuntis/landingexternal.php', [ 'url' => $PAGE->url]);
    redirect($url);
}

\local_webuntis\tenant::load();

if ($USERMAP->get_userid() > 0) {
    throw new moodle_exception('exception:already_connected', 'local_webuntis');
}

$enoughdata = $USERMAP->check_data_prior_usercreate();
$canmapnew = get_config('local_webuntis', 'autocreate') && $TENANT->get_autocreate();
$params = [
    'canmapnew' => $canmapnew,
    'canmapcurrent' => (isloggedin() && !isguestuser()) ? 1 : 0,
    'canmapother' => 1,
    'enoughdata' => $enoughdata,
    'userfullname' => \fullname($USER),
    'usermap' => $USERMAP->get_usermap(),
    'wwwroot' => $CFG->wwwroot,
];

if (strlen($params['userfullname']) > 20) {
    $params['userfullname'] = substr($params['userfullname'], 0, 18) . '...';
}

$params['count'] = $params['canmapnew'] + $params['canmapcurrent'] + $params['canmapother'];

switch ($confirmed) {
    case 1: // Create new user.
        if (empty($params['canmapnew'])) {
            throw new \moodle_exception('forbidden');
        }
        // Create new user and store id.
        $u = (object) [
            'confirmed' => 1,
            'deleted' => 0,
            'mnethostid' => 1,
            'username' => $USERMAP->get_username(),
            'firstname' => $USERMAP->get_firstname(),
            'lastname' => $USERMAP->get_lastname(),
            'email' => $USERMAP->get_email(),
            'role' => \local_webuntis\orgmap::convert_role($USERMAP->get_remoteuserrole()),
            'auth' => 'manual',
            'policyagreed' => 0,
        ];
        if (\local_webuntis\locallib::uses_eduvidual()) {
            if (empty($u->email)) {
                require_once("$CFG->dirroot/local/eduvidual/classes/lib_import.php");
                $compiler = new local_eduvidual_lib_import_compiler_user();
                $u = $compiler->compile($u);
            }
            $u->username = $u->email;
        }

        require_once("$CFG->dirroot/user/lib.php");
        $u->id = \user_create_user($u, false, true);
        if (empty($u->id)) {
            throw new \moodle_exception('could not create user');
        }
        $u->idnumber = $u->id;
        $DB->set_field('user', 'idnumber', $u->idnumber, array('id' => $u->id));
        $DB->set_field('user', 'firstaccess', time(), array('id' => $u->id));
        $DB->set_field('user', 'lastaccess', time(), array('id' => $u->id));
        $DB->set_field('user', 'currentlogin', time(), array('id' => $u->id));
        $DB->set_field('user', 'lastlogin', time(), array('id' => $u->id));
        $u->secret = \local_eduvidual\locallib::get_user_secret($u->id);
        \update_internal_user_password($u, $u->secret, false);

        \local_eduvidual\lib_enrol::choose_background($u->id);
        \core\event\user_created::create_from_userid($u->id)->trigger();

        $USERMAP->set_userid($u->id);
        // Ensure we are enrolled in all eduvidual-organisations.
        \local_webuntis\orgmap::map_role_usermap($USERMAP->get_usermap());
        $DB->set_field('local_webuntis_usermap', 'candisconnect', 0, array('id' => $USERMAP->get_id()));
        $url = $TENANT->get_init_url();
        redirect($url);
    break;
    case 2: // Use current user.
        if (empty($params['canmapcurrent'])) {
            throw new \moodle_exception('forbidden');
        }
        if (isloggedin() && !isguestuser()) {
            $USERMAP->set_userid();
            if ($USERMAP->get_userid() == $USER->id) {
                $url = $TENANT->get_init_url();
                redirect($url, get_string('usermap:success', 'local_webuntis'), 0, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                throw new \moodle_exception(get_string('usermap:failed', 'local_webuntis'));
            }
        } else {
            throw new \moodle_exception(get_string('usermap:failed', 'local_webuntis'));
        }
    break;
    case 3: // Use other users.
        if (empty($params['canmapother'])) {
            throw new \moodle_exception('forbidden');
        }
        // Safely logout.
        $url = $TENANT->get_init_url(false, true);
        $url->param('redirect', get_login_url());
        $USERMAP->release();
        require_logout();
        redirect($url);
    break;
}

// In case mapping other user is the only option, redirect automatically.
if ($params['canmapnew'] == 0 && $params['canmapcurrent'] == 0 && $params['canmapother'] == 1) {
    $url = new \moodle_url('/local/webuntis/landinguser.php', array('confirmed' => 3));
    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_webuntis/landinguser', $params);
echo $OUTPUT->footer();
