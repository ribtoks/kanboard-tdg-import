<?php

namespace Kanboard\Plugin\TdgImport\Api;

use Kanboard\Api\Authorization\ProjectAuthorization;
use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Model\CategoryModel;
use Kanboard\Core\Base;

/**
 * ImportTODO API controller
 *
 * @author   Taras Kushnir
 */
class ImportTODOProcedure extends BaseProcedure
{
    protected $typeToColor = array(
        'TODO' => 'green',
        'FIXME' => 'yellow',
        'BUG' => 'red',
        'HACK' => 'amber',
    );

    public function importTodoComments($root, $branch, $author, $project, array $comments) {
        $project_row = $this->projectModel->getByName($project);
        if (!$project_row) { return false; }
        $project_id = $project_row['id'];

        ProjectAuthorization::getInstance($this->container)->check($this->getClassName(), 'importTodoComments', $project_id);

        $categoryToIDMap = $this->createCategoriesMap($project_id, $comments);
        $existingTasks = $this->createExistingTasksMap($project_id);
        $inputTasks = $this->createInputTaskMap($comments);
        $last_column_id = $this->columnModel->getLastColumnId($project_id);

        // add new or update existing tasks
        foreach ($inputTasks as $hash => $c) {
            $task_id = null;
            if (array_key_exists($hash, $existingTasks)) {
                $task_id = $existingTasks[$hash]['id'];
            }
            $this->addTodoComment($c, $project_id, $branch, $categoryToIDMap, $task_id);
        }

        // close removed tasks
        foreach ($existingTasks as $hash => $t) {
            if (array_key_exists($hash, $inputTasks)) {
                // do nothing, task exists and was update above
            } else {
                // assume last column is some sort of 'done'
                /*$this->taskModificationModel->update(array(
                    'id' => $t['id'],
                    'column_id' => $last_column_id
                ));*/
                $this->taskPositionModel->movePosition(
                    $t['project_id'],
                    $t['id'],
                    $last_column_id,
                    $t['position'],
                    $t['swimlane_id'],
                    false);
            }
        }
        return true;
    }

    private function getColorIdForType($type) {
        if (array_key_exists($type, $this->typeToColor)) {
            $color = $this->typeToColor[$type];
            return $this->colorModel->find($color);
        }
        return '';
    }

    private function addTodoComment($c, $project_id, $branch, $categoryToIDMap, $task_id=null) {
        $reference = $c['file'] . ":" . $c['line'];

        $values = array(
            'title' => $c['title'],
            'project_id' => $project_id,
            'description' => $c['body'],
            'reference' => $reference,
        );

        $color_id = $this->getColorIdForType($c['type']);
        if ($color_id) {
            $values['color_id'] = $color_id;
        }

        if ($task_id) {
            $values['id'] = $task_id;
        }

        $shouldCreate = empty($task_id);

        if ($branch && $shouldCreate) {
            $tags = array("@" . $branch);
            $values['tags'] = $tags;
        }

        $category = $c['category'];
        if ($category && array_key_exists($category, $categoryToIDMap)) {
            $category_id = $categoryToIDMap[$category];
            $values['category_id'] = $category_id;
        }

        list($valid, ) = $this->taskValidator->validateCreation($values);

        if ($valid) {
            if ($shouldCreate) {
                $this->taskCreationModel->create($values);
            } else {
                $this->taskModificationModel->update($values);
            }
        }
    }

    private function createCategoriesMap($project_id, $comments) {
        $categories = array();
        foreach ($comments as $c) {
            if (array_key_exists('category', $c)) { 
                $categories[] = $c['category']; 
            }
        }

        $existing_categories = $this->categoryModel->getList($project_id, false /*prepend none*/, false /*prepend all*/);
        $categoryToIDMap = array();
        foreach ($existing_categories as $key => $value) {
            $categoryToIDMap[$value] = $key;
        }

        foreach ($categories as $category) {
            if (!array_key_exists($category, $categoryToIDMap)) {
                $id = $this->categoryModel->create(
                    array(
                        'project_id' => $project_id,
                        'name' => $category,
                    ));
                if ($id) {
                    $categoryToIDMap[$category] = $id;
                }
            }
        }

        return $categoryToIDMap;
    }

    private function createExistingTasksMap($project_id) {
        $taskMap = array();
        $tasks = $this->taskFinderModel->getAll($project_id);
        foreach ($tasks as $t) {
            $hash = md5($t['title'] . $t['description']);
            $taskMap[$hash] = $t;
        }
        return $taskMap;
    }

    private function createInputTaskMap($comments) {
        $taskMap = array();
        foreach ($comments as $c) {
            $hash = md5($c['title'] . $c['body']);
            $taskMap[$hash] = $c;
        }
        return $taskMap;    
    }
}