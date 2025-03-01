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
 * Unit tests for tool_driprelease.
 *
 * @package    tool_driprelease
 * @copyright  2022 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_driprelease;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/admin/tool/driprelease/lib.php');

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/***
 * Unit test for the driprelease functionality.
 *
 * @package    tool_driprelease
 * @category   test
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use tool_driprelease;
/**
 * Test the code mainly in the tool_driprelease class
 */
final class driprelease_test extends \advanced_testcase {
    /**
     * Instance containing most of the code
     * @var driprelease
     */
    public $driprelease;

    /**
     * Test course
     *
     * @var \stdClass
     */
    public $course1;

    /**
     * Array of modules
     *
     * @var array
     */
    public $modules;

    /**
     * data to be processed
     * @var \stdClass
     */
    public $dripdata;

    /**
     * data from form submission
     *
     * @var \stdClass
     */
    public $fromform;

    public function setUp(): void {
        parent::setUp();
        global $CFG, $DB;
        $this->driprelease = new driprelease();
         // Create course with availability enabled.
        $CFG->enableavailability = true;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $this->course1 = $course;

        $quizgenerator = $generator->get_plugin_generator('mod_quiz');

        $this->modules[] = $quizgenerator->create_instance(['course' => $course->id,
                'grademethod' => QUIZ_GRADEHIGHEST, 'grade' => 100.0, 'sumgrades' => 10.0,
                'attempts' => 10]);

        $this->modules[] = $quizgenerator->create_instance(['course' => $course->id,
                'grademethod' => QUIZ_GRADEHIGHEST, 'grade' => 100.0, 'sumgrades' => 10.0,
                'attempts' => 10]);

        $this->modules[] = $quizgenerator->create_instance(['course' => $course->id,
                'grademethod' => QUIZ_GRADEHIGHEST, 'grade' => 100.0, 'sumgrades' => 10.0,
                'attempts' => 10]);

        foreach ($this->modules as $module) {
            $activitygroup['activity_'.$module->cmid] = 1;
        }

        $this->fromform = (object) [
            'modtype' => 'quiz',
            'activitiespersession' => 1,
            'sessionlength' => 1, // Sessions last 1 day.
            'activitygroup' => $activitygroup,
            'schedulestart' => mktime(0, 0, 0, 1, 1, 2023), // First Jan.
            'schedulefinish' => mktime(0, 0, 0, 2, 1, 2023), // First Feb.
            'stayavailable' => 0,
            'coursegroup' => 0,
            'hideunselected' => 0,
            'resetunselected' => 0,
            'displaydisabled' => 0,
        ];

        list($selections, $this->dripdata) = $this->driprelease->update($this->fromform , $this->course1->id);
        $this->assertCount(3, $selections);
        $this->dripdata = $DB->get_record('tool_driprelease', ['id' => $this->dripdata->id]);
    }
    /**
     * Confirm course_modules table has been
     * written to
     *
     * @covers \tool_driprelease\driprelease::update_availability()
     */
    public function test_update_availability(): void {
        $this->resetAfterTest();
        global $DB;
        $coursemodules = $DB->get_records('course_modules');
        $cm = reset($coursemodules);
        $this->assertEquals($cm->availability, '');
        $tabledata = $this->driprelease->get_table_data($this->dripdata);
        // Element 0 is a header row.
        $tabledata[1]['selected'] = 1;

        $this->driprelease->update_availability($tabledata, $this->dripdata);
        $coursemodules = $DB->get_records('course_modules');
        $cm = reset($coursemodules);
        $startdate = $this->dripdata->schedulestart;

        // Sessions set to one day in setUp.
        $this->assertStringContainsString($startdate, $cm->availability);

    }

    /**
     * If reset unselected is checked then the availability data
     * for the module will be blanked out
     *
     * @covers \tool_driprelease\driprelease::process_unselected
     *
     * @return void
     */
    public function test_process_unselected(): void {
        $this->resetAfterTest();
        global $DB;
        $modules = $this->driprelease->get_table_data($this->dripdata);
        $module = $modules[1]; // A non header.
        $DB->set_field(
            'course_modules',
            'availability',
            'XXX',
            ['id' => $module['cm']->id]
        );
        $before = $DB->get_record('course_modules', ['id' => $module['cm']->id]);
        $this->assertEquals($before->availability, 'XXX');
        $this->dripdata->resetunselected = 1;
        $this->driprelease->process_unselected($module, $this->dripdata);
        $after = $DB->get_record('course_modules', ['id' => $module['cm']->id]);
        $this->assertEquals($after->availability, '');

    }

    /**
     * Confirm modules/quizzes in a course are returned
     * as expected
     * @covers \tool_driprelease\driprelease::get_modules
     */
    public function test_get_course_modules(): void {
        $this->resetAfterTest();
        $modules = $this->driprelease->get_modules($this->dripdata);
        $modulecount = count($modules);
        $this->assertEquals(count($this->modules), $modulecount);
    }

    /**
     * Confirm modules/quizzes in a course are returned
     * as expected
     *
     *
     * @covers \tool_driprelease\driprelease::get_course_module_types()
     */
    public function test_get_course_module_types(): void {
        $this->resetAfterTest();
        $moduletypes = $this->driprelease->get_course_module_types($this->course1->id);
        $this->assertArrayHasKey('quiz', $moduletypes);
    }
    /**
     * Get the data that will be output by the mustache table
     *
     * @covers \tool_driprelease\driprelease::get_table_data()
     */
    public function test_get_table_data(): void {
        $this->resetAfterTest();
        $tabledata = $this->driprelease->get_table_data($this->dripdata);
        // First row is header row.
        $header = $tabledata[0];
        $this->assertEquals($header['isheader'], true);
        $row1 = $tabledata[1];
        $row2 = $tabledata[3];

        $this->assertEquals($row1['isheader'], false);
        $this->assertEquals($row1['name'], "Quiz 1");
        $this->assertEquals($row1['selected'], "checked");
        $this->assertEquals($row1['questioncount'], 0);

        // Sundays quiz.
        $this->assertEquals(mktime(0, 0, 0, 1, 1, 2023), $row1['calculatedavailability']['start']);
        $this->assertEquals(mktime(0, 0, 0, 1, 1, 2023), $row1['calculatedavailability']['end']);

        // Mondays quiz.
        $this->assertEquals(mktime(0, 0, 0, 1, 2, 2023), $row2['calculatedavailability']['start']);
        $this->assertEquals(mktime(0, 0, 0, 1, 2, 2023), $row2['calculatedavailability']['end']);
    }
    /**
     * Check that update doesn't fall over and
     * selections are set as expected
     *
     * @covers \tool_driprelease\driprelease::update()
     */
    public function test_update_instance(): void {
        $this->resetAfterTest();
        $activitygroup = [];

        foreach ($this->modules as $module) {
            $activitygroup['activity_'.$module->id] = 1;
        }
        $this->fromform->activitygroup = $activitygroup;
        list($selections, $this->dripdata) = $this->driprelease->update($this->fromform , $this->course1->id);
        $this->assertCount(3, $selections);
        $this->assertEquals($this->dripdata->id, $this->dripdata->id);
    }

    /**
     * Check manage_selections adds modules
     * when when they have been selected in
     * the form.
     *
     * @covers \tool_driprelease\driprelease::manage_selections()
     */
    public function test_manage_selections(): void {
        $this->resetAfterTest();
        global $DB;
        $cmids = $DB->get_records('tool_driprelease_cmids');
        // Three records created by setUp.
        $this->assertCount(3, $cmids);
        $this->fromform->activitygroup['activity_101'] = 1;
        $this->driprelease->manage_selections($this->fromform, $this->dripdata->id);
        $cmids = $DB->get_records('tool_driprelease_cmids');
        // Four after manage_selections was called.
        $this->assertCount(4, $cmids);
    }

    /**
     * Check add_header, a row containing
     * The date/time values for the start
     * and end of a activity session,
     * e.g. a weeks worth of quizzes.
     *
     * @covers \tool_driprelease\driprelease::add_header()
     */
    public function test_add_header(): void {
        $this->resetAfterTest();
        $header = $this->driprelease->add_header([]);
        $this->assertEquals(true, $header['isheader']);
        $this->assertEquals('Session', $header['name']);
        $this->assertEquals(-1, $header['cm']->id);
    }

    /**
     * Check get_modules returns items
     * configured in setUp function
     *
     * @covers \tool_driprelease\driprelease::get_modules()
     */
    public function test_get_modules(): void {
        $this->resetAfterTest();
        $cmids = $this->driprelease->get_modules($this->dripdata);
        $this->assertCount(3, $cmids);
    }

    /**
     * Test get_availability with date restrictions
     * @covers \tool_driprelease\driprelease::get_availability()
     */
    public function test_get_availability_with_dates(): void {
        $this->resetAfterTest();

        $this->setTimezone('GMT');
        $json = json_encode([
            'c' => [
                ['type' => 'date', 'd' => '>=', 't' => 1609459200], // 1 Jan 2021 00:00
                ['type' => 'date', 'd' => '<', 't' => 1612137600],  // 1 Feb 2021 00:00
            ],
        ]);
        $expectedoutput = [
            'from' => 'Fri 1 Jan 2021 00:00',
            'to' => 'Mon 1 Feb 2021 00:00',
        ];

        $availability = $this->driprelease->get_availability($json);

        // Assert the output.
        $this->assertSame($expectedoutput, $availability);
    }

    /**
     * Test get_availability with no date restrictions
     * @covers \tool_driprelease\driprelease::get_availability()
     */
    public function test_get_availability_with_no_dates(): void {
        $this->resetAfterTest();

        $json = "";
        $availability = $this->driprelease->get_availability($json);

        // Assert the output.
        $this->assertSame([], $availability);
    }

    /**
     * Test get_availability with null json
     * @covers \tool_driprelease\driprelease::get_availability()
     */
    public function test_get_availability_with_null(): void {
        $this->resetAfterTest();

        $availability = $this->driprelease->get_availability(null);

        // Assert the output.
        $this->assertSame([], $availability);
    }

}
