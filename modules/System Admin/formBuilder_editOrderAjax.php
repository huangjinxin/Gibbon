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

use Gibbon\Domain\Forms\FormPageGateway;

require_once '../../gibbon.php';

if (isActionAccessible($guid, $connection2, '/modules/System Admin/formBuilder_edit.php') == false) {
    exit;
} else {
    // Proceed!
    $gibbonFormID = $_POST['gibbonFormID'] ?? '';
    $order = $_POST['order'] ?? [];

    if (empty($order) || empty($gibbonFormID)) {
        exit;
    } else {
        $formPageGateway = $container->get(FormPageGateway::class);

        $count = 1;
        foreach ($order as $gibbonFormPageID) {

            $updated = $formPageGateway->update($gibbonFormPageID, ['sequenceNumber' => $count]);
            $count++;
        }
    }
}
