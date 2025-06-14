<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\UI\Dashboard;

use Gibbon\Http\Url;
use Gibbon\View\View;
use Gibbon\Services\Format;
use Gibbon\Forms\OutputableInterface;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\System\HookGateway;
use Gibbon\Domain\Planner\PlannerEntryGateway;
use Gibbon\Domain\School\SchoolYearTermGateway;
use Gibbon\Domain\System\AlertLevelGateway;
use Gibbon\Domain\System\SettingGateway;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use Gibbon\Tables\Prefab\TodaysLessonsTable;
use Gibbon\Data\Validator;

/**
 * Parent Dashboard View Composer
 *
 * @version  v18
 * @since    v18
 */
class ParentDashboard implements OutputableInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected $db;
    protected $session;
    protected $settingGateway;

    /**
     * @var View
     */
    private $view;

    public function __construct(Connection $db, Session $session, SettingGateway $settingGateway, View $view)
    {
        $this->db = $db;
        $this->session = $session;
        $this->settingGateway = $settingGateway;
        $this->view = $view;
    }

    public function getOutput()
    {
        $guid = $this->session->get('guid');
        $connection2 = $this->db->getConnection();
        $session = $this->session;

        $students = [];

        $data = ['gibbonPersonID' => $this->session->get('gibbonPersonID')];
        $sql = "SELECT * FROM gibbonFamilyAdult WHERE
            gibbonPersonID=:gibbonPersonID AND childDataAccess='Y'";
        $result = $connection2->prepare($sql);
        $result->execute($data);

        if ($result->rowCount() > 0) {
            // Get child list
            while ($row = $result->fetch()) {

                $dataChild = [
                    'gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID'),
                    'gibbonFamilyID' => $row['gibbonFamilyID'],
                    'today' => date('Y-m-d'),
                ];
                $sqlChild = "SELECT
                    gibbonPerson.gibbonPersonID,image_240, surname,
                    preferredName, dateStart,
                    gibbonYearGroup.nameShort AS yearGroup,
                    gibbonFormGroup.nameShort AS formGroup,
                    gibbonFormGroup.website AS formGroupWebsite,
                    gibbonFormGroup.gibbonFormGroupID
                    FROM gibbonFamilyChild JOIN gibbonPerson ON (gibbonFamilyChild.gibbonPersonID=gibbonPerson.gibbonPersonID)
                    JOIN gibbonStudentEnrolment ON (gibbonPerson.gibbonPersonID=gibbonStudentEnrolment.gibbonPersonID)
                    JOIN gibbonYearGroup ON (gibbonStudentEnrolment.gibbonYearGroupID=gibbonYearGroup.gibbonYearGroupID)
                    JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID=gibbonFormGroup.gibbonFormGroupID)
                    WHERE gibbonStudentEnrolment.gibbonSchoolYearID=:gibbonSchoolYearID
                    AND gibbonFamilyID=:gibbonFamilyID
                    AND gibbonPerson.status='Full'
                    AND (dateStart IS NULL OR dateStart<=:today)
                    AND (dateEnd IS NULL OR dateEnd>=:today)
                    ORDER BY surname, preferredName ";
                $resultChild = $connection2->prepare($sqlChild);
                $resultChild->execute($dataChild);

                while ($rowChild = $resultChild->fetch()) {
                    $students[] = $rowChild;
                }
            }
        }

        $output = '';

        if (count($students) > 0) {
            include_once $this->session->get('absolutePath').'/modules/Timetable/moduleFunctions.php';

            $output .= '<h2>'.__('Parent Dashboard').'</h2>';

            foreach ($students as $student) {
                $output .= '<h4>'.
                    $student['preferredName'].' '.$student['surname'].
                    '</h4>';

                $output .= '<section class="flex flex-col sm:flex-row">';

                $output .= '<div class="w-24 text-center mx-auto mb-4 sm:ml-0 sm:mr-4">'.
                    Format::userPhoto($student['image_240'], 75).
                    "<div style='height: 5px'></div>".
                    "<span style='font-size: 70%'>".
                    "<a href='".Url::fromModuleRoute('Students', 'student_view_details')->withQueryParam('gibbonPersonID', $student['gibbonPersonID'])."'>".__('Student Profile').'</a><br/>';

                if (isActionAccessible($guid, $connection2, '/modules/Form Groups/formGroups_details.php')) {
                    $output .= "<a href='".Url::fromModuleRoute('Form Groups', 'formGroups_details')->withQueryParam('gibbonFormGroupID', $student['gibbonFormGroupID'])."'>".__('Form Group').' ('.$student['formGroup'].')</a><br/>';
                }
                if ($student['formGroupWebsite'] != '') {
                    $output .= "<a target='_blank' href='".$student['formGroupWebsite']."'>".$student['formGroup'].' '.__('Website').'</a>';
                }

                $output .= '</span>';
                $output .= '</div>';
                $output .= '<div class="flex-grow mb-6">';
                $dashboardContents = $this->renderChildDashboard($student['gibbonPersonID'], $student['dateStart']);
                if ($dashboardContents == false) {
                    $output .= "<div class='error'>".__('There are no records to display.').'</div>';
                } else {
                    $output .= $dashboardContents;
                }
                $output .= '</div>';
                $output .= '</section><br class="clearfix"/>';
            }
        }

        return $output;
    }

    protected function renderChildDashboard($gibbonPersonID, $dateStart)
    {
        $guid = $this->session->get('guid');
        $connection2 = $this->db->getConnection();
        $session = $this->session;

        $homeworkNameSingular = $this->settingGateway->getSettingByScope('Planner', 'homeworkNameSingular');

        $return = false;

        /**
         * @var AlertLevelGateway
         */
        $alertLevelGateway = $this->getContainer()->get(AlertLevelGateway::class);
        $alert = $alertLevelGateway->getByID(AlertLevelGateway::LEVEL_MEDIUM);
        $entryCount = 0;

        //PREPARE PLANNER SUMMARY
        $classes = false;

        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {
            $plannerOutput = "<span style='font-size: 85%; font-weight: bold'>".__('Today\'s Classes')."</span> . <span style='font-size: 70%'><a href='".Url::fromModuleRoute('Planner', 'planner')->withQueryParam('search', $gibbonPersonID)."'>".__('View Planner').'</a></span>';

            $date = date('Y-m-d');
            if (isSchoolOpen($guid, $date, $connection2) == true && $this->session->has('username')) {
                $classes = true;
                $plannerOutput = $this
                    ->getContainer()
                    ->get(TodaysLessonsTable::class)
                    ->create($session->get('gibbonSchoolYearID'), $gibbonPersonID, 'Parent')
                    ->getOutput();   
            }
        }

        //PREPARE RECENT GRADES
        $grades = false;

        if (isActionAccessible($guid, $connection2, '/modules/Markbook/markbook_view.php')) {
            $gradesOutput = "<div style='margin-top: 20px'><span style='font-size: 85%; font-weight: bold'>".__('Recent Feedback')."</span> . <span style='font-size: 70%'><a href='" . Url::fromModuleRoute('Markbook', 'markbook_view')->withQueryParam('search', $gibbonPersonID) . "'>".__('View Markbook').'</a></span></div>';

            //Get settings
            $enableEffort = $this->settingGateway->getSettingByScope('Markbook', 'enableEffort');
            $enableRubrics = $this->settingGateway->getSettingByScope('Markbook', 'enableRubrics');
            $attainmentAlternativeName = $this->settingGateway->getSettingByScope('Markbook', 'attainmentAlternativeName');
            $attainmentAlternativeNameAbrev = $this->settingGateway->getSettingByScope('Markbook', 'attainmentAlternativeNameAbrev');
            $effortAlternativeName = $this->settingGateway->getSettingByScope('Markbook', 'effortAlternativeName');
            $effortAlternativeNameAbrev = $this->settingGateway->getSettingByScope('Markbook', 'effortAlternativeNameAbrev');
            $enableModifiedAssessment = $this->settingGateway->getSettingByScope('Markbook', 'enableModifiedAssessment');

            try {
                $dataEntry = array('gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID'), 'gibbonPersonID' => $gibbonPersonID);
                $sqlEntry = "SELECT *, gibbonMarkbookColumn.comment AS commentOn, gibbonMarkbookColumn.uploadedResponse AS uploadedResponseOn, gibbonMarkbookEntry.comment AS comment FROM gibbonMarkbookEntry JOIN gibbonMarkbookColumn ON (gibbonMarkbookEntry.gibbonMarkbookColumnID=gibbonMarkbookColumn.gibbonMarkbookColumnID) JOIN gibbonCourseClass ON (gibbonMarkbookColumn.gibbonCourseClassID=gibbonCourseClass.gibbonCourseClassID) JOIN gibbonCourse ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID) WHERE gibbonSchoolYearID=:gibbonSchoolYearID AND gibbonPersonIDStudent=:gibbonPersonID AND complete='Y' AND completeDate<='".date('Y-m-d')."' AND viewableParents='Y' ORDER BY completeDate DESC LIMIT 0, 3";
                $resultEntry = $connection2->prepare($sqlEntry);
                $resultEntry->execute($dataEntry);
            } catch (\PDOException $e) {
            }
            if ($resultEntry->rowCount() > 0) {
                $showParentAttainmentWarning = $this->settingGateway->getSettingByScope('Markbook', 'showParentAttainmentWarning');
                $showParentEffortWarning = $this->settingGateway->getSettingByScope('Markbook', 'showParentEffortWarning');
                $grades = true;
                $gradesOutput .= "<table cellspacing='0' style='margin: 3px 0px; width: 100%'>";
                $gradesOutput .= "<tr class='head'>";
                $gradesOutput .= "<th style='width: 120px'>";
                $gradesOutput .= __('Assessment');
                $gradesOutput .= '</th>';
                if ($enableModifiedAssessment == 'Y') {
                    $gradesOutput .= "<th style='width: 75px'>";
                    $gradesOutput .= __('Modified');
                    $gradesOutput .= '</th>';
                }
                $gradesOutput .= "<th style='width: 75px'>";
                if ($attainmentAlternativeName != '') {
                    $gradesOutput .= $attainmentAlternativeName;
                } else {
                    $gradesOutput .= __('Attainment');
                }
                $gradesOutput .= '</th>';
                if ($enableEffort == 'Y') {
                    $gradesOutput .= "<th style='width: 75px'>";
                    if ($effortAlternativeName != '') {
                        $gradesOutput .= $effortAlternativeName;
                    } else {
                        $gradesOutput .= __('Effort');
                    }
                }
                $gradesOutput .= '</th>';
                $gradesOutput .= '<th>';
                $gradesOutput .= __('Comment');
                $gradesOutput .= '</th>';
                $gradesOutput .= "<th style='width: 75px'>";
                $gradesOutput .= __('Submission');
                $gradesOutput .= '</th>';
                $gradesOutput .= '</tr>';

                $count3 = 0;
                while ($rowEntry = $resultEntry->fetch()) {
                    if ($count3 % 2 == 0) {
                        $rowNum = 'even';
                    } else {
                        $rowNum = 'odd';
                    }
                    ++$count3;

                    $gradesOutput .= "<a name='".$rowEntry['gibbonMarkbookEntryID']."'></a>";

                    $gradesOutput .= "<tr class=$rowNum>";
                    $gradesOutput .= '<td>';
                    $gradesOutput .= "<span title='".htmlPrep($rowEntry['description'])."'>".$rowEntry['name'].'</span><br/>';
                    $gradesOutput .= "<span style='font-size: 90%; font-style: italic; font-weight: normal'>";
                    $gradesOutput .= __('Marked on').' '.Format::date($rowEntry['completeDate']).'<br/>';
                    $gradesOutput .= '</span>';
                    $gradesOutput .= '</td>';
                    if ($enableModifiedAssessment == 'Y') {
                        if (!is_null($rowEntry['modifiedAssessment'])) {
                            $gradesOutput .= "<td>";
                            $gradesOutput .= Format::yesNo($rowEntry['modifiedAssessment']);
                            $gradesOutput .= '</td>';
                        }
                        else {
                            $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: center'>";
                            $gradesOutput .= __('N/A');
                            $gradesOutput .= '</td>';
                        }
                    }
                    if ($rowEntry['attainment'] == 'N' or ($rowEntry['gibbonScaleIDAttainment'] == '' and $rowEntry['gibbonRubricIDAttainment'] == '')) {
                        $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: center'>";
                        $gradesOutput .= __('N/A');
                        $gradesOutput .= '</td>';
                    } else {
                        $gradesOutput .= "<td style='text-align: center'>";
                        $attainmentExtra = '';

                            $dataAttainment = array('gibbonScaleID' => $rowEntry['gibbonScaleIDAttainment']);
                            $sqlAttainment = 'SELECT * FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID';
                            $resultAttainment = $connection2->prepare($sqlAttainment);
                            $resultAttainment->execute($dataAttainment);
                        if ($resultAttainment->rowCount() == 1) {
                            $rowAttainment = $resultAttainment->fetch();
                            $attainmentExtra = '<br/>'.__($rowAttainment['usage']);
                        }
                        $styleAttainment = "style='font-weight: bold'";
                        if ($rowEntry['attainmentConcern'] == 'Y' and $showParentAttainmentWarning == 'Y') {
                            $styleAttainment = "style='color: ".$alert['color'].'; font-weight: bold; border: 2px solid '.$alert['color'].'; padding: 2px 4px; background-color: '.$alert['colorBG']."'";
                        } elseif ($rowEntry['attainmentConcern'] == 'P' and $showParentAttainmentWarning == 'Y') {
                            $styleAttainment = "style='color: #390; font-weight: bold; border: 2px solid #390; padding: 2px 4px; background-color: #D4F6DC'";
                        }
                        $gradesOutput .= "<div $styleAttainment>".$rowEntry['attainmentValue'];
                        if ($rowEntry['gibbonRubricIDAttainment'] != '' AND $enableRubrics =='Y') {
                            $gradesOutput .= "<a class='thickbox' href='" . Url::fromHandlerModuleRoute('fullscreen.php', 'Markbook', 'markbook_view_rubric')->withQueryParams([
                                'gibbonRubricID' => $rowEntry['gibbonRubricIDAttainment'],
                                'gibbonCourseClassID' => $rowEntry['gibbonCourseClassID'],
                                'gibbonMarkbookColumnID' => $rowEntry['gibbonMarkbookColumnID'],
                                'gibbonPersonID' => $gibbonPersonID,
                                'mark' => 'FALSE',
                                'type' => 'attainment',
                                'width' => 1100,
                                'height' => 550,
                            ]) . "'><img style='margin-bottom: -3px; margin-left: 3px' title='View Rubric' src='./themes/".$this->session->get('gibbonThemeName')."/img/rubric.png'/></a>";
                        }
                        $gradesOutput .= '</div>';
                        if ($rowEntry['attainmentValue'] != '') {
                            $gradesOutput .= "<div class='detailItem' style='font-size: 75%; font-style: italic; margin-top: 2px'><b>".htmlPrep(__($rowEntry['attainmentDescriptor'])).'</b>'.__($attainmentExtra).'</div>';
                        }
                        $gradesOutput .= '</td>';
                    }
                    if ($enableEffort == 'Y') {
                        if ($rowEntry['effort'] == 'N' or ($rowEntry['gibbonScaleIDEffort'] == '' and $rowEntry['gibbonRubricIDEffort'] == '')) {
                            $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: center'>";
                            $gradesOutput .= __('N/A');
                            $gradesOutput .= '</td>';
                        } else {
                            $gradesOutput .= "<td style='text-align: center'>";
                            $effortExtra = '';

                                $dataEffort = array('gibbonScaleID' => $rowEntry['gibbonScaleIDEffort']);
                                $sqlEffort = 'SELECT * FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID';
                                $resultEffort = $connection2->prepare($sqlEffort);
                                $resultEffort->execute($dataEffort);
                            if ($resultEffort->rowCount() == 1) {
                                $rowEffort = $resultEffort->fetch();
                                $effortExtra = '<br/>'.__($rowEffort['usage']);
                            }
                            $styleEffort = "style='font-weight: bold'";
                            if ($rowEntry['effortConcern'] == 'Y' and $showParentEffortWarning == 'Y') {
                                $styleEffort = "style='color: ".$alert['color'].'; font-weight: bold; border: 2px solid '.$alert['color'].'; padding: 2px 4px; background-color: '.$alert['colorBG']."'";
                            }
                            $gradesOutput .= "<div $styleEffort>".$rowEntry['effortValue'];
                            if ($rowEntry['gibbonRubricIDEffort'] != '' AND $enableRubrics =='Y') {
                                $gradesOutput .= "<a class='thickbox' href='".$this->session->get('absoluteURL').'/fullscreen.php?q=/modules/Markbook/markbook_view_rubric.php&gibbonRubricID='.$rowEntry['gibbonRubricIDEffort'].'&gibbonCourseClassID='.$rowEntry['gibbonCourseClassID'].'&gibbonMarkbookColumnID='.$rowEntry['gibbonMarkbookColumnID'].'&gibbonPersonID='.$gibbonPersonID."&mark=FALSE&type=effort&width=1100&height=550'><img style='margin-bottom: -3px; margin-left: 3px' title='View Rubric' src='./themes/".$this->session->get('gibbonThemeName')."/img/rubric.png'/></a>";
                            }
                            $gradesOutput .= '</div>';
                            if ($rowEntry['effortValue'] != '') {
                                $gradesOutput .= "<div class='detailItem' style='font-size: 75%; font-style: italic; margin-top: 2px'><b>".htmlPrep(__($rowEntry['effortDescriptor'])).'</b>'.__($effortExtra).'</div>';
                            }
                            $gradesOutput .= '</td>';
                        }
                    }
                    if ($rowEntry['commentOn'] == 'N' and $rowEntry['uploadedResponseOn'] == 'N') {
                        $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: left'>";
                        $gradesOutput .= __('N/A');
                        $gradesOutput .= '</td>';
                    } else {
                        $gradesOutput .= '<td>';
                        if ($rowEntry['comment'] != '') {
                            if (mb_strlen($rowEntry['comment']) > 50) {
                                $gradesOutput .= "<script type='text/javascript'>";
                                $gradesOutput .= '$(document).ready(function(){';
                                $gradesOutput .= "\$(\".comment-$entryCount-$gibbonPersonID\").hide();";
                                $gradesOutput .= "\$(\".show_hide-$entryCount-$gibbonPersonID\").fadeIn(1000);";
                                $gradesOutput .= "\$(\".show_hide-$entryCount-$gibbonPersonID\").click(function(){";
                                $gradesOutput .= "\$(\".comment-$entryCount-$gibbonPersonID\").fadeToggle(1000);";
                                $gradesOutput .= '});';
                                $gradesOutput .= '});';
                                $gradesOutput .= '</script>';
                                $gradesOutput .= '<span>'.mb_substr($rowEntry['comment'], 0, 50).'...<br/>';
                                $gradesOutput .= "<a title='".__('View Description')."' class='show_hide-$entryCount-$gibbonPersonID' onclick='return false;' href='#'>".__('Read more').'</a></span><br/>';
                            } else {
                                $gradesOutput .= nl2br($rowEntry['comment']);
                            }
                            $gradesOutput .= '<br/>';
                        }
                        if ($rowEntry['response'] != '') {
                            $gradesOutput .= "<a title='".__('Uploaded Response')."' href='".$this->session->get('absoluteURL').'/'.$rowEntry['response']."'>".__('Uploaded Response').'</a><br/>';
                        }
                        $gradesOutput .= '</td>';
                    }
                    if ($rowEntry['gibbonPlannerEntryID'] == 0) {
                        $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: left'>";
                        $gradesOutput .= __('N/A');
                        $gradesOutput .= '</td>';
                    } else {
                        try {
                            $dataSub = array('gibbonPlannerEntryID' => $rowEntry['gibbonPlannerEntryID']);
                            $sqlSub = "SELECT * FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID=:gibbonPlannerEntryID AND homeworkSubmission='Y'";
                            $resultSub = $connection2->prepare($sqlSub);
                            $resultSub->execute($dataSub);
                        } catch (\PDOException $e) {
                        }
                        if ($resultSub->rowCount() != 1) {
                            $gradesOutput .= "<td class='dull' style='color: #bbb; text-align: left'>";
                            $gradesOutput .= __('N/A');
                            $gradesOutput .= '</td>';
                        } else {
                            $gradesOutput .= '<td>';
                            $rowSub = $resultSub->fetch();

                            try {
                                $dataWork = array('gibbonPlannerEntryID' => $rowEntry['gibbonPlannerEntryID'], 'gibbonPersonID' => $gibbonPersonID);
                                $sqlWork = 'SELECT * FROM gibbonPlannerEntryHomework WHERE gibbonPlannerEntryID=:gibbonPlannerEntryID AND gibbonPersonID=:gibbonPersonID ORDER BY count DESC';
                                $resultWork = $connection2->prepare($sqlWork);
                                $resultWork->execute($dataWork);
                            } catch (\PDOException $e) {
                            }
                            if ($resultWork->rowCount() > 0) {
                                $rowWork = $resultWork->fetch();

                                if ($rowWork['status'] == 'Exemption') {
                                    $linkText = __('Exemption');
                                } elseif ($rowWork['version'] == 'Final') {
                                    $linkText = __('Final');
                                } else {
                                    $linkText = __('Draft').' '.$rowWork['count'];
                                }

                                $style = '';
                                $status = 'On Time';
                                if ($rowWork['status'] == 'Exemption') {
                                    $status = __('Exemption');
                                } elseif ($rowWork['status'] == 'Late') {
                                    $style = "style='color: #ff0000; font-weight: bold; border: 2px solid #ff0000; padding: 2px 4px'";
                                    $status = __('Late');
                                }

                                if ($rowWork['type'] == 'File') {
                                    $gradesOutput .= "<span title='".$rowWork['version'].". $status. ".sprintf(__('Submitted at %1$s on %2$s'), substr($rowWork['timestamp'], 11, 5), Format::date(substr($rowWork['timestamp'], 0, 10)))."' $style><a href='".$this->session->get('absoluteURL').'/'.$rowWork['location']."'>$linkText</a></span>";
                                } elseif ($rowWork['type'] == 'Link') {
                                    $gradesOutput .= "<span title='".$rowWork['version'].". $status. ".sprintf(__('Submitted at %1$s on %2$s'), substr($rowWork['timestamp'], 11, 5), Format::date(substr($rowWork['timestamp'], 0, 10)))."' $style><a target='_blank' href='".$rowWork['location']."'>$linkText</a></span>";
                                } else {
                                    $gradesOutput .= "<span title='$status. ".sprintf(__('Recorded at %1$s on %2$s'), substr($rowWork['timestamp'], 11, 5), Format::date(substr($rowWork['timestamp'], 0, 10)))."' $style>$linkText</span>";
                                }
                            } else {
                                if (date('Y-m-d H:i:s') < $rowSub['homeworkDueDateTime']) {
                                    $gradesOutput .= "<span title='Pending'>".__('Pending').'</span>';
                                } else {
                                    if (!empty($dateStart) && $dateStart > $rowSub['date']) {
                                        $gradesOutput .= "<span title='".__('Student joined school after assessment was given.')."' style='color: #000; font-weight: normal; border: 2px none #ff0000; padding: 2px 4px'>".__('NA').'</span>';
                                    } else {
                                        if ($rowSub['homeworkSubmissionRequired'] == 'Required') {
                                            $gradesOutput .= "<div style='color: #ff0000; font-weight: bold; border: 2px solid #ff0000; padding: 2px 4px; margin: 2px 0px'>".__('Incomplete').'</div>';
                                        } else {
                                            $gradesOutput .= __('Not submitted online');
                                        }
                                    }
                                }
                            }
                            $gradesOutput .= '</td>';
                        }
                    }
                    $gradesOutput .= '</tr>';
                    if ($rowEntry['commentOn'] == 'Y' && strlen($rowEntry['comment']) > 50) {
                        $gradesOutput .= "<tr class='comment-$entryCount-$gibbonPersonID' id='comment-$entryCount-$gibbonPersonID'>";
                        $gradesOutput .= '<td colspan=6>';
                        $gradesOutput .= nl2br($rowEntry['comment']);
                        $gradesOutput .= '</td>';
                        $gradesOutput .= '</tr>';
                    }
                    ++$entryCount;
                }

                $gradesOutput .= '</table>';
            }
            if ($grades == false) {
                $gradesOutput .= Format::alert(__('There are no records to display.'), 'empty');
            }
        }

        //PREPARE UPCOMING DEADLINES
        $deadlines = false;
        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {

            $homeworkNamePlural = $this->settingGateway->getSettingByScope('Planner', 'homeworkNamePlural');
            $deadlinesOutput = "<div style='margin-top: 20px'><span style='font-size: 85%; font-weight: bold'>".__('Upcoming Due Dates')."</span> . <span style='font-size: 70%'><a href='".Url::fromModuleRoute('Planner', 'planner_deadlines')->withQueryParam('search', $gibbonPersonID)."'>".__('View {homeworkName}', ['homeworkName' => __($homeworkNamePlural)]).'</a></span></div>';


            $plannerGateway = $this->getContainer()->get(PlannerEntryGateway::class);
            $deadlines = $plannerGateway->selectUpcomingHomeworkByStudent($this->session->get('gibbonSchoolYearID'), $gibbonPersonID, 'viewableParents')->fetchAll();

            $deadlinesOutput .= $this->getContainer()->get('page')->fetchFromTemplate('ui/upcomingDeadlines.twig.html', [
                'gibbonPersonID' => $gibbonPersonID,
                'deadlines' => $deadlines,
            ]);
        }

        //PREPARE TIMETABLE
        $timetableOutput = '';
        if (isActionAccessible($guid, $connection2, '/modules/Timetable/tt_view.php')) {
            $date = date('Y-m-d');
            if (isset($_POST['ttDate'])) {
                $date = Format::dateConvert($_POST['ttDate']);
            }
            $params = '';
            if ($classes != false or $grades != false or $deadlines != false) {
                $params = '&tab=1';
            }

            $_POST = (new Validator(''))->sanitize($_POST);
            $jsonQuery = [
                'gibbonPersonID' => $gibbonPersonID,
                'gibbonTTID' => $_GET['gibbonTTID'] ?? '',
                'ttDate' => $_POST['ttDate'] ?? '',
            ];
            $apiEndpoint = (string)Url::fromHandlerRoute('index_tt_ajax.php')->withQueryParams($jsonQuery);

            $timetableOutput .= "<div hx-get='".$apiEndpoint."' hx-trigger='load' style='width: 100%; min-height: 40px; text-align: center'>";
            $timetableOutput .= "<img style='margin: 10px 0 5px 0' src='".$this->session->get('absoluteURL')."/themes/Default/img/loading.gif' alt='".__('Loading')."' onclick='return false;' /><br/><p style='text-align: center'>".__('Loading').'</p>';
            $timetableOutput .= '</div>';
        }

        //PREPARE ACTIVITIES
        $activities = false;
        $activitiesOutput = false;
        if (!(isActionAccessible($guid, $connection2, '/modules/Activities/activities_view.php'))) {
            $activitiesOutput .= "<div class='error'>";
            $activitiesOutput .= __('Your request failed because you do not have access to this action.');
            $activitiesOutput .= '</div>';
        } else {
            $activities = true;

            $activitiesOutput .= "<div class='linkTop'>";
            $activitiesOutput .= "<a href='".Url::fromModuleRoute('Activities', 'activities_view')->withQueryParam('gibbonPersonID', $gibbonPersonID).
                "'>".__('View Available Activities').'</a>';
            $activitiesOutput .= '</div>';

            $dateType = $this->settingGateway->getSettingByScope('Activities', 'dateType');
            if ($dateType == 'Term') {
                $maxPerTerm = $this->settingGateway->getSettingByScope('Activities', 'maxPerTerm');
            }
            try {
                $dataYears = array('gibbonPersonID' => $gibbonPersonID);
                $sqlYears = "SELECT * FROM gibbonStudentEnrolment JOIN gibbonSchoolYear ON (gibbonStudentEnrolment.gibbonSchoolYearID=gibbonSchoolYear.gibbonSchoolYearID) WHERE gibbonSchoolYear.status='Current' AND gibbonPersonID=:gibbonPersonID ORDER BY sequenceNumber DESC";
                $resultYears = $connection2->prepare($sqlYears);
                $resultYears->execute($dataYears);
            } catch (\PDOException $e) {
            }

            if ($resultYears->rowCount() < 1) {
                $activitiesOutput .= "<div class='error'>";
                $activitiesOutput .= __('There are no records to display.');
                $activitiesOutput .= '</div>';
            } else {
                $yearCount = 0;
                while ($rowYears = $resultYears->fetch()) {
                    ++$yearCount;
                    try {
                        $data = array('gibbonPersonID' => $gibbonPersonID, 'gibbonSchoolYearID' => $rowYears['gibbonSchoolYearID']);
                        $sql = "SELECT gibbonActivity.*, gibbonActivityStudent.status, NULL AS role FROM gibbonActivity JOIN gibbonActivityStudent ON (gibbonActivity.gibbonActivityID=gibbonActivityStudent.gibbonActivityID) WHERE gibbonActivityStudent.gibbonPersonID=:gibbonPersonID AND gibbonSchoolYearID=:gibbonSchoolYearID AND active='Y' ORDER BY name";
                        $result = $connection2->prepare($sql);
                        $result->execute($data);
                    } catch (\PDOException $e) {
                    }

                    if ($result->rowCount() < 1) {
                        $activitiesOutput .= "<div class='error'>";
                        $activitiesOutput .= __('There are no records to display.');
                        $activitiesOutput .= '</div>';
                    } else {
                        $activitiesOutput .= "<table cellspacing='0' style='width: 100%'>";
                        $activitiesOutput .= "<tr class='head'>";
                        $activitiesOutput .= '<th>';
                        $activitiesOutput .= __('Activity');
                        $activitiesOutput .= '</th>';
                        $activitiesOutput .= '<th>';
                        $activitiesOutput .= __('Type');
                        $activitiesOutput .= '</th>';
                        $activitiesOutput .= '<th>';
                        if ($dateType != 'Date') {
                            $activitiesOutput .= __('Term');
                        } else {
                            $activitiesOutput .= __('Dates');
                        }
                        $activitiesOutput .= '</th>';
                        $activitiesOutput .= '<th>';
                        $activitiesOutput .= __('Slots');
                        $activitiesOutput .= '</th>';
                        $activitiesOutput .= '<th>';
                        $activitiesOutput .= __('Status');
                        $activitiesOutput .= '</th>';
                        $activitiesOutput .= '</tr>';

                        $count = 0;
                        $rowNum = 'odd';
                        while ($row = $result->fetch()) {
                            if ($count % 2 == 0) {
                                $rowNum = 'even';
                            } else {
                                $rowNum = 'odd';
                            }
                            ++$count;

                            //COLOR ROW BY STATUS!
                            $activitiesOutput .= "<tr class=$rowNum>";
                            $activitiesOutput .= '<td>';
                            $activitiesOutput .= $row['name'];
                            $activitiesOutput .= '</td>';
                            $activitiesOutput .= '<td>';
                            $activitiesOutput .= trim($row['type'] ?? '');
                            $activitiesOutput .= '</td>';
                            $activitiesOutput .= '<td>';
                            if ($dateType != 'Date') {
                                /**
                                 * @var SchoolYearTermGateway
                                 */
                                $schoolYearTermGateway = $this->getContainer()->get(SchoolYearTermGateway::class);
                                $termList = $schoolYearTermGateway->getTermNamesByID($row['gibbonSchoolYearTermIDList']);
                                $activitiesOutput .= !empty($termList) ? implode('<br/>', $termList) : '-';
                            } else {
                                if (substr($row['programStart'], 0, 4) == substr($row['programEnd'], 0, 4)) {
                                    if (substr($row['programStart'], 5, 2) == substr($row['programEnd'], 5, 2)) {
                                        $activitiesOutput .= date('F', mktime(0, 0, 0, substr($row['programStart'], 5, 2))).' '.substr($row['programStart'], 0, 4);
                                    } else {
                                        $activitiesOutput .= date('F', mktime(0, 0, 0, substr($row['programStart'], 5, 2))).' - '.date('F', mktime(0, 0, 0, substr($row['programEnd'], 5, 2))).'<br/>'.substr($row['programStart'], 0, 4);
                                    }
                                } else {
                                    $activitiesOutput .= date('F', mktime(0, 0, 0, substr($row['programStart'], 5, 2))).' '.substr($row['programStart'], 0, 4).' -<br/>'.date('F', mktime(0, 0, 0, substr($row['programEnd'], 5, 2))).' '.substr($row['programEnd'], 0, 4);
                                }
                            }
                            $activitiesOutput .= '</td>';
                            $activitiesOutput .= '<td>';
                                try {
                                    $dataSlots = array('gibbonActivityID' => $row['gibbonActivityID']);
                                    $sqlSlots = 'SELECT gibbonActivitySlot.*, gibbonDaysOfWeek.name AS dayOfWeek, gibbonSpace.name AS facility FROM gibbonActivitySlot JOIN gibbonDaysOfWeek ON (gibbonActivitySlot.gibbonDaysOfWeekID=gibbonDaysOfWeek.gibbonDaysOfWeekID) LEFT JOIN gibbonSpace ON (gibbonActivitySlot.gibbonSpaceID=gibbonSpace.gibbonSpaceID) WHERE gibbonActivityID=:gibbonActivityID ORDER BY sequenceNumber';
                                    $resultSlots = $connection2->prepare($sqlSlots);
                                    $resultSlots->execute($dataSlots);
                                } catch (\PDOException $e) {
                                }
                                $count = 0;
                                while ($rowSlots = $resultSlots->fetch()) {
                                    $activitiesOutput .= '<b>'.$rowSlots['dayOfWeek'].'</b><br/>';
                                    $activitiesOutput .= '<i>'.__('Time').'</i>: '.substr($rowSlots['timeStart'], 0, 5).' - '.substr($rowSlots['timeEnd'], 0, 5).'<br/>';
                                    if ($rowSlots['gibbonSpaceID'] != '') {
                                        $activitiesOutput .= '<i>'.__('Location').'</i>: '.$rowSlots['facility'];
                                    } else {
                                        $activitiesOutput .= '<i>'.__('Location').'</i>: '.$rowSlots['locationExternal'];
                                    }
                                    ++$count;
                                }
                                if ($count == 0) {
                                    $activitiesOutput .= '<i>'.__('None').'</i>';
                                }
                            $activitiesOutput .= '</td>';
                            $activitiesOutput .= '<td>';
                            if ($row['status'] != '') {
                                $activitiesOutput .= $row['status'];
                            } else {
                                $activitiesOutput .= '<i>'.__('NA').'</i>';
                            }
                            $activitiesOutput .= '</td>';
                            $activitiesOutput .= '</tr>';
                        }
                        $activitiesOutput .= '</table>';
                    }
                }
            }
        }

        // TABS
        $tabs = [];

        if (!empty($plannerOutput) || !empty($gradesOutput) || !empty($deadlinesOutput)) {
            $tabs['Planner'] = [
                'label'   => __('Planner'),
                'content' => $plannerOutput.$gradesOutput.$deadlinesOutput,
                'icon'    => 'book-open',
            ];
        }

        if (!empty($timetableOutput)) {
            $tabs['Timetable'] = [
                'label'   => __('Timetable'),
                'content' => $timetableOutput,
                'icon'    => 'calendar',
            ];
        }
        
        if (!empty($activitiesOutput)) {
            $tabs['Activities'] = [
                'label' =>  __('Activities'),
                'content' => $activitiesOutput,
                'icon'    => 'star',
            ];
        }

        // Dashboard Hooks
        $hooks = $this->getContainer()->get(HookGateway::class)->getAccessibleHooksByType('Parental Dashboard', $this->session->get('gibbonRoleIDCurrent'));
        foreach ($hooks as $hookData) {

            // Set the module for this hook for translations
            $this->session->set('module', $hookData['sourceModuleName']);
            $include = $this->session->get('absolutePath').'/modules/'.$hookData['sourceModuleName'].'/'.$hookData['sourceModuleInclude'];

            if (!file_exists($include)) {
                $hookOutput = Format::alert(__('The selected page cannot be displayed due to a hook error.'), 'error');
            } else {
               try {
                    $hookOutput = include $include;
                } catch (\Throwable $e) {
                    error_log($e->getMessage());
                    $hookOutput = Format::alert(__('The selected page cannot be displayed due to a hook error.'), 'error');
                }
            }

            $tabs[$hookData['name']] = [
                'label'   => __($hookData['name'], [], $hookData['sourceModuleName']),
                'content' => $hookOutput,
                'icon'    => $hookData['name'],
            ];
        }

        // Set the default tab
        $parentDashboardDefaultTab = $this->settingGateway->getSettingByScope('School Admin', 'parentDashboardDefaultTab');
        $defaultTab = !isset($_GET['tab']) && !empty($parentDashboardDefaultTab)
            ? array_search($parentDashboardDefaultTab, array_keys($tabs))+1
            : preg_replace('/[^0-9]/', '', $_GET['tab'] ?? 1);

        $return .= $this->view->fetchFromTemplate('ui/tabs.twig.html', [
            'selected' => $defaultTab ?? 1,
            'tabs'     => $tabs,
            'outset'   => false,
            'icons'    => true,
        ]);

        return $return;
    }
}
