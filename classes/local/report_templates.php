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

namespace local_reportium\local;

use stdClass;

/**
 * Ready-made drill-down reports (templates) that can be installed on any site.
 *
 * Every template is a list of levels: the SQL of level 0 lists the top items, each next level receives the
 * clicked item as %%PREVIOUSVALUE%% (and any earlier choice as %%LEVELVALUE:n%%), bound as parameters.
 * The SQL is portable (MySQL/MariaDB, PostgreSQL, MSSQL): {table} names, no LIMIT, no date functions.
 * Names, summaries and level titles are language strings (template_<key>_*), so they install in the site language.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_templates {
    /** @var string Period condition of log queries (see with_period()). */
    const PERIOD = 'l.timecreated BETWEEN %%STARTTIME%% AND %%ENDTIME%% AND ';

    /**
     * Template definitions.
     *
     * @return array key => ['levels' => [['sql' => string, 'config' => array], ...]]
     */
    public static function definitions(): array {
        $enrolled = "SELECT e.courseid, COUNT(DISTINCT ue.userid) AS n
                       FROM {enrol} e
                       JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                      WHERE e.status = 0
                   GROUP BY e.courseid";
        $completed = "SELECT course, COUNT(1) AS n
                        FROM {course_completions}
                       WHERE timecompleted > 0
                    GROUP BY course";
        $fullname = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";

        return [
            'completion' => [
                'levels' => [
                    ['sql' => "SELECT cc.id, cc.name AS category,
                                      COUNT(c.id) AS courses,
                                      COALESCE(SUM(en.n), 0) AS enrolled,
                                      COALESCE(SUM(co.n), 0) AS completed,
                                      COALESCE(ROUND(100.0 * SUM(co.n) / NULLIF(SUM(en.n), 0), 1), 0) AS completion_pct
                                 FROM {course_categories} cc
                            LEFT JOIN {course} c ON c.category = cc.id
                            LEFT JOIN ($enrolled) en ON en.courseid = c.id
                            LEFT JOIN ($completed) co ON co.course = c.id
                             GROUP BY cc.id, cc.name
                             ORDER BY cc.name",
                        'config' => ['metrics' => ['enrolled', 'completed']]],
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COALESCE(en.n, 0) AS enrolled,
                                      COALESCE(co.n, 0) AS completed,
                                      COALESCE(ROUND(100.0 * co.n / NULLIF(en.n, 0), 1), 0) AS completion_pct
                                 FROM {course} c
                                 JOIN {course_categories} cc ON cc.id = c.category
                            LEFT JOIN ($enrolled) en ON en.courseid = c.id
                            LEFT JOIN ($completed) co ON co.course = c.id
                                WHERE cc.id = %%PREVIOUSVALUE%%
                             ORDER BY c.fullname",
                        'config' => ['metrics' => ['enrolled', 'completed']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      COUNT(DISTINCT cm.id) AS activities,
                                      COUNT(DISTINCT CASE WHEN cmc.completionstate IN (1, 2) THEN cm.id END)
                                      AS completed_activities,
                                      COALESCE(MAX(ccp.timecompleted), 0) AS timecompleted
                                 FROM {course} c
                                 JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                                 JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                            LEFT JOIN {course_modules} cm ON cm.course = c.id AND cm.completion <> 0
                                      AND cm.deletioninprogress = 0
                            LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = u.id
                            LEFT JOIN {course_completions} ccp ON ccp.course = c.id AND ccp.userid = u.id
                                WHERE c.id = %%PREVIOUSVALUE%%
                             GROUP BY u.id, u.firstname, u.lastname, u.email
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['metrics' => ['activities', 'completed_activities']]],
                ],
            ],
            'progress' => [
                'levels' => [
                    ['sql' => "SELECT cc.id, cc.name AS category,
                                      COUNT(DISTINCT p.courseid) AS courses,
                                      COUNT(p.userid) AS learners,
                                      COALESCE(ROUND(AVG(100.0 * p.completed / p.activities), 1), 0) AS progress_pct
                                 FROM {course_categories} cc
                                 JOIN {course} c ON c.category = cc.id
                                 JOIN (" . self::progress_sql('') . ") p ON p.courseid = c.id
                             GROUP BY cc.id, cc.name
                             ORDER BY cc.name",
                        'config' => ['metrics' => ['progress_pct']]],
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(p.userid) AS learners,
                                      SUM(CASE WHEN p.completed = p.activities THEN 1 ELSE 0 END) AS all_completed,
                                      COALESCE(ROUND(AVG(100.0 * p.completed / p.activities), 1), 0) AS progress_pct
                                 FROM {course_categories} cc
                                 JOIN {course} c ON c.category = cc.id
                                 JOIN (" . self::progress_sql('AND c.category = %%LEVELVALUE:0%%') . ") p ON p.courseid = c.id
                                WHERE cc.id = %%PREVIOUSVALUE%%
                             GROUP BY c.id, c.fullname
                             ORDER BY c.fullname",
                        'config' => ['metrics' => ['progress_pct']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      p.completed, p.activities,
                                      ROUND(100.0 * p.completed / p.activities, 1) AS progress_pct,
                                      COALESCE(ul.timeaccess, 0) AS lastcourseaccess
                                 FROM (" . self::progress_sql('AND e.courseid = %%PREVIOUSVALUE%%') . ") p
                                 JOIN {user} u ON u.id = p.userid
                            LEFT JOIN {user_lastaccess} ul ON ul.courseid = p.courseid AND ul.userid = u.id
                             ORDER BY progress_pct DESC, u.lastname, u.firstname",
                        'config' => ['metrics' => ['progress_pct'], 'defaultview' => 'table']],
                    ['sql' => self::activity_progress_sql(), 'config' => ['defaultview' => 'table']],
                ],
            ],
            'courseactivity' => [
                'levels' => self::with_period(self::courseactivity_levels()),
                'daterange' => 'last30',
            ],
            'siteactivity' => [
                'levels' => self::with_period(self::siteactivity_levels()),
                'daterange' => 'last30',
            ],
            'useractions' => [
                'levels' => self::with_period(self::useractions_levels()),
                'daterange' => 'last30',
            ],
            'dailyactivity' => [
                'levels' => self::with_period(self::dailyactivity_levels()),
                'daterange' => 'last30',
            ],
            'logins' => [
                'levels' => self::with_period(self::logins_levels()),
                'daterange' => 'last30',
            ],
            'inactivity' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT ue.userid) AS enrolled,
                                      COUNT(DISTINCT ul.userid) AS accessed,
                                      COUNT(DISTINCT ue.userid) - COUNT(DISTINCT ul.userid) AS never_accessed
                                 FROM {course} c
                                 JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                            LEFT JOIN {user_lastaccess} ul ON ul.courseid = c.id AND ul.userid = ue.userid
                                WHERE c.id > 1
                             GROUP BY c.id, c.fullname
                             ORDER BY never_accessed DESC, c.fullname",
                        'config' => ['metrics' => ['never_accessed', 'accessed'], 'stacked' => true]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      CASE WHEN ul.timeaccess IS NULL THEN 'neveraccessed' ELSE 'accessed' END AS access_state,
                                      COALESCE(ul.timeaccess, 0) AS lastcourseaccess,
                                      u.lastaccess AS lastsiteaccess
                                 FROM {enrol} e
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                                 JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                            LEFT JOIN {user_lastaccess} ul ON ul.courseid = e.courseid AND ul.userid = u.id
                                WHERE e.courseid = %%PREVIOUSVALUE%% AND e.status = 0
                             ORDER BY ul.timeaccess, u.lastname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'forums' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT d.id) AS discussions,
                                      COUNT(DISTINCT p.id) AS posts,
                                      COUNT(DISTINCT p.userid) AS participants
                                 FROM {forum} f
                                 JOIN {course} c ON c.id = f.course
                            LEFT JOIN {forum_discussions} d ON d.forum = f.id
                            LEFT JOIN {forum_posts} p ON p.discussion = d.id
                             GROUP BY c.id, c.fullname
                             ORDER BY posts DESC",
                        'config' => ['metrics' => ['discussions', 'posts']]],
                    ['sql' => "SELECT f.id, f.name AS forum,
                                      COUNT(DISTINCT d.id) AS discussions,
                                      COUNT(DISTINCT p.id) AS posts,
                                      COUNT(DISTINCT p.userid) AS participants
                                 FROM {forum} f
                                 JOIN {course} c ON c.id = f.course
                            LEFT JOIN {forum_discussions} d ON d.forum = f.id
                            LEFT JOIN {forum_posts} p ON p.discussion = d.id
                                WHERE c.id = %%PREVIOUSVALUE%%
                             GROUP BY f.id, f.name
                             ORDER BY f.name",
                        'config' => ['metrics' => ['discussions', 'posts']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname,
                                      COUNT(p.id) AS posts,
                                      COUNT(CASE WHEN p.parent = 0 THEN 1 END) AS discussions_started,
                                      MAX(p.created) AS lastpostcreated
                                 FROM {forum} f
                                 JOIN {forum_discussions} d ON d.forum = f.id
                                 JOIN {forum_posts} p ON p.discussion = d.id
                                 JOIN {user} u ON u.id = p.userid
                                WHERE f.id = %%PREVIOUSVALUE%%
                             GROUP BY u.id, u.firstname, u.lastname
                             ORDER BY posts DESC",
                        'config' => []],
                ],
            ],
            'coursecontent' => [
                'levels' => [
                    ['sql' => "SELECT m.name AS activity_type,
                                      COUNT(cm.id) AS activities,
                                      COUNT(DISTINCT cm.course) AS courses
                                 FROM {course_modules} cm
                                 JOIN {modules} m ON m.id = cm.module
                                WHERE cm.deletioninprogress = 0
                             GROUP BY m.name
                             ORDER BY activities DESC",
                        'config' => ['metrics' => ['activities'], 'defaultview' => 'pie']],
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(cm.id) AS activities,
                                      SUM(CASE WHEN cm.visible = 1 THEN 1 ELSE 0 END) AS visible,
                                      SUM(CASE WHEN cm.completion <> 0 THEN 1 ELSE 0 END) AS with_completion
                                 FROM {course_modules} cm
                                 JOIN {modules} m ON m.id = cm.module
                                 JOIN {course} c ON c.id = cm.course
                                WHERE m.name = %%PREVIOUSVALUE%% AND cm.deletioninprogress = 0
                             GROUP BY c.id, c.fullname
                             ORDER BY activities DESC",
                        'config' => []],
                    ['sql' => "SELECT cs.id,
                                      COALESCE(NULLIF(cs.name, ''), CONCAT('#', cs.section)) AS section,
                                      COUNT(cm.id) AS activities
                                 FROM {course_modules} cm
                                 JOIN {modules} m ON m.id = cm.module
                                 JOIN {course_sections} cs ON cs.id = cm.section
                                 JOIN {course} c ON c.id = cm.course
                                WHERE m.name = %%LEVELVALUE:0%% AND c.id = %%PREVIOUSVALUE%%
                                      AND cm.deletioninprogress = 0
                             GROUP BY cs.id, cs.name, cs.section
                             ORDER BY cs.section",
                        'config' => []],
                ],
            ],
            'roles' => [
                'levels' => [
                    ['sql' => "SELECT r.id, COALESCE(NULLIF(r.name, ''), r.shortname) AS role,
                                      COUNT(DISTINCT ctx.instanceid) AS courses,
                                      COUNT(DISTINCT ra.userid) AS users
                                 FROM {role} r
                                 JOIN {role_assignments} ra ON ra.roleid = r.id
                                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                             GROUP BY r.id, r.name, r.shortname, r.sortorder
                             ORDER BY r.sortorder",
                        'config' => ['metrics' => ['users']]],
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT ra.userid) AS users
                                 FROM {role_assignments} ra
                                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                                 JOIN {course} c ON c.id = ctx.instanceid
                                WHERE ra.roleid = %%PREVIOUSVALUE%%
                             GROUP BY c.id, c.fullname
                             ORDER BY users DESC",
                        'config' => []],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      ra.timemodified AS assignedtime,
                                      u.lastaccess
                                 FROM {role_assignments} ra
                                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                                 JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                                WHERE ra.roleid = %%LEVELVALUE:0%% AND ctx.instanceid = %%PREVIOUSVALUE%%
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'enrolmethods' => [
                'levels' => [
                    ['sql' => "SELECT e.enrol AS method,
                                      COUNT(DISTINCT e.courseid) AS courses,
                                      SUM(CASE WHEN ue.status = 0 THEN 1 ELSE 0 END) AS active,
                                      SUM(CASE WHEN ue.status = 1 THEN 1 ELSE 0 END) AS suspended
                                 FROM {enrol} e
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                             GROUP BY e.enrol
                             ORDER BY active DESC",
                        'config' => ['metrics' => ['active', 'suspended'], 'stacked' => true]],
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      SUM(CASE WHEN ue.status = 0 THEN 1 ELSE 0 END) AS active,
                                      SUM(CASE WHEN ue.status = 1 THEN 1 ELSE 0 END) AS suspended
                                 FROM {enrol} e
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                 JOIN {course} c ON c.id = e.courseid
                                WHERE e.enrol = %%PREVIOUSVALUE%%
                             GROUP BY c.id, c.fullname
                             ORDER BY active DESC",
                        'config' => ['stacked' => true]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      CASE WHEN ue.status = 0 THEN 'active' ELSE 'suspended' END AS enrolment_status,
                                      ue.timecreated AS enroltime,
                                      ue.timeend
                                 FROM {enrol} e
                                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                 JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                                WHERE e.enrol = %%LEVELVALUE:0%% AND e.courseid = %%PREVIOUSVALUE%%
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'grades' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(gg.id) AS graded_users,
                                      COALESCE(ROUND(AVG(100.0 * (gg.finalgrade - gi.grademin)
                                          / NULLIF(gi.grademax - gi.grademin, 0)), 1), 0) AS average_pct
                                 FROM {course} c
                                 JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
                            LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.finalgrade IS NOT NULL
                                WHERE c.id > 1
                             GROUP BY c.id, c.fullname
                             ORDER BY c.fullname",
                        'config' => ['metrics' => ['average_pct']]],
                    ['sql' => "SELECT gi.id, COALESCE(gi.itemname, gi.itemtype) AS grade_item,
                                      COUNT(gg.id) AS graded_users,
                                      COALESCE(ROUND(AVG(100.0 * (gg.finalgrade - gi.grademin)
                                          / NULLIF(gi.grademax - gi.grademin, 0)), 1), 0) AS average_pct
                                 FROM {grade_items} gi
                            LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.finalgrade IS NOT NULL
                                WHERE gi.courseid = %%PREVIOUSVALUE%% AND gi.itemtype <> 'course'
                             GROUP BY gi.id, gi.itemname, gi.itemtype, gi.sortorder
                             ORDER BY gi.sortorder",
                        'config' => ['metrics' => ['average_pct']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname,
                                      ROUND(gg.finalgrade, 2) AS grade,
                                      COALESCE(ROUND(100.0 * (gg.finalgrade - gi.grademin)
                                          / NULLIF(gi.grademax - gi.grademin, 0), 1), 0) AS grade_pct,
                                      gg.timemodified
                                 FROM {grade_grades} gg
                                 JOIN {grade_items} gi ON gi.id = gg.itemid
                                 JOIN {user} u ON u.id = gg.userid AND u.deleted = 0
                                WHERE gg.itemid = %%PREVIOUSVALUE%% AND gg.finalgrade IS NOT NULL
                             ORDER BY gg.finalgrade DESC",
                        'config' => ['metrics' => ['grade_pct']]],
                ],
            ],
            'badges' => [
                'levels' => [
                    ['sql' => "SELECT b.id, b.name AS badge,
                                      COUNT(bi.id) AS issued
                                 FROM {badge} b
                            LEFT JOIN {badge_issued} bi ON bi.badgeid = b.id
                             GROUP BY b.id, b.name
                             ORDER BY issued DESC, b.name",
                        'config' => []],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      bi.dateissued AS issuedtime
                                 FROM {badge_issued} bi
                                 JOIN {user} u ON u.id = bi.userid AND u.deleted = 0
                                WHERE bi.badgeid = %%PREVIOUSVALUE%%
                             ORDER BY bi.dateissued DESC",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'filestorage' => [
                'levels' => [
                    ['sql' => "SELECT f.component,
                                      COUNT(f.id) AS files,
                                      ROUND(SUM(f.filesize) / 1048576.0, 2) AS size_mb
                                 FROM {files} f
                                WHERE f.filename <> '.'
                             GROUP BY f.component
                             ORDER BY size_mb DESC",
                        'config' => ['metrics' => ['size_mb'], 'defaultview' => 'pie']],
                    ['sql' => "SELECT COALESCE(f.mimetype, '') AS mimetype,
                                      COUNT(f.id) AS files,
                                      ROUND(SUM(f.filesize) / 1048576.0, 2) AS size_mb
                                 FROM {files} f
                                WHERE f.filename <> '.' AND f.component = %%PREVIOUSVALUE%%
                             GROUP BY f.mimetype
                             ORDER BY size_mb DESC",
                        'config' => ['metrics' => ['size_mb']]],
                    ['sql' => "SELECT f.id, f.filename,
                                      ROUND(f.filesize / 1048576.0, 3) AS size_mb,
                                      f.timecreated
                                 FROM {files} f
                                WHERE f.filename <> '.' AND f.component = %%LEVELVALUE:0%%
                                      AND f.mimetype = %%PREVIOUSVALUE%%
                             ORDER BY f.filesize DESC",
                        'config' => ['metrics' => ['size_mb']]],
                ],
            ],
            'ungraded' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT s.id) AS to_grade,
                                      COUNT(DISTINCT a.id) AS assignments,
                                      MIN(s.timemodified) AS oldestsubmissiontime
                                 FROM {assign} a
                                 JOIN {course} c ON c.id = a.course
                                 JOIN (" . self::ungraded_sql() . ") s ON s.assignment = a.id
                             GROUP BY c.id, c.fullname
                             ORDER BY to_grade DESC",
                        'config' => ['metrics' => ['to_grade']]],
                    ['sql' => "SELECT a.id, a.name AS assignment,
                                      COUNT(s.id) AS to_grade,
                                      MIN(s.timemodified) AS oldestsubmissiontime,
                                      a.duedate
                                 FROM {assign} a
                                 JOIN {course} c ON c.id = a.course
                                 JOIN (" . self::ungraded_sql() . ") s ON s.assignment = a.id
                                WHERE c.id = %%PREVIOUSVALUE%%
                             GROUP BY a.id, a.name, a.duedate
                             ORDER BY to_grade DESC",
                        'config' => ['metrics' => ['to_grade']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      s.timemodified AS submittedtime
                                 FROM (" . self::ungraded_sql() . ") s
                                 JOIN {assign} a ON a.id = s.assignment
                                 JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                                WHERE a.id = %%PREVIOUSVALUE%%
                             ORDER BY s.timemodified",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'overdue' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT o.assignmentid) AS overdue_assignments,
                                      COUNT(DISTINCT o.userid) AS students_behind,
                                      COUNT(1) AS missing_submissions
                                 FROM (" . self::overdue_sql('') . ") o
                                 JOIN {course} c ON c.id = o.courseid
                             GROUP BY c.id, c.fullname
                             ORDER BY missing_submissions DESC",
                        'config' => ['metrics' => ['missing_submissions']]],
                    ['sql' => "SELECT a.id, a.name AS assignment,
                                      COUNT(1) AS missing_submissions,
                                      a.duedate
                                 FROM (" . self::overdue_sql('AND a.course = %%LEVELVALUE:0%%') . ") o
                                 JOIN {assign} a ON a.id = o.assignmentid
                                 JOIN {course} c ON c.id = a.course
                                WHERE c.id = %%PREVIOUSVALUE%%
                             GROUP BY a.id, a.name, a.duedate
                             ORDER BY a.duedate",
                        'config' => ['metrics' => ['missing_submissions']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      u.lastaccess
                                 FROM (" . self::overdue_sql('AND a.id = %%PREVIOUSVALUE%%') . ") o
                                 JOIN {user} u ON u.id = o.userid
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'quizzes' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT q.id) AS quizzes,
                                      COUNT(DISTINCT qa.id) AS attempts,
                                      COUNT(DISTINCT qa.userid) AS participants
                                 FROM {quiz} q
                                 JOIN {course} c ON c.id = q.course
                            LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished' AND qa.preview = 0
                             GROUP BY c.id, c.fullname
                             ORDER BY c.fullname",
                        'config' => ['metrics' => ['attempts', 'participants']]],
                    ['sql' => "SELECT q.id, q.name AS quiz,
                                      (SELECT COUNT(DISTINCT qa.userid) FROM {quiz_attempts} qa
                                        WHERE qa.quiz = q.id AND qa.state = 'finished' AND qa.preview = 0) AS participants,
                                      (SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra
                                         JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                                         JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                                        WHERE ctx.instanceid = q.course) AS students,
                                      COALESCE((SELECT ROUND(AVG(100.0 * qg.grade / NULLIF(q.grade, 0)), 1)
                                                  FROM {quiz_grades} qg WHERE qg.quiz = q.id), 0) AS average_pct
                                 FROM {quiz} q
                                 JOIN {course} c ON c.id = q.course
                                WHERE c.id = %%PREVIOUSVALUE%%
                             ORDER BY q.name",
                        'config' => ['metrics' => ['participants', 'students']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      COUNT(DISTINCT qa.id) AS attempts,
                                      COALESCE(ROUND(MAX(100.0 * qg.grade / NULLIF(q.grade, 0)), 1), 0) AS grade_pct,
                                      COALESCE(MAX(qa.timefinish), 0) AS lastattempttime
                                 FROM {quiz} q
                                 JOIN {context} ctx ON ctx.instanceid = q.course AND ctx.contextlevel = 50
                                 JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                 JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                                 JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                            LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = u.id AND qa.state = 'finished'
                                      AND qa.preview = 0
                            LEFT JOIN {quiz_grades} qg ON qg.quiz = q.id AND qg.userid = u.id
                                WHERE q.id = %%PREVIOUSVALUE%%
                             GROUP BY u.id, u.firstname, u.lastname, u.email
                             ORDER BY attempts, u.lastname, u.firstname",
                        'config' => ['metrics' => ['grade_pct'], 'defaultview' => 'table']],
                ],
            ],
            'groups' => [
                'levels' => [
                    ['sql' => "SELECT c.id, c.fullname AS course,
                                      COUNT(DISTINCT g.id) AS groups_count,
                                      COUNT(DISTINCT gm.userid) AS members
                                 FROM {groups} g
                                 JOIN {course} c ON c.id = g.courseid
                            LEFT JOIN {groups_members} gm ON gm.groupid = g.id
                             GROUP BY c.id, c.fullname
                             ORDER BY c.fullname",
                        'config' => ['metrics' => ['groups_count', 'members']]],
                    ['sql' => "SELECT g.id, g.name AS group_name,
                                      COUNT(gm.id) AS members
                                 FROM {groups} g
                            LEFT JOIN {groups_members} gm ON gm.groupid = g.id
                                WHERE g.courseid = %%PREVIOUSVALUE%%
                             GROUP BY g.id, g.name
                             ORDER BY g.name",
                        'config' => []],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      gm.timeadded,
                                      u.lastaccess
                                 FROM {groups_members} gm
                                 JOIN {user} u ON u.id = gm.userid AND u.deleted = 0
                                WHERE gm.groupid = %%PREVIOUSVALUE%%
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'cohorts' => [
                'levels' => [
                    ['sql' => "SELECT ch.id, ch.name AS cohort,
                                      (SELECT COUNT(1) FROM {cohort_members} m WHERE m.cohortid = ch.id) AS members,
                                      (SELECT COUNT(DISTINCT e.courseid) FROM {enrol} e
                                        WHERE e.enrol = 'cohort' AND e.customint1 = ch.id) AS synced_courses
                                 FROM {cohort} ch
                             ORDER BY ch.name",
                        'config' => ['metrics' => ['members']]],
                    ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                                      m.timeadded,
                                      u.lastaccess
                                 FROM {cohort_members} m
                                 JOIN {user} u ON u.id = m.userid AND u.deleted = 0
                                WHERE m.cohortid = %%PREVIOUSVALUE%%
                             ORDER BY u.lastname, u.firstname",
                        'config' => ['defaultview' => 'table']],
                ],
            ],
            'coursehealth' => [
                'levels' => [
                    ['sql' => "SELECT cc.id, cc.name AS category,
                                      COUNT(h.id) AS courses,
                                      SUM(h.no_teacher) AS without_teacher,
                                      SUM(h.hidden_with_students) AS hidden_with_students,
                                      SUM(h.no_enddate) AS without_end_date,
                                      SUM(h.no_activities) AS without_activities
                                 FROM {course_categories} cc
                                 JOIN (" . self::course_health_sql() . ") h ON h.category = cc.id
                             GROUP BY cc.id, cc.name
                             ORDER BY cc.name",
                        'config' => ['stacked' => true]],
                    ['sql' => "SELECT h.id, h.fullname AS course,
                                      h.no_teacher + h.hidden_with_students + h.no_enddate + h.no_activities AS issues,
                                      h.no_teacher AS without_teacher,
                                      h.hidden_with_students,
                                      h.no_enddate AS without_end_date,
                                      h.no_activities AS without_activities
                                 FROM (" . self::course_health_sql() . ") h
                                 JOIN {course_categories} cc ON cc.id = h.category
                                WHERE cc.id = %%PREVIOUSVALUE%%
                             ORDER BY issues DESC, h.fullname",
                        'config' => ['metrics' => ['issues']]],
                ],
            ],
        ];
    }

    /**
     * Latest individual assignment submissions that still need a grade (no grade yet, or graded before the
     * last change of the submission).
     *
     * @return string SQL returning id, assignment, userid, timemodified
     */
    protected static function ungraded_sql(): string {
        return "SELECT s.id, s.assignment, s.userid, s.timemodified
                  FROM {assign_submission} s
             LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.userid = s.userid
                       AND ag.attemptnumber = s.attemptnumber
                 WHERE s.latest = 1 AND s.status = 'submitted' AND s.userid > 0
                       AND (ag.id IS NULL OR ag.grade IS NULL OR ag.grade < 0 OR ag.timemodified < s.timemodified)";
    }

    /**
     * Students (student archetype role in the course) without a submission for an assignment whose due date passed.
     *
     * @param string $extra extra WHERE condition (starting with AND)
     * @return string SQL returning courseid, assignmentid, userid
     */
    protected static function overdue_sql(string $extra): string {
        return "SELECT DISTINCT a.course AS courseid, a.id AS assignmentid, ra.userid
                  FROM {assign} a
                  JOIN {context} ctx ON ctx.instanceid = a.course AND ctx.contextlevel = 50
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
             LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = ra.userid AND s.latest = 1
                       AND s.status = 'submitted'
                 WHERE a.duedate > 0 AND a.duedate < %%NOW%% AND s.id IS NULL $extra";
    }

    /**
     * Course health flags (1 = problem): no teacher, hidden but with students, no end date, no activities.
     *
     * @return string SQL returning id, category, fullname and the four flags
     */
    protected static function course_health_sql(): string {
        $roleincourse = "SELECT 1 FROM {role_assignments} ra
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE ctx.instanceid = c.id AND r.archetype";
        return "SELECT c.id, c.category, c.fullname,
                       CASE WHEN NOT EXISTS ($roleincourse IN ('editingteacher', 'teacher')) THEN 1 ELSE 0 END AS no_teacher,
                       CASE WHEN c.visible = 0 AND EXISTS ($roleincourse = 'student') THEN 1 ELSE 0 END AS hidden_with_students,
                       CASE WHEN c.enddate = 0 THEN 1 ELSE 0 END AS no_enddate,
                       CASE WHEN NOT EXISTS (SELECT 1 FROM {course_modules} cm WHERE cm.course = c.id
                                              AND cm.deletioninprogress = 0) THEN 1 ELSE 0 END AS no_activities
                  FROM {course} c
                 WHERE c.id > 1";
    }

    /**
     * Course progress per enrolled user, as core_completion\progress::get_course_progress_percentage() computes it:
     * activities completed or passed / activities with completion tracking, in courses with completion enabled.
     * Hidden and deleted activities do not count.
     *
     * @param string $extra extra WHERE condition (starting with AND)
     * @return string SQL returning courseid, userid, activities, completed
     */
    protected static function progress_sql(string $extra): string {
        return "SELECT e.courseid, ue.userid,
                       COUNT(DISTINCT cm.id) AS activities,
                       COUNT(DISTINCT CASE WHEN cmc.completionstate IN (1, 2) THEN cm.id END) AS completed
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                  JOIN {course} c ON c.id = e.courseid AND c.enablecompletion = 1
                  JOIN {course_modules} cm ON cm.course = c.id AND cm.completion <> 0 AND cm.visible = 1
                       AND cm.deletioninprogress = 0
                  JOIN {course_sections} cs ON cs.id = cm.section AND cs.visible = 1
             LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = ue.userid
                 WHERE e.status = 0 $extra
              GROUP BY e.courseid, ue.userid";
    }

    /**
     * Activities of one user in one course with their completion state, in this order of precedence:
     * completed > submitted (assignment submitted, not graded yet) > notcompleted (failed or overdue) > futurenotcompleted.
     * The state is a code (completion_state column) that the interface shows as a coloured, translated label.
     *
     * @return string
     */
    protected static function activity_progress_sql(): string {
        // Activity names live in each module's table: core modules present in every supported Moodle (4.5 to 5.x).
        $modules = ['assign', 'bigbluebuttonbn', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary',
            'h5pactivity', 'imscp', 'label', 'lesson', 'lti', 'page', 'quiz', 'resource', 'scorm', 'url', 'wiki', 'workshop'];
        $joins = '';
        $names = [];
        foreach ($modules as $index => $module) {
            $joins .= "LEFT JOIN {{$module}} n$index ON m.name = '$module' AND n$index.id = cm.instance\n";
            $names[] = "n$index.name";
        }
        $names = implode(', ', $names);
        return "SELECT cm.id,
                       COALESCE($names, CONCAT(m.name, ' ', cm.id)) AS activity,
                       m.name AS activity_type,
                       CASE
                           WHEN COALESCE(cmc.completionstate, 0) = 0 AND sub.cmid IS NOT NULL THEN 'submitted'
                           WHEN cmc.completionstate = 3 AND sub.graded = 0 THEN 'submitted'
                           WHEN cmc.completionstate IN (1, 2) THEN 'completed'
                           WHEN cmc.completionstate = 3
                                OR (cm.completionexpected > 0 AND cm.completionexpected < %%NOW%%) THEN 'notcompleted'
                           ELSE 'futurenotcompleted'
                       END AS completion_state,
                       CASE WHEN cmc.completionstate IN (1, 2) THEN cmc.timemodified ELSE 0 END AS timecompleted,
                       cm.completionexpected
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {course_sections} cs ON cs.id = cm.section AND cs.visible = 1
             LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = %%PREVIOUSVALUE%%
             LEFT JOIN (SELECT scm.id AS cmid,
                               MAX(CASE WHEN ag.grade IS NULL OR ag.grade = -1 THEN 0 ELSE 1 END) AS graded
                          FROM {assign_submission} s
                          JOIN {assign} sa ON sa.id = s.assignment AND sa.teamsubmission = 0
                          JOIN {modules} sm ON sm.name = 'assign'
                          JOIN {course_modules} scm ON scm.instance = sa.id AND scm.module = sm.id
                     LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.attemptnumber = s.attemptnumber
                               AND ag.userid = s.userid
                         WHERE s.latest = 1 AND s.status = 'submitted' AND s.userid = %%PREVIOUSVALUE%%
                               AND sa.course = %%LEVELVALUE:1%%
                      GROUP BY scm.id) sub ON sub.cmid = cm.id
             $joins
                 WHERE cm.course = %%LEVELVALUE:1%% AND cm.completion <> 0 AND cm.visible = 1
                       AND cm.deletioninprogress = 0
              ORDER BY cs.section, cm.id";
    }

    /**
     * Readable name of the area of Moodle a log entry belongs to (its component), as SQL on the log alias "l".
     *
     * @return string SQL expression
     */
    protected static function log_area_sql(): string {
        $areas = [
            'core' => 'Moodle core: site, login, course, profile, enrolments, grades, messages',
            'mod_assign' => 'Activity: Assignment', 'mod_bigbluebuttonbn' => 'Activity: BigBlueButton',
            'mod_book' => 'Resource: Book', 'mod_choice' => 'Activity: Choice', 'mod_data' => 'Activity: Database',
            'mod_feedback' => 'Activity: Feedback', 'mod_folder' => 'Resource: Folder', 'mod_forum' => 'Activity: Forum',
            'mod_glossary' => 'Activity: Glossary', 'mod_h5pactivity' => 'Activity: H5P',
            'mod_imscp' => 'Resource: IMS content package', 'mod_label' => 'Resource: Text and media area',
            'mod_lesson' => 'Activity: Lesson', 'mod_lti' => 'Activity: External tool', 'mod_page' => 'Resource: Page',
            'mod_quiz' => 'Activity: Quiz', 'mod_resource' => 'Resource: File', 'mod_scorm' => 'Activity: SCORM package',
            'mod_url' => 'Resource: URL', 'mod_wiki' => 'Activity: Wiki', 'mod_workshop' => 'Activity: Workshop',
            'mod_subcourse' => 'Activity: Subcourse', 'mod_customcert' => 'Activity: Custom certificate',
            'mod_qbank' => 'Question bank', 'gradereport_grader' => 'Grades: grader report',
            'gradereport_user' => 'Grades: user report', 'gradereport_overview' => 'Grades: overview report',
            'core_completion' => 'Completion', 'tool_usertours' => 'User tours (guided help)',
            'tool_dataprivacy' => 'Data privacy', 'tool_policy' => 'Site policies', 'tool_mobile' => 'Moodle app',
            'tool_uploaduser' => 'Upload users', 'tool_recyclebin' => 'Recycle bin', 'block_xp' => 'Block: Level up!',
            'report_log' => 'Report: logs', 'report_outline' => 'Report: activity report',
            'report_completion' => 'Report: course completion', 'report_progress' => 'Report: activity completion',
            'report_loglive' => 'Report: live logs', 'enrol_self' => 'Self enrolment', 'enrol_manual' => 'Manual enrolment',
            'core_h5p' => 'H5P content', 'core_badges' => 'Badges', 'core_message' => 'Messages',
            'core_calendar' => 'Calendar', 'core_cohort' => 'Cohorts', 'core_competency' => 'Competencies',
            'local_reportium' => 'Reportium reports', 'aiplacement_courseassist' => 'AI course assistant',
            'aiplacement_editor' => 'AI in the editor', 'auth_oauth2' => 'Login: OAuth 2',
        ];
        $when = '';
        foreach ($areas as $component => $label) {
            $when .= "WHEN l.component = '$component' THEN '" . str_replace("'", "''", $label) . "'\n";
        }
        // Families of plugins without their own line.
        $families = ['mod' => 'Activity', 'block' => 'Block', 'tool' => 'Admin tool', 'report' => 'Report',
            'gradereport' => 'Grades', 'enrol' => 'Enrolment', 'format' => 'Course format', 'local' => 'Plugin',
            'qtype' => 'Question type', 'auth' => 'Login', 'theme' => 'Theme', 'aiplacement' => 'AI'];
        foreach ($families as $prefix => $label) {
            $start = strlen($prefix) + 2;
            $when .= "WHEN l.component LIKE '{$prefix}_%' THEN CONCAT('$label: ', SUBSTRING(l.component, $start, 100))\n";
        }
        return "CASE\n{$when}ELSE l.component END";
    }

    /**
     * What a group of log entries is about, by its context: the name of the activity, or Course, User, Category,
     * Block, Site. On a subquery "g" with contextlevel and contextinstanceid.
     *
     * @return string[] [SQL expression, LEFT JOINs it needs]
     */
    protected static function log_item_sql(): array {
        // Activity names live in each module's table (core modules of every supported Moodle).
        $modules = ['assign', 'bigbluebuttonbn', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary',
            'h5pactivity', 'imscp', 'label', 'lesson', 'lti', 'page', 'quiz', 'resource', 'scorm', 'url', 'wiki', 'workshop'];
        $joins = "LEFT JOIN {course_modules} cm ON g.contextlevel = 70 AND cm.id = g.contextinstanceid\n" .
            "LEFT JOIN {modules} m ON m.id = cm.module\n";
        $names = [];
        foreach ($modules as $index => $module) {
            $joins .= "LEFT JOIN {{$module}} n$index ON m.name = '$module' AND n$index.id = cm.instance\n";
            $names[] = "n$index.name";
        }
        $item = "COALESCE(" . implode(', ', $names) . ",
                    CASE WHEN g.contextlevel = 70 THEN CONCAT(COALESCE(m.name, 'activity'), ' ', g.contextinstanceid)
                         WHEN g.contextlevel = 50 THEN 'Course'
                         WHEN g.contextlevel = 30 THEN 'User'
                         WHEN g.contextlevel = 40 THEN 'Category'
                         WHEN g.contextlevel = 80 THEN 'Block'
                         ELSE 'Site' END)";
        return [$item, $joins];
    }

    /**
     * Level of the activity reports: every event of the same action as the log entry clicked, newest first.
     *
     * @param string $extra more conditions on the events "l" and the entry clicked "s" (starting with AND)
     * @return array ['sql' => string, 'config' => array]
     */
    protected static function log_events_level(string $extra = ''): array {
        $relateduser = "TRIM(CONCAT(COALESCE(ru.firstname, ''), ' ', COALESCE(ru.lastname, '')))";
        return ['sql' => "SELECT l.id, l.timecreated, l.eventname AS event,
                    CASE l.crud WHEN 'c' THEN 'created' WHEN 'r' THEN 'viewed' WHEN 'u' THEN 'updated' WHEN 'd' THEN 'deleted'
                         ELSE l.crud END AS operation,
                    l.origin, l.ip,
                    COALESCE($relateduser, '') AS related_user
               FROM {logstore_standard_log} s
               JOIN {logstore_standard_log} l ON l.courseid = s.courseid AND l.userid = s.userid
                    AND l.component = s.component AND l.target = s.target AND l.action = s.action
                    AND l.contextlevel = s.contextlevel AND l.contextinstanceid = s.contextinstanceid
          LEFT JOIN {user} ru ON ru.id = l.relateduserid
              WHERE s.id = %%PREVIOUSVALUE%% $extra
           ORDER BY l.timecreated DESC",
            'config' => ['defaultview' => 'table']];
    }

    /**
     * Last level of the activity reports: everything Moodle recorded about the event clicked (the object it changed,
     * where, who was "logged in as", the extra data of the event).
     *
     * @return array ['sql' => string, 'config' => array]
     */
    protected static function log_detail_level(): array {
        $fullname = fn(string $alias): string => "TRIM(CONCAT(COALESCE($alias.firstname, ''), ' ', COALESCE($alias.lastname, '')))";
        return ['sql' => "SELECT l.id, l.timecreated, l.eventname AS event, l.component, l.target AS object, l.action,
                    TRIM(CONCAT(COALESCE(l.objecttable, ''), ' ', COALESCE(CONCAT('', l.objectid), ''))) AS object_record,
                    CASE WHEN l.courseid > 1 THEN COALESCE(c.fullname, '') ELSE 'Site' END AS place,
                    COALESCE({$fullname('u')}, '') AS person,
                    COALESCE({$fullname('ru')}, '') AS related_user,
                    COALESCE({$fullname('au')}, '') AS logged_in_as,
                    l.origin, l.ip, l.other AS extra_data
               FROM {logstore_standard_log} l
          LEFT JOIN {course} c ON c.id = l.courseid
          LEFT JOIN {user} u ON u.id = l.userid
          LEFT JOIN {user} ru ON ru.id = l.relateduserid
          LEFT JOIN {user} au ON au.id = l.realuserid
              WHERE l.id = %%PREVIOUSVALUE%%",
            'config' => ['defaultview' => 'table']];
    }

    /**
     * Levels of the course activity report: courses > people > areas of Moodle > what they did there (on which
     * activity or page, which action) > every event. Every level has its first and last date.
     *
     * @return array[] [['sql' => string, 'config' => array], ...]
     */
    protected static function courseactivity_levels(): array {
        $fullname = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
        $area = self::log_area_sql();
        [$item, $joins] = self::log_item_sql();
        return [
            // Level 1: courses with activity.
            ['sql' => "SELECT c.id, c.fullname AS course,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT l.userid) AS active_users,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {course} c ON c.id = l.courseid
              WHERE l.courseid > 1 AND l.userid > 0 AND c.id IN (%%MYCOURSES%%)
           GROUP BY c.id, c.fullname
           ORDER BY events DESC",
                'config' => ['metrics' => ['events', 'active_users']]],
            // Level 2: people of the course.
            ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT FLOOR(l.timecreated / 86400)) AS active_days,
                    COUNT(DISTINCT l.component) AS areas,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid AND u.deleted = 0
              WHERE l.courseid = %%PREVIOUSVALUE%%
           GROUP BY u.id, u.firstname, u.lastname, u.email
           ORDER BY events DESC",
                'config' => ['metrics' => ['events', 'active_days']]],
            // Level 3: areas of Moodle the person used in the course.
            ['sql' => "SELECT l.component,
                    $area AS area,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT l.contextinstanceid) AS items,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
              WHERE l.courseid = %%LEVELVALUE:0%% AND l.userid = %%PREVIOUSVALUE%%
           GROUP BY l.component
           ORDER BY events DESC",
                'config' => ['labelcolumn' => 'area', 'metrics' => ['events'], 'defaultview' => 'pie']],
            // Level 4: what they did in that area: on which activity or page, which action, how often.
            ['sql' => "SELECT g.id, $item AS item, g.target AS object, g.action, g.events, g.first_access, g.last_access
               FROM (SELECT MIN(l.id) AS id, l.contextlevel, l.contextinstanceid, l.target, l.action,
                            COUNT(l.id) AS events, MIN(l.timecreated) AS first_access, MAX(l.timecreated) AS last_access
                       FROM {logstore_standard_log} l
                      WHERE l.courseid = %%LEVELVALUE:0%% AND l.userid = %%LEVELVALUE:1%% AND l.component = %%PREVIOUSVALUE%%
                   GROUP BY l.contextlevel, l.contextinstanceid, l.target, l.action) g
          $joins
           ORDER BY g.events DESC",
                'config' => ['labelcolumn' => 'item|action', 'metrics' => ['events']]],
            // Level 5: every event of that action.
            self::log_events_level(),
            // Level 6: details of one event.
            self::log_detail_level(),
        ];
    }

    /**
     * Levels of the site activity report (every log, site and courses): people > areas of Moodle > what they did
     * there (where: the site or a course; on which activity, page or user; which action) > every event.
     *
     * @return array[] [['sql' => string, 'config' => array], ...]
     */
    protected static function siteactivity_levels(): array {
        $fullname = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
        $area = self::log_area_sql();
        [$item, $joins] = self::log_item_sql();
        return [
            // Level 1: people with activity anywhere on the site.
            ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT FLOOR(l.timecreated / 86400)) AS active_days,
                    COUNT(DISTINCT CASE WHEN l.courseid > 1 THEN l.courseid END) AS courses,
                    SUM(CASE WHEN l.courseid > 1 THEN 0 ELSE 1 END) AS site_events,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid AND u.deleted = 0
              WHERE l.userid > 0
           GROUP BY u.id, u.firstname, u.lastname, u.email
           ORDER BY events DESC",
                'config' => ['metrics' => ['events', 'site_events']]],
            // Level 2: areas of Moodle the person used (site and courses).
            ['sql' => "SELECT l.component,
                    $area AS area,
                    COUNT(l.id) AS events,
                    SUM(CASE WHEN l.courseid > 1 THEN 0 ELSE 1 END) AS site_events,
                    COUNT(DISTINCT CASE WHEN l.courseid > 1 THEN l.courseid END) AS courses,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
              WHERE l.userid = %%PREVIOUSVALUE%%
           GROUP BY l.component
           ORDER BY events DESC",
                'config' => ['labelcolumn' => 'area', 'metrics' => ['events'], 'defaultview' => 'pie']],
            // Level 3: what they did in that area: where (site or course), on what, which action, how often.
            ['sql' => "SELECT g.id,
                    CASE WHEN g.courseid > 1 THEN COALESCE(c.fullname, 'Course') ELSE 'Site' END AS place,
                    $item AS item, g.target AS object, g.action, g.events, g.first_access, g.last_access
               FROM (SELECT MIN(l.id) AS id, l.courseid, l.contextlevel, l.contextinstanceid, l.target, l.action,
                            COUNT(l.id) AS events, MIN(l.timecreated) AS first_access, MAX(l.timecreated) AS last_access
                       FROM {logstore_standard_log} l
                      WHERE l.userid = %%LEVELVALUE:0%% AND l.component = %%PREVIOUSVALUE%%
                   GROUP BY l.courseid, l.contextlevel, l.contextinstanceid, l.target, l.action) g
          LEFT JOIN {course} c ON c.id = g.courseid
          $joins
           ORDER BY g.events DESC",
                'config' => ['labelcolumn' => 'item|action', 'metrics' => ['events']]],
            // Level 4: every event of that action.
            self::log_events_level(),
            // Level 5: details of one event.
            self::log_detail_level(),
        ];
    }

    /**
     * Levels of "Who did what to whom": people who acted on other people (grading, enrolling, messaging...) >
     * the people affected > what was done to them > every event > its details.
     *
     * @return array[]
     */
    protected static function useractions_levels(): array {
        $fullname = fn(string $alias): string => "TRIM(CONCAT(COALESCE($alias.firstname, ''), ' ', COALESCE($alias.lastname, '')))";
        $area = self::log_area_sql();
        [$item, $joins] = self::log_item_sql();
        return [
            ['sql' => "SELECT u.id, {$fullname('u')} AS fullname, u.email,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT l.relateduserid) AS people_affected,
                    COUNT(DISTINCT CASE WHEN l.courseid > 1 THEN l.courseid END) AS courses,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid AND u.deleted = 0
              WHERE l.relateduserid > 0 AND l.relateduserid <> l.userid
           GROUP BY u.id, u.firstname, u.lastname, u.email
           ORDER BY events DESC",
                'config' => ['metrics' => ['events', 'people_affected']]],
            ['sql' => "SELECT ru.id, {$fullname('ru')} AS fullname, ru.email,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT CASE WHEN l.courseid > 1 THEN l.courseid END) AS courses,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} ru ON ru.id = l.relateduserid
              WHERE l.userid = %%PREVIOUSVALUE%% AND l.relateduserid <> l.userid
           GROUP BY ru.id, ru.firstname, ru.lastname, ru.email
           ORDER BY events DESC",
                'config' => ['metrics' => ['events']]],
            ['sql' => "SELECT g.id,
                    CASE WHEN g.courseid > 1 THEN COALESCE(c.fullname, 'Course') ELSE 'Site' END AS place,
                    " . str_replace('l.component', 'g.component', $area) . " AS area,
                    $item AS item, g.target AS object, g.action, g.events, g.first_access, g.last_access
               FROM (SELECT MIN(l.id) AS id, l.courseid, l.component, l.contextlevel, l.contextinstanceid, l.target, l.action,
                            COUNT(l.id) AS events, MIN(l.timecreated) AS first_access, MAX(l.timecreated) AS last_access
                       FROM {logstore_standard_log} l
                      WHERE l.userid = %%LEVELVALUE:0%% AND l.relateduserid = %%PREVIOUSVALUE%%
                   GROUP BY l.courseid, l.component, l.contextlevel, l.contextinstanceid, l.target, l.action) g
          LEFT JOIN {course} c ON c.id = g.courseid
          $joins
           ORDER BY g.events DESC",
                'config' => ['labelcolumn' => 'item|action', 'metrics' => ['events']]],
            self::log_events_level('AND l.relateduserid = s.relateduserid'),
            self::log_detail_level(),
        ];
    }

    /**
     * Levels of "Daily activity": days (line chart) > people active that day > what each person did that day >
     * every event of that day > its details. Days are UTC days.
     *
     * @return array[]
     */
    protected static function dailyactivity_levels(): array {
        $fullname = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
        $area = self::log_area_sql();
        [$item, $joins] = self::log_item_sql();
        // The day clicked: from day_start to day_start + 24 hours.
        $day = 'l.timecreated >= %%LEVELVALUE:0%% AND l.timecreated - 86400 < %%LEVELVALUE:0%%';
        return [
            ['sql' => "SELECT d.day_start,
                    COUNT(d.id) AS events,
                    COUNT(DISTINCT d.userid) AS active_users,
                    SUM(CASE WHEN d.target = 'user' AND d.action = 'loggedin' THEN 1 ELSE 0 END) AS logins,
                    COUNT(DISTINCT CASE WHEN d.courseid > 1 THEN d.courseid END) AS courses
               FROM (SELECT FLOOR(l.timecreated / 86400) * 86400 AS day_start, l.id, l.userid, l.target, l.action, l.courseid
                       FROM {logstore_standard_log} l
                      WHERE l.userid > 0) d
           GROUP BY d.day_start
           ORDER BY d.day_start",
                // Every day in one page: the whole history in the chart.
                'config' => ['metrics' => ['events', 'active_users'], 'defaultview' => 'line', 'pagesize' => 1000]],
            ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                    COUNT(l.id) AS events,
                    COUNT(DISTINCT l.component) AS areas,
                    COUNT(DISTINCT CASE WHEN l.courseid > 1 THEN l.courseid END) AS courses,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid AND u.deleted = 0
              WHERE l.timecreated >= %%PREVIOUSVALUE%% AND l.timecreated - 86400 < %%PREVIOUSVALUE%%
           GROUP BY u.id, u.firstname, u.lastname, u.email
           ORDER BY events DESC",
                'config' => ['metrics' => ['events']]],
            ['sql' => "SELECT g.id,
                    CASE WHEN g.courseid > 1 THEN COALESCE(c.fullname, 'Course') ELSE 'Site' END AS place,
                    " . str_replace('l.component', 'g.component', $area) . " AS area,
                    $item AS item, g.target AS object, g.action, g.events, g.first_access, g.last_access
               FROM (SELECT MIN(l.id) AS id, l.courseid, l.component, l.contextlevel, l.contextinstanceid, l.target, l.action,
                            COUNT(l.id) AS events, MIN(l.timecreated) AS first_access, MAX(l.timecreated) AS last_access
                       FROM {logstore_standard_log} l
                      WHERE l.userid = %%PREVIOUSVALUE%% AND $day
                   GROUP BY l.courseid, l.component, l.contextlevel, l.contextinstanceid, l.target, l.action) g
          LEFT JOIN {course} c ON c.id = g.courseid
          $joins
           ORDER BY g.first_access",
                'config' => ['labelcolumn' => 'item|action', 'metrics' => ['events']]],
            self::log_events_level("AND $day"),
            self::log_detail_level(),
        ];
    }

    /**
     * Levels of "Logins and access": people with their logins, failed logins, IPs and app / web service access >
     * the IPs of a person > every access from that IP > its details.
     *
     * @return array[]
     */
    protected static function logins_levels(): array {
        $fullname = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
        // Login events, by target and action (event class names hold backslashes, which databases read differently).
        $logins = "l.component = 'core' AND ((l.target = 'user' AND l.action IN ('loggedin', 'loggedout', 'loggedinas'))
                   OR (l.target = 'user_login' AND l.action = 'failed'))";
        $counts = "SUM(CASE WHEN l.target = 'user' AND l.action = 'loggedin' THEN 1 ELSE 0 END) AS logins,
                    SUM(CASE WHEN l.target = 'user_login' AND l.action = 'failed' THEN 1 ELSE 0 END) AS failed_logins,
                    SUM(CASE WHEN l.origin = 'ws' THEN 1 ELSE 0 END) AS app_or_ws";
        return [
            ['sql' => "SELECT u.id, $fullname AS fullname, u.email,
                    $counts,
                    COUNT(DISTINCT l.ip) AS ips,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
               JOIN {user} u ON u.id = l.userid AND u.deleted = 0
              WHERE l.userid > 0 AND $logins
           GROUP BY u.id, u.firstname, u.lastname, u.email
           ORDER BY logins DESC",
                'config' => ['metrics' => ['logins', 'failed_logins']]],
            ['sql' => "SELECT l.ip,
                    $counts,
                    MIN(l.timecreated) AS first_access,
                    MAX(l.timecreated) AS last_access
               FROM {logstore_standard_log} l
              WHERE l.userid = %%PREVIOUSVALUE%% AND $logins
           GROUP BY l.ip
           ORDER BY last_access DESC",
                'config' => ['metrics' => ['logins', 'failed_logins']]],
            ['sql' => "SELECT l.id, l.timecreated,
                    CASE WHEN l.target = 'user_login' THEN 'failed login'
                         WHEN l.action = 'loggedin' THEN 'login'
                         WHEN l.action = 'loggedout' THEN 'logout'
                         ELSE 'logged in as another user' END AS what,
                    l.origin, l.other AS extra_data
               FROM {logstore_standard_log} l
              WHERE l.userid = %%LEVELVALUE:0%% AND l.ip = %%PREVIOUSVALUE%% AND $logins
           ORDER BY l.timecreated DESC",
                'config' => ['defaultview' => 'table']],
            self::log_detail_level(),
        ];
    }
    /**
     * Templates for a select/list: key => localised name.
     *
     * @return string[]
     */
    public static function list(): array {
        $list = [];
        foreach (array_keys(self::definitions()) as $key) {
            $list[$key] = get_string('template_' . $key . '_name', 'local_reportium');
        }
        return $list;
    }

    /**
     * Create a report (and its drill levels) from a template.
     *
     * @param string $key template key
     * @param int $courseid course the report belongs to (SITEID for site reports)
     * @param int $userid owner
     * @return int new report id
     */
    public static function install(string $key, int $courseid, int $userid): int {
        global $CFG, $DB;
        $definitions = self::definitions();
        if (!isset($definitions[$key])) {
            throw new \moodle_exception('templatenotfound', 'local_reportium', '', $key);
        }
        $template = $definitions[$key];
        $levels = $template['levels'];
        $now = time();

        $transaction = $DB->start_delegated_transaction();
        $report = (object) [
            'courseid' => $courseid,
            'ownerid' => $userid,
            'visible' => 1,
            'name' => get_string('template_' . $key . '_name', 'local_reportium'),
            'summary' => get_string('template_' . $key . '_summary', 'local_reportium'),
            'summaryformat' => FORMAT_HTML,
            'export' => implode(',', exporter::DEFAULTS) . ',',
            'global' => $courseid == SITEID ? 1 : 0,
            'displaytotalrecords' => 1,
            'displayprintbutton' => 1,
            'daterange' => $template['daterange'] ?? null,
            'template' => $key,
        ];
        $report->id = $DB->insert_record('local_reportium', $report);
        report_roles::set_defaults((int) $report->id);

        foreach ($levels as $index => $level) {
            $label = '';
            if ($index > 0 && get_string_manager()->string_exists("template_{$key}_level{$index}", 'local_reportium')) {
                $label = get_string("template_{$key}_level{$index}", 'local_reportium');
            }
            $DB->insert_record('local_reportium_level', (object) [
                'reportid' => $report->id,
                'drilllevel' => $index,
                'label' => $label,
                'sqlquery' => self::clean_sql($level['sql']),
                'config' => json_encode((object) $level['config']),
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }
        set_config(
            'cachettlminutes_report_' . $report->id,
            max(0, (int) get_config('local_reportium', 'defaultcachettl')),
            'local_reportium'
        );
        $transaction->allow_commit();
        return (int) $report->id;
    }

    /**
     * Install, as site reports, every template not installed yet (called on plugin install and upgrade).
     *
     * @return int[] key => new report id
     */
    public static function install_missing(): array {
        global $DB;
        $admin = get_admin();
        $userid = $admin ? (int) $admin->id : 0;
        $installed = [];
        foreach (array_keys(self::definitions()) as $key) {
            if (!$DB->record_exists('local_reportium', ['template' => $key])) {
                $installed[$key] = self::install($key, SITEID, $userid);
            }
        }
        return $installed;
    }

    /**
     * Levels added to a template after its reports were installed: append them to those reports (the levels they
     * already have are not touched).
     *
     * @return int levels added
     */
    public static function add_missing_levels(): int {
        global $DB;
        $added = 0;
        $now = time();
        $definitions = self::definitions();
        foreach ($DB->get_records_select('local_reportium', 'template IS NOT NULL') as $report) {
            if (!isset($definitions[$report->template])) {
                continue;
            }
            $have = (int) $DB->count_records('local_reportium_level', ['reportid' => $report->id]);
            foreach (array_slice($definitions[$report->template]['levels'], $have, null, true) as $index => $level) {
                $key = $report->template;
                $label = get_string_manager()->string_exists("template_{$key}_level{$index}", 'local_reportium')
                    ? get_string("template_{$key}_level{$index}", 'local_reportium') : '';
                $DB->insert_record('local_reportium_level', (object) ['reportid' => $report->id, 'drilllevel' => $index,
                    'label' => $label, 'sqlquery' => self::clean_sql($level['sql']),
                    'config' => json_encode((object) $level['config']), 'timecreated' => $now, 'timemodified' => $now,
                    'usermodified' => 0]);
                $added++;
            }
        }
        return $added;
    }

    /**
     * Log queries filter by the period of the report: the condition goes right after the WHERE of each query that
     * reads the logs as "l" (the same text everywhere, so add_date_ranges() recognises the SQL without it).
     *
     * @param array[] $levels
     * @return array[]
     */
    protected static function with_period(array $levels): array {
        foreach ($levels as $index => $level) {
            $levels[$index]['sql'] = preg_replace(
                '/(FROM \{logstore_standard_log\} [ls]\s(?:(?!WHERE|\)).)*?WHERE )/s',
                '$1' . self::PERIOD,
                $level['sql']
            );
        }
        return $levels;
    }

    /**
     * Installed log reports get the period condition in the levels whose SQL is still the original one (levels
     * changed by users are left alone), and the period of their template when they have none.
     *
     * @return int levels changed
     */
    public static function add_date_ranges(): int {
        global $DB;
        $changed = 0;
        $definitions = self::definitions();
        foreach ($DB->get_records_select('local_reportium', 'template IS NOT NULL') as $report) {
            $template = $definitions[$report->template] ?? null;
            if (!$template || empty($template['daterange'])) {
                continue;
            }
            foreach ($template['levels'] as $index => $level) {
                $new = self::clean_sql($level['sql']);
                if (strpos($new, self::PERIOD) === false) {
                    continue;
                }
                $record = $DB->get_record('local_reportium_level', ['reportid' => $report->id, 'drilllevel' => $index]);
                if ($record && trim((string) $record->sqlquery) === str_replace(self::PERIOD, '', $new)) {
                    $DB->set_field('local_reportium_level', 'sqlquery', $new, ['id' => $record->id]);
                    $changed++;
                }
            }
            if (empty($report->daterange)) {
                $DB->set_field('local_reportium', 'daterange', $template['daterange'], ['id' => $report->id]);
            }
        }
        return $changed;
    }

    /**
     * Reports installed from a template before the "template" field existed: recognise them by their name
     * (in any installed language) and remember their template key.
     *
     * @return void
     */
    public static function mark_existing(): void {
        global $DB;
        $strings = get_string_manager();
        $languages = array_keys($strings->get_list_of_translations(true));
        foreach (array_keys(self::definitions()) as $key) {
            $names = [];
            foreach (array_unique(array_merge(['en'], $languages)) as $lang) {
                $names[] = $strings->get_string('template_' . $key . '_name', 'local_reportium', null, $lang);
            }
            [$insql, $params] = $DB->get_in_or_equal(array_unique($names), SQL_PARAMS_NAMED);
            $params['siteid'] = SITEID;
            $DB->execute("UPDATE {local_reportium} SET template = :key
                           WHERE template IS NULL AND courseid = :siteid AND name $insql", $params + ['key' => $key]);
        }
    }

    /**
     * Remove the PHP source indentation so the SQL reads well in the SQL inspector.
     *
     * @param string $sql
     * @return string
     */
    public static function clean_sql(string $sql): string {
        $lines = array_map('trim', explode("\n", trim($sql)));
        return implode("\n", array_filter($lines, static function (string $line): bool {
            return $line !== '';
        }));
    }
}
