<?php

class assign_feedback_mahara extends assign_feedback_plugin {

    /**
     * @see parent
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'assignfeedback_mahara');
    }

    /**
     * @see parent
     */
    public function is_empty(stdClass $grade) {
        return false;
    }

    /**
     * @see parent
     */
    public function has_user_summary() {
        return false;
    }

    /**
     * Callback function that is called when the standard grading form is used.
     * @see assign_plugin::save()
     */
    public function save(stdClass $grade, stdClass $formdata = null) {
        $outcomes = $this->process_outcomes_from_form($grade, $formdata);
        return $this->handle_grade_save($grade, $outcomes);
    }

    /**
     * Prepares the release scenario
     *
     * @param stdClass $grade
     * @return Model_Option|null
     */
    private function prepare_release($grade) {
        global $DB;

        $event = new stdClass();
        $event->assignment = $this->assignment;
        $event->grade = $grade;
        $event->submission = $DB->get_record(
            'assign_submission',
            array(
                'assignment' => $this->assignment->get_instance()->id,
                'userid' => $grade->userid,
            )
        );

        $maharasubmissionplugin = $event->assignment->get_submission_plugin_by_type('mahara');

        $maharasubmission = $DB->get_record(
            'assignsubmission_mahara',
            array(
                'assignment' => $this->assignment->get_instance()->id,
                'submission' => $event->submission->id,
            )
        );
        if ($maharasubmission) {
            $event->maharasubmission = $maharasubmission;
            return array($maharasubmissionplugin, $event, $maharasubmission);
        }

        return null;
    }

    /**
     * Completes the common release scenario
     *
     * @param assign_submission_mahara $maharasubmissionplugin
     * @param stdClass $event Object prepared in prepare_release() with data about submission
     * @param stdClass $maharasubmission Record from assignsubmission_mahara table
     * @param array $outcomes
     * @return boolean
     */
    private function complete_release($maharasubmissionplugin, $event, $maharasubmission, $outcomes) {

        if ($maharasubmission->viewstatus == assign_submission_mahara::STATUS_SUBMITTED) {
            // Returns no result
            $maharasubmissionplugin->mnet_release_submited_view(
                $maharasubmission->viewid,
                $outcomes,
                $maharasubmission->iscollection
            );

            if ($maharasubmissionplugin->get_error()) {
                return false;
            } else {
                $maharasubmissionplugin->set_mahara_submission_status($maharasubmission->submission, assign_submission_mahara::STATUS_RELEASED);
            }
        }

        return true;
    }

    /**
     * @see parent
     */
    public function supports_quickgrading() {
        return true;
    }

    /**
     * Callback method called when the quickgrading form is used
     * @see assign_feedback_plugin::save_quickgrading_changes()
     */
    public function save_quickgrading_changes($userid, $grade) {
        $outcomes = $this->process_outcomes_from_quickgrading($grade);
        return $this->handle_grade_save($grade, $outcomes);
    }

    /**
     * Get user grading info
     *
     * @param $grade
     * @return grading_info
     */
    private function get_user_grade_info($grade) {
        return $grading_info = grade_get_grades(
                $this->assignment->get_course()->id,
                'mod',
                'assign',
                $this->assignment->get_instance()->id,
                $grade->userid
        );
    }

    /**
     * Process outcome data from quick grading
     *
     * @param $grade
     * @return array
     */
    private function process_outcomes_from_quickgrading($grade) {
        $grading_info = $this->get_user_grade_info($grade);

        $viewoutcomes = array();
        if (!empty($grading_info->outcomes)) {
            foreach ($grading_info->outcomes as $outcomeid => $outcome) {
                $newoutcome_name = "outcome_{$outcomeid}_{$grade->userid}";
                $oldoutcome = $outcome->grades[$grade->userid]->grade;
                $newoutcome = optional_param($newoutcome_name, -1, PARAM_INT);

                $scale = make_grades_menu(-$outcome->scaleid);
                if ($oldoutcome == $newoutcome || !isset($scale[$newoutcome])) {
                    continue;
                }

                foreach ($scale as $k => $v) {
                    $scale[$k] = array('name' => $v, 'value' => $k);
                }

                $viewoutcomes[] = array(
                        'name' => $outcome->name,
                        'scale' => $scale,
                        'grade' => $newoutcome,
                );
            }
        }

        return $viewoutcomes;
    }

    /**
     * Process outcome data from a form
     *
     * @param $grade
     * @param stdClass $formdata
     * @return array
     */
    private function process_outcomes_from_form($grade, $formdata) {
        $grading_info = $this->get_user_grade_info($grade);
        $viewoutcomes = array();

        if (!empty($grading_info->outcomes)) {
            foreach ($grading_info->outcomes as $index => $outcome) {
                $name = "outcome_$index";
                $oldoutcome = $outcome->grades[$grade->userid]->grade;
                $scale = make_grades_menu(-$outcome->scaleid);

                if (
                        !isset($formdata->{$name}[$grade->userid])
                        || $oldoutcome == $formdata->{$name}[$grade->userid]
                        || !isset($scale[$formdata->{$name}[$grade->userid]])
                ) {
                    continue;
                }

                foreach ($scale as $k => $v) {
                    $scale[$k] = array('name' => $v, 'value' => $k);
                }

                $viewoutcomes[] = array(
                    'name' => $outcome->name,
                    'scale' => $scale,
                    'grade' => $formdata->{$name}[$grade->userid],
                );
            }
        }

        return $viewoutcomes;
    }

    /**
     * Method that responds to a grade save event. It checks whether there is a Moodle page
     * that needs to be released, and if so, releases it.
     *
     * @param stdClass $grade
     * @param unknown_type $outcomefunction
     * @param unknown_type $data
     */
    private function handle_grade_save(stdClass $grade, $outcomes) {
        $release = $this->prepare_release($grade);
        if ($release) {
            list($mahara, $event, $portfolio) = $release;
            return $this->complete_release($mahara, $event, $portfolio, $outcomes);
        }

        return true;
    }
}
