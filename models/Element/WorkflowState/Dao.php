<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Model\Element\WorkflowState;

use Pimcore\Db\Helper;
use Pimcore\Model;

/**
 * @internal
 *
 * @property \Pimcore\Model\Element\WorkflowState $model
 */
class Dao extends Model\Dao\AbstractDao
{
    /**
     * @param int $cid
     * @param string $ctype
     * @param string $workflow
     *
     * @throws Model\Exception\NotFoundException
     */
    public function getByPrimary(int $cid, string $ctype, string $workflow)
    {
        $data = $this->db->fetchAssociative('SELECT * FROM element_workflow_state WHERE cid = ? AND ctype = ? AND workflow = ?', [$cid, $ctype, $workflow]);

        if (empty($data['cid'])) {
            throw new Model\Exception\NotFoundException('WorkflowStatus item for workflow ' . $workflow . ' with cid ' . $cid . ' and ctype ' . $ctype . ' not found');
        }
        $this->assignVariablesToModel($data);
    }

    /**
     * Save object to database
     *
     * @return bool
     *
     * @todo: not all save methods return a boolean, why this one?
     */
    public function save(): bool
    {
        $dataAttributes = $this->model->getObjectVars();

        $data = [];
        foreach ($dataAttributes as $key => $value) {
            if (in_array($key, $this->getValidTableColumns('element_workflow_state'))) {
                $data[$key] = $value;
            }
        }

        Helper::insertOrUpdate($this->db, 'element_workflow_state', $data);

        return true;
    }

    /**
     * Deletes object from database
     */
    public function delete()
    {
        $this->db->delete('element_workflow_state', [
            'cid' => $this->model->getCid(),
            'ctype' => $this->model->getCtype(),
        ]);
    }
}
