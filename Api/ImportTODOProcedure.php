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
        $comments_count = count($comments);
        $this->logger->info("[TODO import] importing comments count=$comments_count project=$project");
        $project_row = $this->projectModel->getByName($project);
        if (!$project_row) {
            $this->logger->error("[TODO import] cannot find project name=$project");
            return false;
        }
        $project_id = $project_row['id'];

        ProjectAuthorization::getInstance($this->container)->check($this->getClassName(), 'importTodoComments', $project_id);

        $existingTasks = $this->createExistingTasksMap($project_id);
        $inputTasks = $this->createInputTaskMap($comments);
        $categoryToIDMap = $this->createCategoriesMap($project_id, $comments);
        
        $this->addNewTasks($existingTasks, $inputTasks, $project_id, $branch, $categoryToIDMap);
        $this->closeMissingTasks($existingTasks, $inputTasks, $project_id, $branch);
        
        $this->logger->info("[TODO import] import finished");
        return true;
    }
    
    // add new or update existing tasks that are present in $inputTasks
    private function addNewTasks($existingTasks, $inputTasks, $project_id, $branch, $categoryToIDMap) {
        $added_count = 0;
        $updated_count = 0;
        foreach ($inputTasks as $hash => $c) {
            if (array_key_exists($hash, $existingTasks)) {
                // if the task existed before, we update some properties that might have changed
                $task_id = $existingTasks[$hash]['id'];
                $this->updateToDoComment($c, $project_id, $branch, $categoryToIDMap, $task_id);
                $updated_count++;
            } else {
                $this->addTodoComment($c, $project_id, $branch, $categoryToIDMap);
                $added_count++;
            }
        }
        $this->logger->info("[TODO import] added new tasks count=$added_count");
        $this->logger->info("[TODO import] updated existing tasks count=$updated_count");
    }
    
    // moves a task to the last column ('done') in case task is missing from the input tasks
    // and task current branch is the same branch where task was initially created
    private function closeMissingTasks($existingTasks, $inputTasks, $project_id, $branch) {
        $last_column_id = $this->columnModel->getLastColumnId($project_id);
        $this->logger->debug("[TODO import] last column id=$last_column_id");
        
        $closed_count = 0;
        // "close" removed tasks by moving to the last column
        // assume last column is some sort of 'done'
        foreach ($existingTasks as $hash => $t) {
            $task_id = $t['id'];
            if (array_key_exists($hash, $inputTasks)) {
                // do nothing, task exists and was update above
                continue;
            }

            if ($t['column_id'] == $last_column_id) {
                $this->logger->debug("[TODO import] task is already in the last column id=$task_id");
                continue;
            }

            if ($this->canTaskBeClosed($t['id'], $branch)) {
                $task_title = $t['title'];
                $this->logger->debug("[TODO import] closing task id=$task_id title=$task_title");
                // task is missing in the input so probably was resolved
                $this->taskPositionModel->movePosition(
                    $t['project_id'],
                    $t['id'],
                    $last_column_id,
                    $t['position'],
                    $t['swimlane_id'],
                    false);
                $closed_count++;
            } else {
                $this->logger->debug("[TODO import] task cannot be closed id=$task_id");
            }
        }
        $this->logger->info("[TODO import] closed missing tasks count=$closed_count");
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
        } else {
            $this->logger->warning("[TODO import] cannot find color for task type=$type");
        }
        return '';
    }
    
    // creates a key-value map to be inserted or updated in the table for a single task
    private function createTaskProperties($comment, $project_id, $branch, $categoryToIDMap) {
        $reference = $comment['file'] . ':' . $comment['line'];
        $values = array(
            'title' => $comment['title'],
            'project_id' => $project_id,
            'description' => $comment['body'],
            'reference' => $reference,
        );

        $color_id = $this->getColorIdForType($comment['type']);
        if ($color_id) { $values['color_id'] = $color_id; }

        $tags = array();
        if ($branch) { $tags[] = '@' . $branch; }
        if (array_key_exists('issue', $comment)) { $tags[] = '#' . $comment['issue']; }
        $values['tags'] = $tags;

        $category = $comment['category'];
        // new categories should have been created beforehand
        if ($category && array_key_exists($category, $categoryToIDMap)) {
            $category_id = $categoryToIDMap[$category];
            $values['category_id'] = $category_id;
        }
        
        return $values;
    }
    
    // updates task
    private function updateToDoComment($comment, $project_id, $branch, $categoryToIDMap, $task_id) {
        $values = $this->createTaskProperties($comment, $project_id, $branch, $categoryToIDMap);
        if ($task_id) { $values['id'] = $task_id; }
        list($valid, ) = $this->taskValidator->validateCreation($values);
        $task_title = $values['title'];

        if ($valid) {
            $this->logger->debug("[TODO import] updating task with id=$task_id title=$task_title");
            $this->taskModificationModel->update($values);
        } else {
            $this->logger->error("[TODO import] validation failure for task with title=$task_title");
        }
    }

    // adds comment/task
    private function addTodoComment($comment, $project_id, $branch, $categoryToIDMap) {
        $values = $this->createTaskProperties($comment, $project_id, $branch, $categoryToIDMap);
        list($valid, ) = $this->taskValidator->validateCreation($values);
        $task_title = $values['title'];

        if ($valid) {
            $this->logger->debug("[TODO import] creating task with title=$task_title");
            $this->taskCreationModel->create($values);
        } else {
            $this->logger->error("[TODO import] validation failure for task with title=$task_title");
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
        $categories_count = count($existing_categories);
        $this->logger->debug("[TODO import] fetched existing categories count=$categories_count");
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
                $this->logger->debug("[TODO import] created new category name=$category id=$id");
                if ($id) {
                    $categoryToIDMap[$category] = $id;
                }
            }
        }

        return $categoryToIDMap;
    }
    
    private function taskHash($title, $description) {
        return md5($title . $description);
    }

    // creates mapping hash->task from existing tasks
    private function createExistingTasksMap($project_id) {
        $taskMap = array();
        $tasks = $this->taskFinderModel->getAll($project_id);
        $tasks_count = count($tasks);
        $this->logger->debug("[TODO import] processing existing tasks_count=$tasks_count");
        foreach ($tasks as $t) {
            $hash = $this->taskHash($t['title'], $t['description']);
            $taskMap[$hash] = $t;
        }
        return $taskMap;
    }

    // creates mapping hash->comment from input tasks
    private function createInputTaskMap($comments) {
        $taskMap = array();
        $comments_count = count($comments);
        $this->logger->debug("[TODO import] processing input comments_count=$comments_count");
        foreach ($comments as $c) {
            $hash = $this->taskHash($c['title'], $c['body']);
            $taskMap[$hash] = $c;
        }
        return $taskMap;    
    }
}
