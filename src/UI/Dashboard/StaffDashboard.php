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
use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Domain\System\HookGateway;
use Gibbon\Forms\OutputableInterface;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Tables\Prefab\BehaviourTable;
use Gibbon\Tables\Prefab\EnrolmentTable;
use Gibbon\Tables\Prefab\FormGroupTable;
use Gibbon\Contracts\Database\Connection;
use League\Container\ContainerAwareTrait;
use Gibbon\Tables\Prefab\TodaysLessonsTable;
use League\Container\ContainerAwareInterface;

/**
 * Staff Dashboard View Composer
 *
 * @version  v18
 * @since    v18
 */
class StaffDashboard implements OutputableInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var \Gibbon\Contracts\Database\Connection
     */
    protected $db;

    /**
     * @var \Gibbon\Contracts\Services\Session
     */
    protected $session;

    /**
     * @var \Gibbon\Tables\Prefab\FormGroupTable
     */
    protected $formGroupTable;

    /**
     * @var \Gibbon\Tables\Prefab\EnrolmentTable
     */
    protected $enrolmentTable;

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * @var View
     */
    private $view;

    public function __construct(
        Connection $db,
        Session $session,
        FormGroupTable $formGroupTable,
        EnrolmentTable $enrolmentTable,
        SettingGateway $settingGateway,
        View $view
    ) {
        $this->db = $db;
        $this->session = $session;
        $this->formGroupTable = $formGroupTable;
        $this->enrolmentTable = $enrolmentTable;
        $this->settingGateway = $settingGateway;
        $this->view = $view;
    }

    public function getOutput()
    {
        $output = '<h2>'.
            __('Staff Dashboard').
            '</h2>'.
            "<div class='w-full' style='height:calc(100% - 6rem)'>";

        $dashboardContents = $this->renderDashboard();

        if ($dashboardContents == false) {
            $output .= "<div class='error'>".
                __('There are no records to display.').
                '</div>';
        } else {
            $output .= $dashboardContents;
        }
        $output .= '</div>';

        return $output;
    }

    protected function renderDashboard()
    {
        $guid = $this->session->get('guid');
        $connection2 = $this->db->getConnection();
        $gibbonPersonID = $this->session->get('gibbonPersonID');
        $session = $this->session;

        $return = false;

        $planner = false;

        // PLANNER
        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {
            $planner = $this
                ->getContainer()
                ->get(TodaysLessonsTable::class)
                ->create($session->get('gibbonSchoolYearID'), $this->session->get('gibbonPersonID'), 'Teacher')
                ->getOutput();
        }

        //GET TIMETABLE
        $timetable = false;
        if (
            isActionAccessible($guid, $connection2, '/modules/Timetable/tt.php') and $this->session->get('username') != ''
            && $this->session->get('gibbonRoleIDCurrentCategory') == 'Staff'
        ) {
            $_POST = (new Validator(''))->sanitize($_POST);
            $jsonQuery = [
                'gibbonTTID' => $_GET['gibbonTTID'] ?? '',
                'ttDate' => $_POST['ttDate'] ?? '',
            ];
            $apiEndpoint = (string)Url::fromHandlerRoute('index_tt_ajax.php')->withQueryParams($jsonQuery);

            $timetable .= '<h2>'.__('My Timetable').'</h2>';
            $timetable .= "<div hx-get='".$apiEndpoint."' hx-trigger='load' style='width: 100%; min-height: 40px; text-align: center'>";
            $timetable .= "<img style='margin: 10px 0 5px 0' src='".$this->session->get('absoluteURL')."/themes/Default/img/loading.gif' alt='".__('Loading')."' onclick='return false;' /><br/><p style='text-align: center'>".__('Loading').'</p>';
            $timetable .= '</div>';
        }

        //GET FORM GROUPS
        $formGroups = array();
        $formGroupCount = 0;
        $count = 0;

        $dataFormGroups = array('gibbonPersonIDTutor' => $this->session->get('gibbonPersonID'), 'gibbonPersonIDTutor2' => $this->session->get('gibbonPersonID'), 'gibbonPersonIDTutor3' => $this->session->get('gibbonPersonID'), 'gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID'));
        $sqlFormGroups = 'SELECT * FROM gibbonFormGroup WHERE (gibbonPersonIDTutor=:gibbonPersonIDTutor OR gibbonPersonIDTutor2=:gibbonPersonIDTutor2 OR gibbonPersonIDTutor3=:gibbonPersonIDTutor3) AND gibbonSchoolYearID=:gibbonSchoolYearID';
        $resultFormGroups = $this->db->select($sqlFormGroups, $dataFormGroups);

        $attendanceAccess = isActionAccessible($guid, $connection2, '/modules/Attendance/attendance_take_byFormGroup.php');

        while ($rowFormGroups = $resultFormGroups->fetch()) {
            $formGroups[$count][0] = $rowFormGroups['gibbonFormGroupID'];
            $formGroups[$count][1] = $rowFormGroups['nameShort'];

            //Form group table
            $formGroupTable = clone $this->formGroupTable;

            $formGroupTable->build($rowFormGroups['gibbonFormGroupID'], true, false, 'rollOrder, surname, preferredName');
            $formGroupTable->setTitle('');

            if ($rowFormGroups['attendance'] == 'Y' AND $attendanceAccess) {
                $formGroupTable->addHeaderAction('attendance', __('Take Attendance'))
                    ->setURL('/modules/Attendance/attendance_take_byFormGroup.php')
                    ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                    ->setIcon('attendance')
                    ->displayLabel();
            }

            $formGroupTable->addHeaderAction('export', __('Export to Excel'))
                ->setURL('/indexExport.php')
                ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                ->directLink()
                ->displayLabel();

            $formGroups[$count][2] = $formGroupTable->getOutput();

            // BEHAVIOUR
            $behaviourView = isActionAccessible($guid, $connection2, '/modules/Behaviour/behaviour_view.php');
            if ($behaviourView) {
                $table = $this->getContainer()->get(BehaviourTable::class)->create($this->session->get('gibbonSchoolYearID'), $formGroups[$count][0]);
                $formGroups[$count][3] = $table->getOutput();
            }

            ++$count;
            ++$formGroupCount;
        }

        // TABS
        $tabs = [];

        if (!empty($planner) || !empty($timetable)) {
            $tabs['Planner'] = [
                'label'   => __('Planner'),
                'content' => $planner.$timetable,
                'icon'    => 'book-open',
            ];
        }

        if (count($formGroups) > 0) {
            foreach ($formGroups as $index => $formGroup) {
                $tabs['Form Group Info'.$index] = [
                    'label'   => $formGroup[1],
                    'content' => $formGroup[2],
                    'icon'    => 'user-group',
                ];
                $tabs['Form Group Behaviour'.$index] = [
                    'label'   => $formGroup[1].' '.__('Behaviour'),
                    'content' => $formGroup[3],
                    'icon'    => 'chat-bubble-text',
                ];
            }
        }

        if (isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_left.php') || isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_new.php')) {
            $tabs['Enrolment'] = [
                'label'   => __('Enrolment'),
                'content' => $this->enrolmentTable->getOutput(),
                'icon'    => 'academic-cap',
            ];
        }

        // Dashboard Hooks
        $hooks = $this->getContainer()->get(HookGateway::class)->getAccessibleHooksByType('Staff Dashboard', $this->session->get('gibbonRoleIDCurrent'));
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
        $staffDashboardDefaultTab = $this->settingGateway->getSettingByScope('School Admin', 'staffDashboardDefaultTab');
        $defaultTab = !isset($_GET['tab']) && !empty($staffDashboardDefaultTab)
            ? array_search($staffDashboardDefaultTab, array_keys($tabs))+1
            : preg_replace('/[^0-9]/', '', $_GET['tab'] ?? 1);

        $return .= $this->view->fetchFromTemplate('ui/tabs.twig.html', [
            'selected' => $defaultTab ?? 1,
            'tabs'     => $tabs,
            'outset'   => true,
            'icons'    => true,
        ]);

        return $return;
    }
}
