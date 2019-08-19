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

    // main function of this class that does the import
    public function importTodoComments($root, $branch, $author, $project, array $comments) {
        $project_row = $this->projectModel->getByName($project);
        if (!$project_row) { return false; }
        $project_id = $project_row['id'];

        ProjectAuthorization::getInstance($this->container)->check($this->getClassName(), 'importTodoComments', $project_id);

        $existingTasks = $this->createExistingTasksMap($project_id);
        $inputTasks = $this->createInputTaskMap($comments);
        $categoryToIDMap = $this->createCategoriesMap($project_id, $comments);
        
        $this->addNewTasks($existingTasks, $inputTasks, $project_id, $branch, $categoryToIDMap);
        $this->closeMissingTasks($existingTasks, $inputTasks, $project_id, $branch);

        return true;
    }
    
    private function addNewTasks($existingTasks, $inputTasks, $project_id, $branch, $categoryToIDMap) {
        // add new or update existing tasks that are present in $inputTasks
        foreach ($inputTasks as $hash => $c) {
            $task_id = null;
            // if the task existed before, we update some properties
            if (array_key_exists($hash, $existingTasks)) {
                $task_id = $existingTasks[$hash]['id'];
            }
            $this->addTodoComment($c, $project_id, $branch, $categoryToIDMap, $task_id);
        }
    }
    
    private function closeMissingTasks($existingTasks, $inputTasks, $project_id, $branch) {
        $last_column_id = $this->columnModel->getLastColumnId($project_id);
        
        // "close" removed tasks by moving to the last column
        // assume last column is some sort of 'done'
        foreach ($existingTasks as $hash => $t) {
            if (array_key_exists($hash, $inputTasks)) {
                // do nothing, task exists and was update above
            } else if ($this->canTaskBeClosed($t['id'], $branch)) {
                // task is missing in the input so probably was resolved
                $this->taskPositionModel->movePosition(
                    $t['project_id'],
                    $t['id'],
                    $last_column_id,
                    $t['position'],
                    $t['swimlane_id'],
                    false);
            }
        }
    }

    // task can be closed if it is missing on the same branch as was created
    private function canTaskBeClosed($task_id, $branch) {
        $tags = $this->taskTagModel->getTagsByTask($task_id);
        $branchTag = '@' . $branch;
        foreach ($tags as $tag) {
            if ($tag['name'] == $branchTag) {
                return true;
            }
        }
        return false;
    }

    // returns color name for comment type (FIXME/TODO/BUG/etc.)
    private function getColorIdForType($type) {
        if (array_key_exists($type, $this->typeToColor)) {
            $color = $this->typeToColor[$type];
            return $this->colorModel->find($color);
        }
        return '';
    }
    
    // creates a key-value map to be inserted or updated in the table for a single task
    private function createTaskProperties($comment, $project_id, $branch, $categoryToIDMap, $task_id=null) {
        $reference = $comment['file'] . ':' . $comment['line'];
        $values = array(
            'title' =>$comment['title'],
            'project_id' => $project_id,
            'description' => $comment['body'],
            'reference' => $reference,
        );

        $color_id = $this->getColorIdForType($comment['type']);
        if ($color_id) { $values['color_id'] = $color_id; }
        if ($task_id) { $values['id'] = $task_id; }

        $shouldCreate = empty($task_id);

        if ($shouldCreate) {
            $tags = array();
            if ($branch) { $tags[] = '@' . $branch; }
            if (array_key_exists('issue', $comment)) { $tags[] = '#' .$comment['issue']; }
            $values['tags'] = $tags;
        }

        $category = $comment['category'];
        // new categories should have been created beforehand
        if ($category && array_key_exists($category, $categoryToIDMap)) {
            $category_id = $categoryToIDMap[$category];
            $values['category_id'] = $category_id;
        }
        
        return $values;
    }

    // adds or updates comment/task
    private function addTodoComment($comment, $project_id, $branch, $categoryToIDMap, $task_id=null) {
        $values = $this->createTaskProperties($comment, $project_id, $branch, $categoryToIDMap, $task_id);
        list($valid, ) = $this->taskValidator->validateCreation($values);
        $shouldCreate = empty($task_id);

        if ($valid) {
            if ($shouldCreate) {
                $this->taskCreationModel->create($values);
            } else {
                $this->taskModificationModel->update($values);
            }
        }
    }

    // creates missing categories from new categories in comments
    // and returns map [category_name] -> category_id
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
