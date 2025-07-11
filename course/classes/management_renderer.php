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
 * Contains renderers for the course management pages.
 *
 * @package core_course
 * @copyright 2013 Sam Hemelryk
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/course/renderer.php');

/**
 * Main renderer for the course management pages.
 *
 * @package core_course
 * @copyright 2013 Sam Hemelryk
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_course_management_renderer extends plugin_renderer_base {

    /**
     * Initialises the JS required to enhance the management interface.
     *
     * Thunderbirds are go, this function kicks into gear the JS that makes the
     * course management pages that much cooler.
     */
    public function enhance_management_interface() {
        $this->page->requires->yui_module('moodle-course-management', 'M.course.management.init');
        $this->page->requires->strings_for_js(
            array(
                'show',
                'showcategory',
                'hide',
                'expand',
                'expandcategory',
                'collapse',
                'collapsecategory',
                'confirmcoursemove',
                'move',
                'cancel',
                'confirm'
            ),
            'moodle'
        );
    }

    /**
     * Prepares the form element for the course category listing bulk actions.
     *
     * @return string
     */
    public function management_form_start() {
        $form = array('action' => $this->page->url->out(), 'method' => 'POST', 'id' => 'coursecat-management');

        $html = html_writer::start_tag('form', $form);
        $html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        $html .=  html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'bulkaction'));
        return $html;
    }

    /**
     * Closes the course category bulk management form.
     *
     * @return string
     */
    public function management_form_end() {
        return html_writer::end_tag('form');
    }

    /**
     * Presents a course category listing.
     *
     * @param core_course_category $category The currently selected category. Also the category to highlight in the listing.
     * @return string
     */
    public function category_listing(?core_course_category $category = null) {

        if ($category === null) {
            $selectedparents = array();
            $selectedcategory = null;
        } else {
            $selectedparents = $category->get_parents();
            $selectedparents[] = $category->id;
            $selectedcategory = $category->id;
        }
        $catatlevel = \core_course\management\helper::get_expanded_categories('');
        $catatlevel[] = array_shift($selectedparents);
        $catatlevel = array_unique($catatlevel);

        $listing = core_course_category::top()->get_children();

        $attributes = [
            'class' => 'ms-1 list-unstyled category-list list-group',
            'role' => 'tree',
            'aria-labelledby' => 'category-listing-title',
        ];

        $html  = html_writer::start_div('category-listing card w-100');
        $html .= html_writer::tag('h3', get_string('categories'),
                array('class' => 'card-header', 'id' => 'category-listing-title'));
        $html .= html_writer::start_div('card-body');
        $html .= $this->category_listing_actions($category);
        $html .= html_writer::start_tag('ul', $attributes);
        foreach ($listing as $listitem) {
            // Render each category in the listing.
            $subcategories = array();
            if (in_array($listitem->id, $catatlevel)) {
                $subcategories = $listitem->get_children();
            }
            $html .= $this->category_listitem(
                    $listitem,
                    $subcategories,
                    $listitem->get_children_count(),
                    $selectedcategory,
                    $selectedparents
            );
        }
        $html .= html_writer::end_tag('ul');
        $html .= $this->category_bulk_actions($category);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renders a category list item.
     *
     * This function gets called recursively to render sub categories.
     *
     * @param core_course_category $category The category to render as listitem.
     * @param core_course_category[] $subcategories The subcategories belonging to the category being rented.
     * @param int $totalsubcategories The total number of sub categories.
     * @param int $selectedcategory The currently selected category
     * @param int[] $selectedcategories The path to the selected category and its ID.
     * @return string
     */
    public function category_listitem(core_course_category $category, array $subcategories, $totalsubcategories,
            $selectedcategory = null, $selectedcategories = array()) {

        $isexpandable = ($totalsubcategories > 0);
        $isexpanded = (!empty($subcategories));
        $activecategory = ($selectedcategory === $category->id);
        $attributes = array(
                'class' => 'listitem listitem-category list-group-item list-group-item-action',
                'data-id' => $category->id,
                'data-expandable' => $isexpandable ? '1' : '0',
                'data-expanded' => $isexpanded ? '1' : '0',
                'data-selected' => $activecategory ? '1' : '0',
                'data-visible' => $category->visible ? '1' : '0',
                'role' => 'treeitem',
                'aria-expanded' => $isexpanded ? 'true' : 'false',
                'data-course-count' => $category->get_courses_count(['recursive' => 1]),
                'data-category-name' => $category->get_formatted_name(),
        );
        $text = $category->get_formatted_name();
        if (($parent = $category->get_parent_coursecat()) && $parent->id) {
            $a = new stdClass;
            $a->category = $text;
            $a->parentcategory = $parent->get_formatted_name();
            $textlabel = get_string('categorysubcategoryof', 'moodle', $a);
        }
        $courseicon = $this->output->pix_icon('i/course', get_string('courses'), 'core', ['class' => 'ps-1']);
        $bcatinput = array(
                'id' => 'categorylistitem' . $category->id,
                'type' => 'checkbox',
                'name' => 'bcat[]',
                'value' => $category->id,
                'class' => 'bulk-action-checkbox form-check-input',
                'data-action' => 'select'
        );

        $checkboxclass = '';
        if (!$category->can_resort_subcategories() && !$category->has_manage_capability()) {
            // Very very hardcoded here.
            $checkboxclass = 'd-none';
        }

        $viewcaturl = new moodle_url('/course/management.php', array('categoryid' => $category->id));
        if ($isexpanded) {
            $icon = $this->output->pix_icon('t/switch_minus', get_string('collapse'),
                    'moodle', array('class' => 'tree-icon', 'title' => ''));
            $icon = html_writer::link(
                    $viewcaturl,
                    $icon,
                    array(
                            'class' => 'float-start',
                            'data-action' => 'collapse',
                            'title' => get_string('collapsecategory', 'moodle', $text),
                            'aria-controls' => 'subcategoryof'.$category->id
                    )
            );
        } else if ($isexpandable) {
            $icon = $this->output->pix_icon('t/switch_plus', get_string('expand'),
                    'moodle', array('class' => 'tree-icon', 'title' => ''));
            $icon = html_writer::link(
                    $viewcaturl,
                    $icon,
                    array(
                            'class' => 'float-start',
                            'data-action' => 'expand',
                            'title' => get_string('expandcategory', 'moodle', $text)
                    )
            );
        } else {
            $icon = $this->output->pix_icon(
                    'i/navigationitem',
                    '',
                    'moodle',
                    array('class' => 'tree-icon'));
            $icon = html_writer::span($icon, 'float-start');
        }
        $actions = \core_course\management\helper::get_category_listitem_actions($category);
        $hasactions = !empty($actions) || $category->can_create_course();

        $html = html_writer::start_tag('li', $attributes);
        $html .= html_writer::start_div('clearfix');
        $html .= html_writer::start_div('float-start ' . $checkboxclass);
        $html .= html_writer::start_div('form-check me-1 ');
        $html .= html_writer::empty_tag('input', $bcatinput);
        $labeltext = html_writer::span(get_string('bulkactionselect', 'moodle', $text), 'visually-hidden');
        $html .= html_writer::tag('label', $labeltext, array(
            'class' => 'form-check-label',
            'for' => 'categorylistitem' . $category->id));
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= $icon;
        if ($hasactions) {
            $textattributes = array('class' => 'float-start categoryname aalink');
        } else {
            $textattributes = array('class' => 'float-start categoryname without-actions');
        }
        if (isset($textlabel)) {
            $textattributes['aria-label'] = $textlabel;
        }
        $html .= html_writer::link($viewcaturl, $text, $textattributes);
        $html .= html_writer::start_div('float-end d-flex');
        if ($category->idnumber) {
            $html .= html_writer::tag('span', s($category->idnumber), array('class' => 'text-muted idnumber'));
        }
        if ($hasactions) {
            $html .= $this->category_listitem_actions($category, $actions);
        }
        $countid = 'course-count-'.$category->id;
        $html .= html_writer::span(
                html_writer::span($category->get_courses_count()) .
                html_writer::span(get_string('courses'), 'accesshide', array('id' => $countid)) .
                $courseicon,
                'course-count text-muted',
                array('aria-labelledby' => $countid)
        );
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        if ($isexpanded) {
            $html .= html_writer::start_tag('ul',
                    array('class' => 'ml', 'role' => 'group', 'id' => 'subcategoryof'.$category->id));
            $catatlevel = \core_course\management\helper::get_expanded_categories($category->path);
            $catatlevel[] = array_shift($selectedcategories);
            $catatlevel = array_unique($catatlevel);
            foreach ($subcategories as $listitem) {
                $childcategories = (in_array($listitem->id, $catatlevel)) ? $listitem->get_children() : array();
                $html .= $this->category_listitem(
                        $listitem,
                        $childcategories,
                        $listitem->get_children_count(),
                        $selectedcategory,
                        $selectedcategories
                );
            }
            $html .= html_writer::end_tag('ul');
        }
        $html .= html_writer::end_tag('li');
        return $html;
    }

    /**
     * Renderers the actions that are possible for the course category listing.
     *
     * These are not the actions associated with an individual category listing.
     * That happens through category_listitem_actions.
     *
     * @param core_course_category $category
     * @return string
     */
    public function category_listing_actions(?core_course_category $category = null) {
        $actions = array();

        $cancreatecategory = $category && $category->can_create_subcategory();
        $cancreatecategory = $cancreatecategory || core_course_category::can_create_top_level_category();
        if ($category === null) {
            $category = core_course_category::top();
        }

        if ($cancreatecategory) {
            $url = new moodle_url('/course/editcategory.php', array('parent' => $category->id));
            $actions[] = html_writer::link($url, get_string('createnewcategory'), array('class' => 'btn btn-secondary'));
        }
        if (core_course_category::can_approve_course_requests()) {
            $actions[] = html_writer::link(new moodle_url('/course/pending.php'), get_string('coursespending'));
        }
        if (count($actions) === 0) {
            return '';
        }
        return html_writer::div(join(' ', $actions), 'listing-actions category-listing-actions mb-3');
    }

    /**
     * Renderers the actions for individual category list items.
     *
     * @param core_course_category $category
     * @param array $actions
     * @return string
     */
    public function category_listitem_actions(core_course_category $category, ?array $actions = null) {
        if ($actions === null) {
            $actions = \core_course\management\helper::get_category_listitem_actions($category);
        }
        $menu = new action_menu();
        $menu->attributes['class'] .= ' category-item-actions item-actions';
        $hasitems = false;
        foreach ($actions as $key => $action) {
            $hasitems = true;
            $menu->add(new action_menu_link(
                $action['url'],
                $action['icon'],
                $action['string'],
                in_array($key, array('show', 'hide', 'moveup', 'movedown')),
                array('data-action' => $key, 'class' => 'action-'.$key)
            ));
        }
        if (!$hasitems) {
            return '';
        }

        // If the action menu has items, add the menubar role to the main element containing it.
        $menu->attributes['role'] = 'menubar';

        return $this->render($menu);
    }

    public function render_action_menu($menu) {
        return $this->output->render($menu);
    }

    /**
     * Renders bulk actions for categories.
     *
     * @param core_course_category $category The currently selected category if there is one.
     * @return string
     */
    public function category_bulk_actions(?core_course_category $category = null) {
        // Resort courses.
        // Change parent.
        if (!core_course_category::can_resort_any() && !core_course_category::can_change_parent_any()) {
            return '';
        }
        $strgo = new lang_string('go');

        $html  = html_writer::start_div('category-bulk-actions bulk-actions');
        $html .= html_writer::div(get_string('categorybulkaction'), 'accesshide', array('tabindex' => '0'));
        if (core_course_category::can_resort_any()) {
            $selectoptions = array(
                'selectedcategories' => get_string('selectedcategories'),
                'allcategories' => get_string('allcategories')
            );
            $form = html_writer::start_div();
            if ($category) {
                $selectoptions = array('thiscategory' => get_string('thiscategory')) + $selectoptions;
                $form .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'currentcategoryid', 'value' => $category->id));
            }
            $form .= html_writer::div(
                html_writer::select(
                    $selectoptions,
                    'selectsortby',
                    'selectedcategories',
                    false,
                    array('aria-label' => get_string('selectcategorysort'))
                )
            );
            $form .= html_writer::div(
                html_writer::select(
                    array(
                        'name' => get_string('sortbyx', 'moodle', get_string('categoryname')),
                        'namedesc' => get_string('sortbyxreverse', 'moodle', get_string('categoryname')),
                        'idnumber' => get_string('sortbyx', 'moodle', get_string('idnumbercoursecategory')),
                        'idnumberdesc' => get_string('sortbyxreverse' , 'moodle' , get_string('idnumbercoursecategory')),
                        'none' => get_string('dontsortcategories')
                    ),
                    'resortcategoriesby',
                    'name',
                    false,
                    array('aria-label' => get_string('selectcategorysortby'), 'class' => 'mt-1')
                )
            );
            $form .= html_writer::div(
                html_writer::select(
                    array(
                        'fullname' => get_string('sortbyx', 'moodle', get_string('fullnamecourse')),
                        'fullnamedesc' => get_string('sortbyxreverse', 'moodle', get_string('fullnamecourse')),
                        'shortname' => get_string('sortbyx', 'moodle', get_string('shortnamecourse')),
                        'shortnamedesc' => get_string('sortbyxreverse', 'moodle', get_string('shortnamecourse')),
                        'idnumber' => get_string('sortbyx', 'moodle', get_string('idnumbercourse')),
                        'idnumberdesc' => get_string('sortbyxreverse', 'moodle', get_string('idnumbercourse')),
                        'timecreated' => get_string('sortbyx', 'moodle', get_string('timecreatedcourse')),
                        'timecreateddesc' => get_string('sortbyxreverse', 'moodle', get_string('timecreatedcourse')),
                        'none' => get_string('dontsortcourses')
                    ),
                    'resortcoursesby',
                    'fullname',
                    false,
                    array('aria-label' => get_string('selectcoursesortby'), 'class' => 'mt-1')
                )
            );
            $form .= html_writer::empty_tag('input', array('type' => 'submit', 'name' => 'bulksort',
                'value' => get_string('sort'), 'class' => 'btn btn-secondary my-1'));
            $form .= html_writer::end_div();

            $html .= html_writer::start_div('detail-pair row yui3-g my-1');
            $html .= html_writer::div(html_writer::span(get_string('sorting')), 'pair-key col-md-3 yui3-u-1-4');
            $html .= html_writer::div($form, 'pair-value col-md-9 yui3-u-3-4');
            $html .= html_writer::end_div();
        }
        if (core_course_category::can_change_parent_any()) {
            $options = array();
            if (core_course_category::top()->has_manage_capability()) {
                $options[0] = core_course_category::top()->get_formatted_name();
            }
            $options += core_course_category::make_categories_list('moodle/category:manage');
            $select = html_writer::select(
                $options,
                'movecategoriesto',
                '',
                array('' => 'choosedots'),
                array('aria-labelledby' => 'moveselectedcategoriesto', 'class' => 'me-1')
            );
            $submit = array('type' => 'submit', 'name' => 'bulkmovecategories', 'value' => get_string('move'),
                'class' => 'btn btn-secondary');
            $html .= $this->detail_pair(
                html_writer::span(get_string('moveselectedcategoriesto'), '', array('id' => 'moveselectedcategoriesto')),
                $select . html_writer::empty_tag('input', $submit)
            );
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renders a course listing.
     *
     * @param core_course_category $category The currently selected category. This is what the listing is focused on.
     * @param core_course_list_element $course The currently selected course.
     * @param int $page The page being displayed.
     * @param int $perpage The number of courses to display per page.
     * @param string|null $viewmode The view mode the page is in, one out of 'default', 'combined', 'courses' or 'categories'.
     * @return string
     */
    public function course_listing(?core_course_category $category = null, ?core_course_list_element $course = null,
            $page = 0, $perpage = 20, $viewmode = 'default') {

        if ($category === null) {
            $html = html_writer::start_div('select-a-category');
            $html .= html_writer::tag('h3', get_string('courses'),
                    array('id' => 'course-listing-title', 'tabindex' => '0'));
            $html .= $this->output->notification(get_string('selectacategory'), 'notifymessage');
            $html .= html_writer::end_div();
            return $html;
        }

        $page = max($page, 0);
        $perpage = max($perpage, 2);
        $totalcourses = $category->coursecount;
        $totalpages = ceil($totalcourses / $perpage);
        if ($page > $totalpages - 1) {
            $page = $totalpages - 1;
        }
        $options = array(
                'offset' => $page * $perpage,
                'limit' => $perpage
        );
        $courseid = isset($course) ? $course->id : null;
        $class = '';
        if ($page === 0) {
            $class .= ' firstpage';
        }
        if ($page + 1 === (int)$totalpages) {
            $class .= ' lastpage';
        }

        $html  = html_writer::start_div('card course-listing w-100'.$class, array(
                'data-category' => $category->id,
                'data-page' => $page,
                'data-totalpages' => $totalpages,
                'data-totalcourses' => $totalcourses,
                'data-canmoveoutof' => $category->can_move_courses_out_of() && $category->can_move_courses_into()
        ));
        $html .= html_writer::tag('h3', $category->get_formatted_name(),
                array('id' => 'course-listing-title', 'tabindex' => '0', 'class' => 'card-header'));
        $html .= html_writer::start_div('card-body');
        $html .= $this->course_listing_actions($category, $course, $perpage);
        $html .= $this->listing_pagination($category, $page, $perpage, false, $viewmode);
        $html .= html_writer::start_tag('ul', ['class' => 'course-list list-group', 'role' => 'list']);
        foreach ($category->get_courses($options) as $listitem) {
            $html .= $this->course_listitem($category, $listitem, $courseid);
        }
        $html .= html_writer::end_tag('ul');
        $html .= $this->listing_pagination($category, $page, $perpage, true, $viewmode);
        $html .= $this->course_bulk_actions($category);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renders pagination for a course listing.
     *
     * @param core_course_category $category The category to produce pagination for.
     * @param int $page The current page.
     * @param int $perpage The number of courses to display per page.
     * @param bool $showtotals Set to true to show the total number of courses and what is being displayed.
     * @param string|null $viewmode The view mode the page is in, one out of 'default', 'combined', 'courses' or 'categories'.
     * @return string
     */
    protected function listing_pagination(core_course_category $category, $page, $perpage, $showtotals = false,
                                          $viewmode = 'default') {
        $html = '';
        $totalcourses = $category->get_courses_count();
        $totalpages = ceil($totalcourses / $perpage);
        if ($showtotals) {
            if ($totalpages == 0) {
                $str = get_string('nocoursesyet');
            } else if ($totalpages == 1) {
                $str = get_string('showingacourses', 'moodle', $totalcourses);
            } else {
                $a = new stdClass;
                $a->start = ($page * $perpage) + 1;
                $a->end = min((($page + 1) * $perpage), $totalcourses);
                $a->total = $totalcourses;
                $str = get_string('showingxofycourses', 'moodle', $a);
            }
            $html .= html_writer::div($str, 'listing-pagination-totals text-muted');
        }

        if ($viewmode !== 'default') {
            $baseurl = new moodle_url('/course/management.php', array('categoryid' => $category->id,
                'view' => $viewmode));
        } else {
            $baseurl = new moodle_url('/course/management.php', array('categoryid' => $category->id));
        }

        $html .= $this->output->paging_bar($totalcourses, $page, $perpage, $baseurl);
        return $html;
    }

    /**
     * Renderers a course list item.
     *
     * This function will be called for every course being displayed by course_listing.
     *
     * @param core_course_category $category The currently selected category and the category the course belongs to.
     * @param core_course_list_element $course The course to produce HTML for.
     * @param int $selectedcourse The id of the currently selected course.
     * @return string
     */
    public function course_listitem(core_course_category $category, core_course_list_element $course, $selectedcourse) {

        $text = $course->get_formatted_name();
        $attributes = array(
                'class' => 'listitem listitem-course list-group-item list-group-item-action',
                'data-id' => $course->id,
                'data-selected' => ($selectedcourse == $course->id) ? '1' : '0',
                'data-visible' => $course->visible ? '1' : '0'
        );

        $bulkcourseinput = array(
                'id' => 'courselistitem' . $course->id,
                'type' => 'checkbox',
                'name' => 'bc[]',
                'value' => $course->id,
                'class' => 'bulk-action-checkbox form-check-input',
                'data-action' => 'select'
        );

        $checkboxclass = '';
        if (!$category->has_manage_capability()) {
            // Very very hardcoded here.
            $checkboxclass = 'd-none';
        }

        $viewcourseurl = new moodle_url($this->page->url, array('courseid' => $course->id));

        $html  = html_writer::start_tag('li', $attributes);
        $html .= html_writer::start_div('d-flex flex-wrap');

        if ($category->can_resort_courses()) {
            // In order for dnd to be available the user must be able to resort the category children..
            $html .= html_writer::div($this->output->pix_icon('i/move_2d', get_string('dndcourse')), 'float-start drag-handle');
        }

        $html .= html_writer::start_div('float-start ' . $checkboxclass);
        $html .= html_writer::start_div('form-check me-1 ');
        $html .= html_writer::empty_tag('input', $bulkcourseinput);
        $labeltext = html_writer::span(get_string('bulkactionselect', 'moodle', $text), 'visually-hidden');
        $html .= html_writer::tag('label', $labeltext, array(
            'class' => 'form-check-label',
            'for' => 'courselistitem' . $course->id));
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::link(
            $viewcourseurl, $text, array('class' => 'text-break col ps-0 mb-2 coursename aalink')
        );
        $html .= html_writer::start_div('flex-shrink-0 ms-auto');
        if ($course->idnumber) {
            $html .= html_writer::tag('span', s($course->idnumber), array('class' => 'text-muted idnumber'));
        }
        $html .= $this->course_listitem_actions($category, $course);
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_tag('li');
        return $html;
    }

    /**
     * Renderers actions for the course listing.
     *
     * Not to be confused with course_listitem_actions which renderers the actions for individual courses.
     *
     * @param core_course_category $category
     * @param core_course_list_element $course The currently selected course.
     * @param int $perpage
     * @return string
     */
    public function course_listing_actions(core_course_category $category, ?core_course_list_element $course = null, $perpage = 20) {
        $actions = array();
        if ($category->can_create_course()) {
            $url = new moodle_url('/course/edit.php', array('category' => $category->id, 'returnto' => 'catmanage'));
            $actions[] = html_writer::link($url, get_string('createnewcourse'), array('class' => 'btn btn-secondary'));
        }
        if ($category->can_request_course()) {
            // Request a new course.
            $url = new moodle_url('/course/request.php', array('category' => $category->id, 'return' => 'management'));
            $actions[] = html_writer::link($url, get_string('requestcourse'));
        }
        if ($category->can_resort_courses()) {
            $params = $this->page->url->params();
            $params['action'] = 'resortcourses';
            $params['sesskey'] = sesskey();
            $baseurl = new moodle_url('/course/management.php', $params);
            $fullnameurl = new moodle_url($baseurl, array('resort' => 'fullname'));
            $fullnameurldesc = new moodle_url($baseurl, array('resort' => 'fullnamedesc'));
            $shortnameurl = new moodle_url($baseurl, array('resort' => 'shortname'));
            $shortnameurldesc = new moodle_url($baseurl, array('resort' => 'shortnamedesc'));
            $idnumberurl = new moodle_url($baseurl, array('resort' => 'idnumber'));
            $idnumberdescurl = new moodle_url($baseurl, array('resort' => 'idnumberdesc'));
            $timecreatedurl = new moodle_url($baseurl, array('resort' => 'timecreated'));
            $timecreateddescurl = new moodle_url($baseurl, array('resort' => 'timecreateddesc'));
            $menu = new action_menu(array(
                    new action_menu_link_secondary($fullnameurl,
                            null,
                            get_string('sortbyx', 'moodle', get_string('fullnamecourse'))),
                    new action_menu_link_secondary($fullnameurldesc,
                            null,
                            get_string('sortbyxreverse', 'moodle', get_string('fullnamecourse'))),
                    new action_menu_link_secondary($shortnameurl,
                            null,
                            get_string('sortbyx', 'moodle', get_string('shortnamecourse'))),
                    new action_menu_link_secondary($shortnameurldesc,
                            null,
                            get_string('sortbyxreverse', 'moodle', get_string('shortnamecourse'))),
                    new action_menu_link_secondary($idnumberurl,
                            null,
                            get_string('sortbyx', 'moodle', get_string('idnumbercourse'))),
                    new action_menu_link_secondary($idnumberdescurl,
                            null,
                            get_string('sortbyxreverse', 'moodle', get_string('idnumbercourse'))),
                    new action_menu_link_secondary($timecreatedurl,
                            null,
                            get_string('sortbyx', 'moodle', get_string('timecreatedcourse'))),
                    new action_menu_link_secondary($timecreateddescurl,
                            null,
                            get_string('sortbyxreverse', 'moodle', get_string('timecreatedcourse')))
            ));
            $menu->set_menu_trigger(get_string('resortcourses'));
            $actions[] = $this->render($menu);
        }
        $strall = get_string('all');
        $menu = new action_menu(array(
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 5)), null, 5),
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 10)), null, 10),
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 20)), null, 20),
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 50)), null, 50),
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 100)), null, 100),
                new action_menu_link_secondary(new moodle_url($this->page->url, array('perpage' => 999)), null, $strall),
        ));
        if ((int)$perpage === 999) {
            $perpage = $strall;
        }
        $menu->attributes['class'] .= ' courses-per-page';
        $menu->set_menu_trigger(get_string('perpagea', 'moodle', $perpage));
        $actions[] = $this->render($menu);
        return html_writer::div(join(' ', $actions), 'listing-actions course-listing-actions mb-3');
    }

    /**
     * Renderers actions for individual course actions.
     *
     * @param core_course_category $category The currently selected category.
     * @param core_course_list_element  $course The course to renderer actions for.
     * @return string
     */
    public function course_listitem_actions(core_course_category $category, core_course_list_element $course) {
        $actions = \core_course\management\helper::get_course_listitem_actions($category, $course);
        if (empty($actions)) {
            return '';
        }
        $actionshtml = array();
        foreach ($actions as $action) {
            $action['attributes']['role'] = 'button';
            $actionshtml[] = $this->output->action_icon($action['url'], $action['icon'], null, $action['attributes']);
        }
        return html_writer::span(join('', $actionshtml), 'course-item-actions item-actions me-0');
    }

    /**
     * Renderers bulk actions that can be performed on courses.
     *
     * @param core_course_category $category The currently selected category and the category in which courses that
     *      are selectable belong.
     * @return string
     */
    public function course_bulk_actions(core_course_category $category) {
        $html  = html_writer::start_div('course-bulk-actions bulk-actions');
        if ($category->can_move_courses_out_of()) {
            $html .= html_writer::div(get_string('coursebulkaction'), 'accesshide', array('tabindex' => '0'));
            $options = core_course_category::make_categories_list('moodle/category:manage');
            $select = html_writer::select(
                $options,
                'movecoursesto',
                '',
                array('' => 'choosedots'),
                array('aria-labelledby' => 'moveselectedcoursesto', 'class' => 'me-1')
            );
            $submit = array('type' => 'submit', 'name' => 'bulkmovecourses', 'value' => get_string('move'),
                'class' => 'btn btn-secondary');
            $html .= $this->detail_pair(
                html_writer::span(get_string('moveselectedcoursesto'), '', array('id' => 'moveselectedcoursesto')),
                $select . html_writer::empty_tag('input', $submit)
            );
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renderers bulk actions that can be performed on courses in search returns
     *
     * @return string
     */
    public function course_search_bulk_actions() {
        $html  = html_writer::start_div('course-bulk-actions bulk-actions');
        $html .= html_writer::div(get_string('coursebulkaction'), 'accesshide', array('tabindex' => '0'));
        $options = core_course_category::make_categories_list('moodle/category:manage');
        $select = html_writer::select(
            $options,
            'movecoursesto',
            '',
            array('' => 'choosedots'),
            array('aria-labelledby' => 'moveselectedcoursesto')
        );
        $submit = array('type' => 'submit', 'name' => 'bulkmovecourses', 'value' => get_string('move'),
            'class' => 'btn btn-secondary');
        $html .= $this->detail_pair(
            html_writer::span(get_string('moveselectedcoursesto'), '', array('id' => 'moveselectedcoursesto')),
            $select . html_writer::empty_tag('input', $submit)
        );
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renderers detailed course information.
     *
     * @param core_course_list_element $course The course to display details for.
     * @return string
     */
    public function course_detail(core_course_list_element $course) {
        $details = \core_course\management\helper::get_course_detail_array($course);
        $fullname = $details['fullname']['value'];

        $html = html_writer::start_div('course-detail card');
        $html .= html_writer::start_div('card-header');
        $html .= html_writer::tag('h3', $fullname, array('id' => 'course-detail-title',
                'class' => 'card-title', 'tabindex' => '0'));
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('card-body');
        $html .= $this->course_detail_actions($course);
        foreach ($details as $class => $data) {
            $html .= $this->detail_pair($data['key'], $data['value'], $class);
        }
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Renderers a key value pair of information for display.
     *
     * @param string $key
     * @param string $value
     * @param string $class
     * @return string
     */
    protected function detail_pair($key, $value, $class ='') {
        $html = html_writer::start_div('detail-pair row yui3-g '.preg_replace('#[^a-zA-Z0-9_\-]#', '-', $class));
        $html .= html_writer::div(html_writer::span($key), 'pair-key col-md-3 yui3-u-1-4 fw-bold');
        $html .= html_writer::div(html_writer::div($value, 'd-flex'), 'pair-value col-md-8 yui3-u-3-4');
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * A collection of actions for a course.
     *
     * @param core_course_list_element $course The course to display actions for.
     * @return string
     */
    public function course_detail_actions(core_course_list_element $course) {
        $actions = \core_course\management\helper::get_course_detail_actions($course);
        if (empty($actions)) {
            return '';
        }
        $options = array();
        foreach ($actions as $action) {
            $options[] = $this->action_link($action['url'], $action['string'], null,
                    array('class' => 'btn btn-sm btn-secondary me-1 mb-3'));
        }
        return html_writer::div(join('', $options), 'listing-actions course-detail-listing-actions');
    }

    /**
     * Creates an action button (styled link)
     *
     * @param moodle_url $url The URL to go to when clicked.
     * @param string $text The text for the button.
     * @param string $id An id to give the button.
     * @param string $class A class to give the button.
     * @param array $attributes Any additional attributes
     * @return string
     */
    protected function action_button(moodle_url $url, $text, $id = null, $class = null, $title = null, array $attributes = array()) {
        if (isset($attributes['class'])) {
            $attributes['class'] .= ' yui3-button';
        } else {
            $attributes['class'] = 'yui3-button';
        }
        if (!is_null($id)) {
            $attributes['id'] = $id;
        }
        if (!is_null($class)) {
            $attributes['class'] .= ' '.$class;
        }
        if (is_null($title)) {
            $title = $text;
        }
        $attributes['title'] = $title;
        if (!isset($attributes['role'])) {
            $attributes['role'] = 'button';
        }
        return html_writer::link($url, $text, $attributes);
    }

    /**
     * Opens a grid.
     *
     * Call {@link core_course_management_renderer::grid_column_start()} to create columns.
     *
     * @param string $id An id to give this grid.
     * @param string $class A class to give this grid.
     * @return string
     */
    public function grid_start($id = null, $class = null) {
        $gridclass = 'grid-start grid-row-r d-flex flex-wrap row';
        if (is_null($class)) {
            $class = $gridclass;
        } else {
            $class .= ' ' . $gridclass;
        }
        $attributes = array();
        if (!is_null($id)) {
            $attributes['id'] = $id;
        }
        return html_writer::start_div($class, $attributes);
    }

    /**
     * Closes the grid.
     *
     * @return string
     */
    public function grid_end() {
        return html_writer::end_div();
    }

    /**
     * Opens a grid column
     *
     * @param int $size The number of segments this column should span.
     * @param string $id An id to give the column.
     * @param string $class A class to give the column.
     * @return string
     */
    public function grid_column_start($size, $id = null, $class = null) {

        if ($id == 'course-detail') {
            $size = 12;
            $bootstrapclass = 'col-md-'.$size;
        } else {
            $bootstrapclass = 'd-flex flex-wrap px-3 mb-3';
        }

        $yuigridclass = "col-sm";
        if (in_array($size, [4, 5, 7])) {
            $yuigridclass = "col-12 col-lg-6";
        }

        if (is_null($class)) {
            $class = $yuigridclass . ' ' . $bootstrapclass;
        } else {
            $class .= ' ' . $yuigridclass . ' ' . $bootstrapclass;
        }
        $attributes = array();
        if (!is_null($id)) {
            $attributes['id'] = $id;
        }
        return html_writer::start_div($class . " grid_column_start", $attributes);
    }

    /**
     * Closes a grid column.
     *
     * @return string
     */
    public function grid_column_end() {
        return html_writer::end_div();
    }

    /**
     * Renders an action_icon.
     *
     * This function uses the {@link core_renderer::action_link()} method for the
     * most part. What it does different is prepare the icon as HTML and use it
     * as the link text.
     *
     * @param string|moodle_url $url A string URL or moodel_url
     * @param pix_icon $pixicon
     * @param component_action $action
     * @param array $attributes associative array of html link attributes + disabled
     * @param bool $linktext show title next to image in link
     * @return string HTML fragment
     */
    public function action_icon($url, pix_icon $pixicon, ?component_action $action = null,
                                ?array $attributes = null, $linktext = false) {
        if (!($url instanceof moodle_url)) {
            $url = new moodle_url($url);
        }
        $attributes = (array)$attributes;

        if (empty($attributes['class'])) {
            // Let devs override the class via $attributes.
            $attributes['class'] = 'action-icon';
        }

        $icon = $this->render($pixicon);

        if ($linktext) {
            $text = $pixicon->attributes['alt'];
        } else {
            $text = '';
        }

        return $this->action_link($url, $icon.$text, $action, $attributes);
    }

    /**
     * Displays a view mode selector.
     *
     * @param array $modes An array of view modes.
     * @param string $currentmode The current view mode.
     * @param moodle_url $url The URL to use when changing actions. Defaults to the page URL.
     * @param string $param The param name.
     * @return string
     */
    public function view_mode_selector(array $modes, $currentmode, ?moodle_url $url = null, $param = 'view') {
        if ($url === null) {
            $url = $this->page->url;
        }

        $menu = new action_menu;
        $menu->attributes['class'] .= ' view-mode-selector vms ms-1';

        $selected = null;
        foreach ($modes as $mode => $modestr) {
            $attributes = array(
                'class' => 'vms-mode',
                'data-mode' => $mode
            );
            if ($currentmode === $mode) {
                $attributes['class'] .= ' currentmode';
                $selected = $modestr;
            }
            if ($selected === null) {
                $selected = $modestr;
            }
            $modeurl = new moodle_url($url, array($param => $mode));
            if ($mode === 'default') {
                $modeurl->remove_params($param);
            }
            $menu->add(new action_menu_link_secondary($modeurl, null, $modestr, $attributes));
        }

        $menu->set_menu_trigger($selected);

        $html = html_writer::start_div('view-mode-selector vms d-flex');
        $html .= get_string('viewing').' '.$this->render($menu);
        $html .= html_writer::end_div();

        return $html;
    }

    /**
     * Displays a search result listing.
     *
     * @param array $courses The courses to display.
     * @param int $totalcourses The total number of courses to display.
     * @param core_course_list_element $course The currently selected course if there is one.
     * @param int $page The current page, starting at 0.
     * @param int $perpage The number of courses to display per page.
     * @param string $search The string we are searching for.
     * @return string
     */
    public function search_listing(array $courses, $totalcourses, ?core_course_list_element $course = null, $page = 0, $perpage = 20,
            $search = '') {
        $page = max($page, 0);
        $perpage = max($perpage, 2);
        $totalpages = ceil($totalcourses / $perpage);
        if ($page > $totalpages - 1) {
            $page = $totalpages - 1;
        }
        $courseid = isset($course) ? $course->id : null;
        $first = true;
        $last = false;
        $i = $page * $perpage;

        $html  = html_writer::start_div('course-listing w-100', array(
                'data-category' => 'search',
                'data-page' => $page,
                'data-totalpages' => $totalpages,
                'data-totalcourses' => $totalcourses
        ));
        $html .= html_writer::tag('h3', get_string('courses'));
        $html .= $this->search_pagination($totalcourses, $page, $perpage);
        $html .= html_writer::start_tag('ul', array('class' => 'ml'));
        foreach ($courses as $listitem) {
            $i++;
            if ($i == $totalcourses) {
                $last = true;
            }
            $html .= $this->search_listitem($listitem, $courseid, $first, $last);
            $first = false;
        }
        $html .= html_writer::end_tag('ul');
        $html .= $this->search_pagination($totalcourses, $page, $perpage, true, $search);
        $html .= $this->course_search_bulk_actions();
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Displays pagination for search results.
     *
     * @param int $totalcourses The total number of courses to be displayed.
     * @param int $page The current page.
     * @param int $perpage The number of courses being displayed.
     * @param bool $showtotals Whether or not to print total information.
     * @param string $search The string we are searching for.
     * @return string
     */
    protected function search_pagination($totalcourses, $page, $perpage, $showtotals = false, $search = '') {
        $html = '';
        $totalpages = ceil($totalcourses / $perpage);
        if ($showtotals) {
            if ($totalpages == 0) {
                $str = get_string('nocoursesfound', 'moodle', s($search));
            } else if ($totalpages == 1) {
                $str = get_string('showingacourses', 'moodle', $totalcourses);
            } else {
                $a = new stdClass;
                $a->start = ($page * $perpage) + 1;
                $a->end = min((($page + 1) * $perpage), $totalcourses);
                $a->total = $totalcourses;
                $str = get_string('showingxofycourses', 'moodle', $a);
            }
            $html .= html_writer::div($str, 'listing-pagination-totals text-muted');
        }

        if ($totalcourses < $perpage) {
            return $html;
        }
        $aside = 2;
        $span = $aside * 2 + 1;
        $start = max($page - $aside, 0);
        $end = min($page + $aside, $totalpages - 1);
        if (($end - $start) < $span) {
            if ($start == 0) {
                $end = min($totalpages - 1, $span - 1);
            } else if ($end == ($totalpages - 1)) {
                $start = max(0, $end - $span + 1);
            }
        }
        $items = array();
        $baseurl = $this->page->url;
        if ($page > 0) {
            $items[] = $this->action_button(new moodle_url($baseurl, array('page' => 0)), get_string('first'));
            $items[] = $this->action_button(new moodle_url($baseurl, array('page' => $page - 1)), get_string('prev'));
            $items[] = '...';
        }
        for ($i = $start; $i <= $end; $i++) {
            $class = '';
            if ($page == $i) {
                $class = 'active-page';
            }
            $items[] = $this->action_button(new moodle_url($baseurl, array('page' => $i)), $i + 1, null, $class);
        }
        if ($page < ($totalpages - 1)) {
            $items[] = '...';
            $items[] = $this->action_button(new moodle_url($baseurl, array('page' => $page + 1)), get_string('next'));
            $items[] = $this->action_button(new moodle_url($baseurl, array('page' => $totalpages - 1)), get_string('last'));
        }

        $html .= html_writer::div(join('', $items), 'listing-pagination');
        return $html;
    }

    /**
     * Renderers a search result course list item.
     *
     * This function will be called for every course being displayed by course_listing.
     *
     * @param core_course_list_element $course The course to produce HTML for.
     * @param int $selectedcourse The id of the currently selected course.
     * @return string
     */
    public function search_listitem(core_course_list_element $course, $selectedcourse) {

        $text = $course->get_formatted_name();
        $attributes = array(
                'class' => 'listitem listitem-course list-group-item list-group-item-action',
                'data-id' => $course->id,
                'data-selected' => ($selectedcourse == $course->id) ? '1' : '0',
                'data-visible' => $course->visible ? '1' : '0'
        );
        $bulkcourseinput = '';
        if (core_course_category::get($course->category)->can_move_courses_out_of()) {
            $bulkcourseinput = array(
                    'type' => 'checkbox',
                    'id' => 'coursesearchlistitem' . $course->id,
                    'name' => 'bc[]',
                    'value' => $course->id,
                    'class' => 'bulk-action-checkbox form-check-input',
                    'data-action' => 'select'
            );
        }
        $viewcourseurl = new moodle_url($this->page->url, array('courseid' => $course->id));
        $categoryname = core_course_category::get($course->category)->get_formatted_name();

        $html  = html_writer::start_tag('li', $attributes);
        $html .= html_writer::start_div('clearfix');
        $html .= html_writer::start_div('float-start');
        if ($bulkcourseinput) {
            $html .= html_writer::start_div('form-check me-1');
            $html .= html_writer::empty_tag('input', $bulkcourseinput);
            $labeltext = html_writer::span(get_string('bulkactionselect', 'moodle', $text), 'visually-hidden');
            $html .= html_writer::tag('label', $labeltext, array(
                'class' => 'form-check-label',
                'for' => 'coursesearchlistitem' . $course->id));
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div();
        $html .= html_writer::link($viewcourseurl, $text, array('class' => 'float-start coursename aalink'));
        $html .= html_writer::tag('span', $categoryname, array('class' => 'float-start ms-3 text-muted'));
        $html .= html_writer::start_div('float-end');
        $html .= $this->search_listitem_actions($course);
        $html .= html_writer::tag('span', s($course->idnumber), array('class' => 'text-muted idnumber'));
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $html .= html_writer::end_tag('li');
        return $html;
    }

    /**
     * Renderers actions for individual course actions.
     *
     * @param core_course_list_element  $course The course to renderer actions for.
     * @return string
     */
    public function search_listitem_actions(core_course_list_element $course) {
        $baseurl = new moodle_url(
            '/course/managementsearch.php',
            array('courseid' => $course->id, 'categoryid' => $course->category, 'sesskey' => sesskey())
        );
        $actions = array();
        // Edit.
        if ($course->can_access()) {
            if ($course->can_edit()) {
                $actions[] = $this->output->action_icon(
                    new moodle_url('/course/edit.php', array('id' => $course->id)),
                    new pix_icon('t/edit', get_string('edit')),
                    null,
                    array('class' => 'action-edit')
                );
            }
            // Delete.
            if ($course->can_delete()) {
                $actions[] = $this->output->action_icon(
                    new moodle_url('/course/delete.php', array('id' => $course->id)),
                    new pix_icon('t/delete', get_string('delete')),
                    null,
                    array('class' => 'action-delete')
                );
            }
            // Show/Hide.
            if ($course->can_change_visibility()) {
                    $actions[] = $this->output->action_icon(
                        new moodle_url($baseurl, array('action' => 'hidecourse')),
                        new pix_icon('t/hide', get_string('hide')),
                        null,
                        array('data-action' => 'hide', 'class' => 'action-hide')
                    );
                    $actions[] = $this->output->action_icon(
                        new moodle_url($baseurl, array('action' => 'showcourse')),
                        new pix_icon('t/show', get_string('show')),
                        null,
                        array('data-action' => 'show', 'class' => 'action-show')
                    );
            }
        }
        if (empty($actions)) {
            return '';
        }
        return html_writer::span(join('', $actions), 'course-item-actions item-actions');
    }

    /**
     * Creates access hidden skip to links for the displayed sections.
     *
     * @param bool $displaycategorylisting
     * @param bool $displaycourselisting
     * @param bool $displaycoursedetail
     * @return string
     */
    public function accessible_skipto_links($displaycategorylisting, $displaycourselisting, $displaycoursedetail) {
        $html = html_writer::start_div('skiplinks accesshide');
        $url = new moodle_url($this->page->url);
        if ($displaycategorylisting) {
            $url->set_anchor('category-listing');
            $html .= html_writer::link($url, get_string('skiptocategorylisting'), array('class' => 'skip'));
        }
        if ($displaycourselisting) {
            $url->set_anchor('course-listing');
            $html .= html_writer::link($url, get_string('skiptocourselisting'), array('class' => 'skip'));
        }
        if ($displaycoursedetail) {
            $url->set_anchor('course-detail');
            $html .= html_writer::link($url, get_string('skiptocoursedetails'), array('class' => 'skip'));
        }
        $html .= html_writer::end_div();
        return $html;
    }

    /**
     * Render the tertiary nav for the manage categories page.
     *
     * @param \core_course\output\manage_categories_action_bar $actionbar
     * @return string The renderered template
     */
    public function render_action_bar(\core_course\output\manage_categories_action_bar $actionbar): string {
        return $this->render_from_template('core_course/manage_category_actionbar', $actionbar->export_for_template($this));
    }
}
